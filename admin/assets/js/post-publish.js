/*
 * Post editor: the Publish panel (1.2.60; shared component since 1.2.62).
 *
 * The control itself — Now / Schedule / Set date for a post that is not live,
 * Keep date / Change date for a live one, in the SITE timezone — is
 * BasehimPostFields.publish() from post-fields.js, the same one Quick Edit
 * uses. It posts publish_mode, publish_date and publish_time with the form;
 * the server (PostPublishing) checks them again and decides.
 *
 * This file only connects it to the editor: the header's primary button says
 * what it will do (Publish, Schedule, Publish now, Update, Submit for review)
 * and a click is checked, and confirmed when it takes a live post off the
 * site, before the form is sent.
 */
(function () {
  'use strict';

  var F = window.BasehimPostFields;
  var mount = document.querySelector('[data-bh-publish-mount]');
  var form = document.getElementById('bh-post-form');
  var button = document.getElementById('bhe-publish');
  if (!F || !mount || !form || !button) return;

  var cfg;
  try { cfg = JSON.parse(mount.getAttribute('data-config') || '{}'); } catch (e) { return; }
  var stored = cfg.status || 'draft';
  var canPublish = !!cfg.can_publish;
  var isOut = stored === 'published' || stored === 'scheduled' || stored === 'private';
  var statusSel = form.querySelector('[name="status"]');
  var confirmed = false;

  /** The status the save will ask for, before the server's checks. */
  function targetStatus() {
    if (!isOut) return canPublish ? 'published' : 'pending';
    return statusSel ? statusSel.value : stored;
  }

  var ctl = F.publish(mount, cfg, { targetStatus: targetStatus, idPrefix: 'bh-publish', skin: 'editor' });

  function syncButton() {
    var l = ctl.label();
    if (!button.hasAttribute('aria-busy')) button.textContent = l;
    var t = targetStatus();
    if (!isOut) {
      button.setAttribute('data-status', canPublish ? 'published' : 'pending');
    } else if (canPublish && stored === 'scheduled' && (t === 'scheduled' || t === 'published')) {
      // The select shows "Scheduled"; Publish now must ask for published.
      if (ctl.mode() === 'now') button.setAttribute('data-status', 'published');
      else button.removeAttribute('data-status');
    } else {
      button.removeAttribute('data-status');
    }
    button.title = l === 'Schedule' ? 'Schedule this ' + (cfg.type_label || 'post') + ' for the date and time chosen in the Publish panel'
                 : l === 'Publish now' ? 'Publish now instead of at the scheduled time' : '';
  }

  ctl.onChange(syncButton);
  if (statusSel) statusSel.addEventListener('change', function () { ctl.render(); syncButton(); });
  document.addEventListener('bh:post-saved', function () { ctl.render(); syncButton(); });
  // "in 5 minutes" and "is it still ahead" age with the clock.
  setInterval(function () { ctl.render(); syncButton(); }, 30000);

  button.addEventListener('click', function (e) {
    var err = ctl.check();
    if (err) {
      e.preventDefault(); e.stopImmediatePropagation();
      if (window.bhToast) window.bhToast(err, 'error');
      return;
    }
    var msg = ctl.confirmMessage();
    if (msg && !confirmed) {
      e.preventDefault(); e.stopImmediatePropagation();
      var ask = window.bhConfirm ? window.bhConfirm(msg, { confirmLabel: 'Schedule', cancelLabel: 'Keep it live' }) : Promise.resolve(window.confirm(msg));
      ask.then(function (ok) {
        if (!ok) return;
        confirmed = true;
        button.click();          // runs block-editor.js's handler, then submits
        confirmed = false;
      });
    }
  }, true);   // capture: before block-editor.js sets the status field

  syncButton();
})();
