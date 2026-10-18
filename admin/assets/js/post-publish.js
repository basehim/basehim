/*
 * Basehim — the post editor's Publish panel (1.2.60).
 *
 * Publish immediately or schedule for a date and time in the SITE timezone
 * (Settings → General), never the browser's. The panel only collects the
 * choice; the server (PostPublishing) validates it again and decides, so
 * nothing here is a security boundary.
 *
 * Fields it posts with the form: publish_mode (now|schedule), publish_date
 * (Y-m-d) and publish_time (H:i), both site-local.
 *
 * It also keeps the editor's primary button honest about what it will do:
 * Publish, Schedule, Publish now, Update or Submit for review.
 */
(function () {
  'use strict';

  var panel = document.querySelector('[data-bh-publish]');
  var form = document.getElementById('bh-post-form');
  var button = document.getElementById('bhe-publish');
  if (!panel || !form || !button) return;

  var tz = panel.getAttribute('data-tz') || 'UTC';
  var stored = panel.getAttribute('data-status') || 'draft';       // status as saved
  var canPublish = panel.getAttribute('data-can-publish') === '1';
  var typeLabel = panel.getAttribute('data-type-label') || 'post';
  // The server's clock, so a browser whose clock is off does not decide
  // what "in the future" means.
  var skew = (parseInt(panel.getAttribute('data-now'), 10) || 0) * 1000 - Date.now();
  function serverNow() { return Date.now() + skew; }

  var statusSel = form.querySelector('[name="status"]');
  var radios = panel.querySelectorAll('input[name="publish_mode"]');
  var when = panel.querySelector('[data-bh-publish-when]');
  var dateIn = panel.querySelector('input[name="publish_date"]');
  var timeIn = panel.querySelector('input[name="publish_time"]');
  var preview = panel.querySelector('[data-bh-publish-preview]');
  var errorEl = panel.querySelector('[data-bh-publish-error]');
  var modes = panel.querySelector('[data-bh-publish-modes]');
  var isOut = stored === 'published' || stored === 'scheduled' || stored === 'private';
  var MAX = Date.UTC(2038, 0, 19, 3, 14, 7);
  var confirmed = false;

  // -- Time zone arithmetic (Intl, no library) --------------------------------

  var partsFmt;
  try {
    partsFmt = new Intl.DateTimeFormat('en-US', {
      timeZone: tz, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit',
      hour: '2-digit', minute: '2-digit', second: '2-digit'
    });
  } catch (e) { partsFmt = null; }

  /** Wall-clock parts of an instant in the site timezone. */
  function wall(ms) {
    if (!partsFmt) { var d = new Date(ms); return { y: d.getUTCFullYear(), m: d.getUTCMonth() + 1, d: d.getUTCDate(), h: d.getUTCHours(), mi: d.getUTCMinutes(), s: d.getUTCSeconds() }; }
    var o = {};
    partsFmt.formatToParts(new Date(ms)).forEach(function (p) { o[p.type] = parseInt(p.value, 10); });
    return { y: o.year, m: o.month, d: o.day, h: o.hour === 24 ? 0 : o.hour, mi: o.minute, s: o.second };
  }
  /** The site timezone's offset from UTC at an instant, in ms. */
  function offsetAt(ms) {
    var w = wall(ms);
    return Date.UTC(w.y, w.m - 1, w.d, w.h, w.mi, w.s) - Math.floor(ms / 1000) * 1000;
  }
  /**
   * "2026-10-20" + "09:30" in the site timezone → instant (ms), or NaN. A wall
   * time skipped by daylight saving moves forward by the gap, as PHP does.
   */
  function toInstant(date, time) {
    var dm = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date || '');
    var tm = /^(\d{2}):(\d{2})/.exec(time || '');
    if (!dm || !tm) return NaN;
    var y = +dm[1], mo = +dm[2], d = +dm[3], h = +tm[1], mi = +tm[2];
    var probe = new Date(Date.UTC(y, mo - 1, d));
    if (probe.getUTCMonth() !== mo - 1 || probe.getUTCDate() !== d || h > 23 || mi > 59) return NaN;
    var guess = Date.UTC(y, mo - 1, d, h, mi);
    var t = guess - offsetAt(guess);
    var o2 = offsetAt(t);
    if (guess - o2 !== t) t = guess - o2;
    // In a spring-forward gap neither guess lands on the wall time: take the
    // later offset, which pushes the time forward by the gap.
    var w = wall(t);
    if (w.h !== h || w.mi !== mi) t = guess - Math.min(offsetAt(guess - 864e5), offsetAt(guess + 864e5));
    return t;
  }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function localDate(ms) { var w = wall(ms); return w.y + '-' + pad(w.m) + '-' + pad(w.d); }
  function localTime(ms) { var w = wall(ms); return pad(w.h) + ':' + pad(w.mi); }

  function describe(ms) {
    try {
      return new Intl.DateTimeFormat(undefined, {
        timeZone: tz, weekday: 'short', year: 'numeric', month: 'short', day: 'numeric',
        hour: 'numeric', minute: '2-digit', timeZoneName: 'short'
      }).format(new Date(ms));
    } catch (e) { return localDate(ms) + ' ' + localTime(ms) + ' (' + tz + ')'; }
  }
  function relative(ms) {
    var diff = ms - serverNow(), min = Math.round(diff / 60000);
    var unit = 'minute', n = min;
    if (Math.abs(min) >= 2880) { unit = 'day'; n = Math.round(min / 1440); }
    else if (Math.abs(min) >= 120) { unit = 'hour'; n = Math.round(min / 60); }
    try { return new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' }).format(n, unit); }
    catch (e) { return ''; }
  }

  // -- State --------------------------------------------------------------------

  function mode() {
    for (var i = 0; i < radios.length; i++) if (radios[i].checked) return radios[i].value;
    return 'now';
  }
  /** The status the save will ask for, before the server's checks. */
  function targetStatus() {
    if (!isOut) return canPublish ? 'published' : 'pending';
    return statusSel ? statusSel.value : stored;
  }
  /** Whether timing applies to this save at all (not when going to draft/private). */
  function timingApplies() {
    var t = targetStatus();
    return canPublish && (t === 'published' || t === 'scheduled');
  }
  function chosen() { return toInstant(dateIn && dateIn.value, timeIn && timeIn.value); }

  function validate() {
    if (!timingApplies() || mode() !== 'schedule') return null;
    if (!dateIn.value || !timeIn.value) return 'Choose a date and a time.';
    var t = chosen();
    if (isNaN(t)) return 'Choose a valid date and time.';
    if (t <= serverNow()) return 'Pick a time in the future, or choose Immediately.';
    if (t > MAX) return 'Choose a date before 19 January 2038.';
    return null;
  }

  function label() {
    var t = targetStatus(), m = mode();
    if (!canPublish) return isOut ? 'Update' : 'Submit for review';
    if (!isOut) return m === 'schedule' ? 'Schedule' : 'Publish';
    if (t !== 'published' && t !== 'scheduled') return 'Update';
    if (stored === 'scheduled') return m === 'schedule' ? 'Update' : 'Publish now';
    if (stored === 'published') return m === 'schedule' ? 'Schedule' : 'Update';
    return 'Update';   // private → published goes out now
  }

  function syncButton() {
    var l = label();
    // While a save runs the loader owns the label.
    if (!button.hasAttribute('aria-busy')) button.textContent = l;
    // What the click writes into the status field (block-editor.js reads it).
    var t = targetStatus();
    if (!isOut) {
      button.setAttribute('data-status', canPublish ? 'published' : 'pending');
    } else if (canPublish && stored === 'scheduled' && (t === 'scheduled' || t === 'published')) {
      // The select shows "Scheduled"; Publish now must ask for published.
      if (mode() === 'now') button.setAttribute('data-status', 'published');
      else button.removeAttribute('data-status');
    } else {
      button.removeAttribute('data-status');
    }
    button.title = l === 'Schedule' ? 'Schedule this ' + typeLabel + ' for the date and time chosen in the Publish panel'
                 : l === 'Publish now' ? 'Publish now instead of at the scheduled time'
                 : '';
  }

  function showError(msg) {
    if (!errorEl) return;
    errorEl.textContent = msg || '';
    errorEl.hidden = !msg;
    [dateIn, timeIn].forEach(function (el) { if (el) { if (msg) el.setAttribute('aria-invalid', 'true'); else el.removeAttribute('aria-invalid'); } });
  }

  function render(opts) {
    opts = opts || {};
    var applies = timingApplies();
    if (modes) modes.hidden = !applies;
    var sched = applies && mode() === 'schedule';
    if (when) when.hidden = !sched;

    // A first switch to Schedule suggests the next whole hour, an hour ahead.
    if (sched && dateIn && timeIn && !dateIn.value && !timeIn.value) {
      var next = serverNow() + 3600000;
      var w = wall(next);
      next = toInstant(localDate(next), pad(w.h) + ':00') + (w.mi > 0 ? 3600000 : 0);
      dateIn.value = localDate(next);
      timeIn.value = localTime(next);
    }

    var text = '';
    if (applies && preview) {
      if (sched) {
        var t = chosen();
        if (!isNaN(t)) {
          text = (t > serverNow() ? 'Goes live ' : 'Was due ') + describe(t);
          var rel = relative(t);
          if (rel) text += ' — ' + rel;
          text += '.';
          if (stored === 'published') text += ' Until then it is taken off the site.';
        }
      } else if (!isOut || stored === 'scheduled') {
        text = 'Goes live as soon as you press ' + label() + '.';
      }
    }
    if (preview) { preview.textContent = text; preview.hidden = text === ''; }

    // Errors appear once there is something to correct, and clear as it is.
    var err = validate();
    var showing = opts.showErrors || (errorEl && !errorEl.hidden);
    showError(showing ? err : null);
    syncButton();
  }

  // -- Wiring -------------------------------------------------------------------

  Array.prototype.forEach.call(radios, function (r) { r.addEventListener('change', function () { showError(null); render(); }); });
  [dateIn, timeIn].forEach(function (el) {
    if (!el) return;
    el.addEventListener('input', function () { render(); });
    el.addEventListener('change', function () { render(); });
  });
  if (statusSel) statusSel.addEventListener('change', function () { render(); });
  // A save over AJAX (Save draft) may have changed the status field.
  document.addEventListener('bh:post-saved', function () { render(); });
  // The relative time ("in 5 minutes") and "is it still ahead" age with the clock.
  setInterval(function () { if (!when || !when.hidden) render(); }, 30000);

  button.addEventListener('click', function (e) {
    var err = validate();
    if (err) {
      e.preventDefault();
      e.stopImmediatePropagation();
      render({ showErrors: true });
      var bad = (!dateIn.value || isNaN(chosen())) ? dateIn : timeIn;
      if (bad) bad.focus();
      if (window.bhToast) window.bhToast(err, 'error');
      return;
    }
    // A live post given a future date goes off the site until then: say so.
    if (canPublish && stored === 'published' && mode() === 'schedule' && timingApplies() && !confirmed) {
      e.preventDefault();
      e.stopImmediatePropagation();
      var msg = 'This ' + typeLabel + ' is live. Scheduling it takes it off the site until ' + describe(chosen()) + '.';
      var ask = window.bhConfirm
        ? window.bhConfirm(msg, { confirmLabel: 'Schedule', cancelLabel: 'Keep it live' })
        : Promise.resolve(window.confirm(msg));
      ask.then(function (ok) {
        if (!ok) return;
        confirmed = true;
        button.click();          // runs block-editor.js's handler, then submits
        confirmed = false;
      });
    }
  }, true);   // capture: before block-editor.js sets the status field

  render();
})();
