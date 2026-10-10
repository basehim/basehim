/*
 * Site-timezone date formatting for admin scripts (1.2.45).
 *
 * The server stores every time in UTC ("2026-10-10 08:32:00"). Scripts that
 * show one should use these, so the admin shows the site's timezone
 * (Settings → General) rather than the browser's, the same as PHP's bh_date():
 *
 *   BasehimTime.format('2026-10-10 08:32:00')                  "Oct 10, 2026, 1:32 PM"
 *   BasehimTime.date('2026-10-10 08:32:00')                    "Oct 10, 2026"
 *   BasehimTime.format(value, { hour: '2-digit', minute: '2-digit' })
 *   BasehimTime.ago('2026-10-10 08:32:00')                     "5 minutes ago"
 *   BasehimTime.timezone                                       "Asia/Karachi"
 *
 * The settings come from window.BASEHIM_TIME, printed by the admin layout.
 */
(function (w) {
  var cfg = w.BASEHIM_TIME || {};
  var tz = cfg.timezone || 'UTC';

  // "Y-m-d H:i:s" from the server is UTC; anything with Z or an offset keeps it.
  function parse(v) {
    if (v === null || v === undefined || v === '') return null;
    if (v instanceof Date) return isNaN(v) ? null : v;
    if (typeof v === 'number') return new Date(v < 1e12 ? v * 1000 : v);
    var s = String(v).trim();
    if (/^\d{4}-\d{2}-\d{2}$/.test(s)) s += 'T00:00:00Z';
    else if (/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/.test(s)) s = s.replace(' ', 'T') + 'Z';
    var d = new Date(s);
    return isNaN(d) ? null : d;
  }
  function fmt(v, opts, fallback) {
    var d = parse(v);
    if (!d) return fallback !== undefined ? fallback : (v ? String(v) : '');
    var o = Object.assign({ timeZone: tz }, opts || {});
    try { return new Intl.DateTimeFormat(undefined, o).format(d); }
    catch (e) { o.timeZone = 'UTC'; return new Intl.DateTimeFormat(undefined, o).format(d); }
  }

  w.BasehimTime = {
    timezone: tz,
    offset: cfg.offset || '+00:00',
    parse: parse,
    format: function (v, opts) { return fmt(v, opts || { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }); },
    date: function (v, opts) { return fmt(v, opts || { year: 'numeric', month: 'short', day: 'numeric' }); },
    time: function (v, opts) { return fmt(v, opts || { hour: 'numeric', minute: '2-digit' }); },
    ago: function (v) {
      var d = parse(v); if (!d) return '';
      var s = Math.round((Date.now() - d.getTime()) / 1000), a = Math.abs(s), fut = s < 0;
      if (a < 45) return fut ? 'in a moment' : 'just now';
      if (a >= 7 * 86400) return this.date(d);
      var units = [[86400, 'day'], [3600, 'hour'], [60, 'minute']];
      for (var i = 0; i < units.length; i++) {
        if (a >= units[i][0]) { var n = Math.round(a / units[i][0]); var t = n + ' ' + units[i][1] + (n === 1 ? '' : 's'); return fut ? 'in ' + t : t + ' ago'; }
      }
      return 'just now';
    },
    iso: function (v) { var d = parse(v); return d ? d.toISOString() : ''; }
  };
})(window);
