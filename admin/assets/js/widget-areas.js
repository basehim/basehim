/*
 * Appearance → Widgets → Widget areas (1.2.93).
 *
 * Draws the areas, their widgets and each widget's settings form from the
 * widgets.* AJAX actions (app/Http/Controllers/Ajax/WidgetActions.php). Every
 * change is saved at once; the server answers with the whole new state and
 * the screen is drawn again from it, so it never drifts from what is saved.
 *
 * Moving a widget: drag it by its handle (to another place or another area),
 * or focus the handle and press Arrow Up / Arrow Down; "Move to" in its
 * settings moves it to another area. All data is written with textContent or
 * escaped, never as HTML, except the preview, which goes into a sandboxed
 * iframe with scripts off.
 */
(function () {
  'use strict';

  var app = document.getElementById('bhw-app');
  if (!app || !window.Basehim || !Basehim.ajax) return;
  var ICONS = window.BH_WIDGET_ICONS || {};
  var picker = document.getElementById('bhw-picker');
  var pickerList = document.getElementById('bhw-picker-list');
  var pickerSearch = document.getElementById('bhw-picker-search');
  var statusEl = document.getElementById('bhw-status');

  var state = null;
  var open = {};        // instance id -> true while its settings are open
  var drafts = {};      // instance id -> unsaved form values
  var errors = {};      // instance id -> {field: message}
  var pickerArea = null;
  var dragId = null;

  // ── helpers ──────────────────────────────────────────────────────────────
  function el(tag, attrs, kids) {
    var n = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      var v = attrs[k];
      if (v === null || v === undefined || v === false) return;
      if (k === 'text') n.textContent = v;
      else if (k === 'class') n.className = v;
      else if (k.slice(0, 2) === 'on') n.addEventListener(k.slice(2), v);
      else n.setAttribute(k, v === true ? '' : String(v));
    });
    (kids || []).forEach(function (c) { if (c) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return n;
  }
  function icon(name) {
    var s = el('span', { class: 'bhw-row__icon', 'aria-hidden': 'true' });
    s.innerHTML = ICONS[name] || ICONS['puzzle-piece'] || ''; // trusted: rendered by the server's icon set
    return s;
  }
  function btn(label, iconName, cls, onclick, extra) {
    var b = el('button', Object.assign({ type: 'button', class: 'bh-btn ' + cls, onclick: onclick }, extra || {}));
    if (iconName) { b.appendChild(icon(iconName)); b.querySelector('span').className = ''; }
    if (cls.indexOf('--icon') >= 0) b.setAttribute('aria-label', label); else b.appendChild(document.createTextNode(label));
    return b;
  }
  function say(msg) { statusEl.textContent = ''; setTimeout(function () { statusEl.textContent = msg; }, 30); }
  function toast(msg, type) { if (window.bhToast) bhToast(msg, type || 'success'); }
  function fail(err, fallback) { toast((err && err.message) || fallback, 'error'); }
  function areaOf(id) {
    for (var i = 0; i < state.areas.length; i++) {
      var a = state.areas[i];
      for (var j = 0; j < a.instances.length; j++) if (a.instances[j].id === id) return { area: a, index: j, inst: a.instances[j] };
    }
    return null;
  }

  function call(action, data, button, busyLabel) {
    var l = button && window.bhLoader ? bhLoader(button, busyLabel || 'Saving…') : null;
    return Basehim.ajax.request('widgets.' + action, data || {}, { method: action === 'state' ? 'GET' : 'POST' })
      .then(function (res) {
        if (l) l.done ? l.done('Saved') : l.remove();
        if (res && res.areas) { state = res; render(); }
        return res;
      }, function (err) { if (l) (l.fail ? l.fail('Not saved') : l.remove()); throw err; });
  }

  // ── drawing ──────────────────────────────────────────────────────────────
  function render() {
    var focusId = document.activeElement && document.activeElement.getAttribute('data-focus');
    app.textContent = '';
    if (!state.areas.length) {
      app.appendChild(el('div', { class: 'bh-empty' }, [
        el('p', { class: 'bh-empty__title', text: 'Your theme has no widget areas' }),
        el('p', { class: 'bh-empty__text', text: 'A theme lists its areas under "widget_areas" in theme.json; an app registers them with WidgetAreaRegistry. Switch to a theme that has some to place widgets.' })
      ]));
      return;
    }
    var grid = el('div', { class: 'bhw-grid' });
    state.areas.forEach(function (a) { grid.appendChild(renderArea(a)); });
    app.appendChild(grid);
    if (focusId) {
      var f = app.querySelector('[data-focus="' + CSS.escape(focusId) + '"]');
      if (f) f.focus();
    }
  }

  function renderArea(a) {
    var count = a.instances.length;
    var head = el('div', { class: 'bh-card__head' }, [
      el('div', null, [
        el('h2', { class: 'bh-card__title' }, [a.name, el('span', { class: 'bhw-key', text: a.key })]),
        a.description ? el('p', { class: 'bh-card__sub', text: a.description }) : null,
        !a.active ? el('div', { class: 'bhw-row__badges' }, [el('span', { class: 'bh-badge bh-badge--amber', text: 'Not in the active theme' })]) : null
      ]),
      el('span', { class: 'bhw-count', text: count + (a.max ? ' of ' + a.max : '') + ' widget' + (count === 1 && !a.max ? '' : 's') })
    ]);
    var list = el('ol', { class: 'bhw-list', 'data-area': a.key, 'aria-label': 'Widgets in ' + a.name });
    if (!count) list.appendChild(el('li', { class: 'bhw-empty', text: a.active ? 'No widgets here yet. Add one, or drag one in from another area.' : 'Empty.' }));
    a.instances.forEach(function (inst, i) { list.appendChild(renderItem(a, inst, i)); });
    if (a.active) wireDrop(list, a);

    var foot = null;
    if (a.active) {
      var full = a.max && count >= a.max;
      foot = el('div', { class: 'bhw-buttons', style: 'margin-top:12px' }, [
        btn('Add widget', 'plus', 'bh-btn--secondary bh-btn--sm', function () { openPicker(a); }, { disabled: full ? true : null, 'data-focus': 'add:' + a.key }),
        full ? el('span', { class: 'bhw-count', text: 'This area is full.' }) : null
      ]);
    }
    return el('section', { class: 'bh-card bhw-area', 'aria-label': a.name }, [head, el('div', { class: 'bh-card__body' }, [list, foot])]);
  }

  function renderItem(a, inst, index) {
    var isOpen = !!open[inst.id];
    var li = el('li', { class: 'bhw-item' + (inst.disabled ? ' is-off' : ''), 'data-id': inst.id });
    var handle = el('button', {
      type: 'button', class: 'bh-btn bh-btn--ghost bh-btn--sm bh-btn--icon bhw-handle', draggable: 'true',
      'data-focus': 'handle:' + inst.id,
      'aria-label': 'Move ' + inst.name + (inst.label ? ' (' + inst.label + ')' : '') + ', position ' + (index + 1) + ' of ' + a.instances.length + '. Use the arrow keys to move it.',
      onkeydown: function (e) {
        if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
        e.preventDefault();
        var to = index + (e.key === 'ArrowUp' ? -1 : 1);
        if (to < 0 || to >= a.instances.length) return;
        call('move', { id: inst.id, area: a.key, position: to }).then(function () {
          say(inst.name + ' moved to position ' + (to + 1) + ' of ' + a.instances.length + '.');
        }, function (err) { fail(err, 'The widget could not be moved.'); });
      }
    });
    handle.innerHTML = ICONS['bars-2'] || '';
    handle.addEventListener('dragstart', function (e) {
      dragId = inst.id; li.classList.add('is-dragging');
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', inst.id); e.dataTransfer.setDragImage(li, 20, 20); } catch (x) {}
    });
    handle.addEventListener('dragend', function () { dragId = null; li.classList.remove('is-dragging'); });

    var badges = [];
    if (inst.disabled) badges.push(el('span', { class: 'bh-badge bh-badge--gray', text: 'Hidden' }));
    if (inst.unavailable) badges.push(el('span', { class: 'bh-badge bh-badge--amber', text: 'Unavailable' }));

    var sw = el('span', { class: 'bh-switch' }, [
      el('input', {
        type: 'checkbox', role: 'switch', class: 'bh-switch__input', checked: !inst.disabled ? true : null,
        'aria-label': 'Show ' + inst.name + ' on the site', 'data-focus': 'switch:' + inst.id,
        onchange: function (e) {
          var on = e.target.checked;
          call('options', { id: inst.id, disabled: !on }).then(function () {
            say(inst.name + (on ? ' is shown on the site.' : ' is hidden from the site.'));
          }, function (err) { e.target.checked = !on; fail(err, 'Not saved.'); });
        }
      }),
      el('span', { class: 'bh-switch__track', 'aria-hidden': 'true' })
    ]);

    var row = el('div', { class: 'bhw-row' }, [
      handle,
      icon(inst.icon),
      el('div', { class: 'bhw-row__text' }, [
        el('div', { class: 'bhw-row__name', text: inst.name }),
        inst.label ? el('div', { class: 'bhw-row__label', text: inst.label }) : null,
        badges.length ? el('div', { class: 'bhw-row__badges' }, badges) : null
      ]),
      el('div', { class: 'bhw-row__actions' }, [
        sw,
        btn(isOpen ? 'Close settings' : 'Edit settings', 'pencil-square', 'bh-btn--ghost bh-btn--sm bh-btn--icon', function () {
          if (open[inst.id]) delete open[inst.id]; else open[inst.id] = true;
          render();
        }, { 'aria-expanded': isOpen ? 'true' : 'false', 'aria-controls': 'bhw-panel-' + inst.id, 'data-focus': 'edit:' + inst.id }),
        btn('Duplicate', 'document-duplicate', 'bh-btn--ghost bh-btn--sm bh-btn--icon', function (e) {
          call('duplicate', { id: inst.id }, e.currentTarget, 'Copying…').then(function () { say(inst.name + ' copied.'); },
            function (err) { fail(err, 'The widget could not be copied.'); });
        }, { disabled: a.active && a.max && a.instances.length >= a.max ? true : null }),
        btn('Remove', 'trash', 'bh-btn--ghost bh-btn--sm bh-btn--icon', function () { remove(inst); })
      ])
    ]);
    li.appendChild(row);
    if (inst.unavailable) li.appendChild(el('p', { class: 'bhw-locked', style: 'padding:0 12px 10px', text: inst.unavailable }));
    if (isOpen) li.appendChild(renderPanel(a, inst));
    return li;
  }

  function remove(inst) {
    var go = window.bhConfirm
      ? bhConfirm('Remove "' + (inst.label || inst.name) + '" from this area? Its settings are deleted too.', { danger: true, confirmLabel: 'Remove' })
      : Promise.resolve(true);
    go.then(function (ok) {
      if (!ok) return;
      delete open[inst.id]; delete drafts[inst.id];
      call('remove', { id: inst.id }).then(function () { toast('Widget removed.'); },
        function (err) { fail(err, 'The widget could not be removed.'); });
    });
  }

  // ── settings form, from the widget's schema ───────────────────────────────
  function renderPanel(a, inst) {
    var fields = state.forms[inst.widget] || [];
    var values = Object.assign({}, inst.settings, drafts[inst.id] && drafts[inst.id].settings);
    var opts = Object.assign({ hide_title: inst.hide_title, 'class': inst['class'] }, drafts[inst.id] && drafts[inst.id].options);
    var errs = errors[inst.id] || {};
    var form = el('form', { class: 'bhw-panel', id: 'bhw-panel-' + inst.id, novalidate: true });
    var preview = el('div', { class: 'bhw-preview', hidden: true });

    function draft() {
      drafts[inst.id] = { settings: collect(form, fields), options: { hide_title: form.querySelector('[name="__hide_title"]').checked, 'class': form.querySelector('[name="__class"]').value } };
      return drafts[inst.id];
    }
    function applyShowIf() {
      var vals = collect(form, fields);
      fields.forEach(function (f) {
        var wrap = form.querySelector('[data-field="' + CSS.escape(f.key) + '"]');
        if (wrap) wrap.hidden = !visible(f, vals);
      });
    }

    if (inst.unavailable) form.appendChild(el('p', { class: 'bh-note bh-note--warn', text: inst.unavailable + ' You can still change its settings.' }));
    if (!fields.length) form.appendChild(el('p', { class: 'bh-help', text: 'This widget has no settings.' }));
    fields.forEach(function (f) { form.appendChild(renderField(inst, f, values[f.key], errs[f.key])); });

    var areaSelect = el('select', { class: 'bh-select', id: 'bhw-move-' + inst.id }, state.areas.filter(function (x) { return x.active; }).map(function (x) {
      return el('option', { value: x.key, selected: x.key === a.key ? true : null, text: x.name });
    }));
    var options = el('div', { class: 'bhw-options' }, [
      el('div', { class: 'bhw-inline' }, [
        el('span', { class: 'bh-switch' }, [
          el('input', { type: 'checkbox', role: 'switch', class: 'bh-switch__input', id: 'bhw-ht-' + inst.id, name: '__hide_title', checked: opts.hide_title ? true : null }),
          el('span', { class: 'bh-switch__track', 'aria-hidden': 'true' })
        ]),
        el('label', { class: 'bh-label', for: 'bhw-ht-' + inst.id, style: 'margin:0', text: 'Hide the title on the site' })
      ]),
      el('div', { class: 'bhw-field' }, [
        el('label', { class: 'bh-label', for: 'bhw-cls-' + inst.id, text: 'CSS class' }),
        el('input', { class: 'bh-input', id: 'bhw-cls-' + inst.id, name: '__class', value: opts['class'] || '', maxlength: 500, autocomplete: 'off', spellcheck: 'false' }),
        el('p', { class: 'bh-help', text: 'Added to the widget’s wrapper, for your theme’s CSS. Letters, digits, - and _; separate several with spaces.' })
      ]),
      el('div', { class: 'bhw-field' }, [
        el('label', { class: 'bh-label', for: 'bhw-move-' + inst.id, text: 'Area' }),
        el('div', { class: 'bhw-inline' }, [areaSelect,
          btn('Move', null, 'bh-btn--secondary bh-btn--sm', function (e) {
            var to = areaSelect.value;
            if (to === a.key) return;
            var target = state.areas.filter(function (x) { return x.key === to; })[0];
            call('move', { id: inst.id, area: to, position: target ? target.instances.length : 0 }, e.currentTarget, 'Moving…').then(function () {
              say(inst.name + ' moved to ' + (target ? target.name : to) + '.');
            }, function (err) { fail(err, 'The widget could not be moved.'); });
          })])
      ])
    ]);
    form.appendChild(options);

    var save = btn('Save changes', null, 'bh-btn--primary bh-btn--sm', null, { type: 'submit' });
    form.appendChild(el('div', { class: 'bhw-buttons' }, [
      save,
      btn('Preview', 'eye', 'bh-btn--secondary bh-btn--sm', function (e) {
        var d = draft();
        var l = window.bhLoader ? bhLoader(e.currentTarget, 'Rendering…') : null;
        Basehim.ajax.request('widgets.preview', { id: inst.id, settings: JSON.stringify(d.settings), hide_title: !!d.options.hide_title }).then(function (res) {
          if (l) l.remove();
          showPreview(preview, res.html, res.note);
        }, function (err) { if (l) l.remove(); fail(err, 'The preview could not be made.'); });
      }),
      btn('Restore defaults', 'arrow-uturn-left', 'bh-btn--ghost bh-btn--sm', function () {
        var d = {};
        fields.forEach(function (f) { if (!f.locked) d[f.key] = f['default']; });
        drafts[inst.id] = { settings: Object.assign({}, values, d), options: opts };
        errors[inst.id] = {};
        render();
        say('Defaults restored in the form. Save to keep them.');
      }),
      btn('Close', null, 'bh-btn--ghost bh-btn--sm', function () { delete open[inst.id]; render(); })
    ]));
    form.appendChild(preview);

    form.addEventListener('input', function () { draft(); applyShowIf(); });
    form.addEventListener('change', function () { draft(); applyShowIf(); });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var d = draft();
      call('save', { id: inst.id, settings: JSON.stringify(d.settings), hide_title: !!d.options.hide_title, 'class': d.options['class'] || '' }, save)
        .then(function () {
          delete drafts[inst.id]; delete errors[inst.id];
          render();
          toast('Widget saved.');
        }, function (err) {
          var map = {};
          Object.keys((err && err.fields) || {}).forEach(function (k) {
            var key = k.indexOf('settings.') === 0 ? k.slice(9) : k;
            map[key] = [].concat(err.fields[k])[0];
          });
          errors[inst.id] = map;
          render();
          var first = document.querySelector('#bhw-panel-' + CSS.escape(inst.id) + ' [aria-invalid="true"]');
          if (first) first.focus();
          fail(err, 'Not saved. Check the highlighted settings.');
        });
    });
    applyShowIf();
    return form;
  }

  function fieldId(inst, f) { return 'bhw-f-' + inst.id + '-' + f.key; }

  function renderField(inst, f, value, error) {
    var id = fieldId(inst, f);
    var wrap = el('div', { class: 'bhw-field', 'data-field': f.key });
    var label = el('label', { class: 'bh-label', for: id, text: f.label + (f.required ? ' (required)' : '') });
    var help = f.help ? el('p', { class: 'bh-help', id: id + '-help', text: f.help }) : null;
    var described = [help ? id + '-help' : null, error ? id + '-err' : null].filter(Boolean).join(' ') || null;
    var common = { id: id, name: f.key, 'aria-describedby': described, 'aria-invalid': error ? 'true' : null, disabled: f.locked ? true : null };
    var input;

    switch (f.type) {
      case 'checkbox':
        input = el('span', { class: 'bh-switch' }, [
          el('input', Object.assign({}, common, { type: 'checkbox', role: 'switch', class: 'bh-switch__input', value: '1', checked: String(value) === '1' ? true : null })),
          el('span', { class: 'bh-switch__track', 'aria-hidden': 'true' })
        ]);
        wrap.appendChild(el('div', { class: 'bhw-inline' }, [input, el('label', { class: 'bh-label', for: id, style: 'margin:0', text: f.label })]));
        label = null;
        break;
      case 'textarea':
      case 'html':
        input = el('textarea', Object.assign({}, common, { class: 'bh-textarea', rows: f.type === 'html' ? 8 : 4, maxlength: f.maxlength || null,
          style: f.type === 'html' ? 'font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px' : null, spellcheck: f.type === 'html' ? 'false' : null }));
        input.value = value == null ? '' : String(value);
        break;
      case 'select':
      case 'radio':
        input = el('select', Object.assign({}, common, { class: 'bh-select' }), (f.options || []).map(function (o) {
          return el('option', { value: o[0], selected: String(value) === o[0] ? true : null, text: o[1] });
        }));
        break;
      case 'multiselect':
      case 'terms':
        if (f.type === 'terms' && !f.multiple) {
          input = el('select', Object.assign({}, common, { class: 'bh-select' }), [el('option', { value: '0', text: 'None' })].concat((f.options || []).map(function (o) {
            return el('option', { value: o[0], selected: String(value) === o[0] ? true : null, text: o[1] });
          })));
          break;
        }
        var chosen = (Array.isArray(value) ? value : []).map(String);
        if (!(f.options || []).length) {
          input = el('p', { class: 'bh-help', id: id, text: 'Nothing to choose from yet.' });
        } else {
          input = el('div', { class: 'bhw-checks', role: 'group', id: id, 'aria-labelledby': id + '-lbl', 'aria-describedby': described }, (f.options || []).map(function (o, i) {
            return el('label', null, [el('input', { type: 'checkbox', name: f.key, value: o[0], checked: chosen.indexOf(o[0]) >= 0 ? true : null, disabled: f.locked ? true : null }), o[1]]);
          }));
          label = el('span', { class: 'bh-label', id: id + '-lbl', text: f.label });
        }
        break;
      case 'number':
        input = el('input', Object.assign({}, common, { type: 'number', class: 'bh-input', inputmode: 'decimal', min: f.min, max: f.max, step: f.step || 1 }));
        input.value = value == null ? '' : String(value);
        break;
      case 'color':
        input = el('input', Object.assign({}, common, { type: 'text', class: 'bh-input', placeholder: '#2563eb', maxlength: 9, pattern: '#[0-9a-fA-F]{3,8}' }));
        input.value = value || '';
        break;
      case 'posts':
      case 'media':
        input = el('input', Object.assign({}, common, { type: 'text', class: 'bh-input', inputmode: 'numeric', placeholder: f.type === 'media' ? 'Media ID' : 'Post IDs, separated by commas' }));
        input.value = Array.isArray(value) ? value.join(', ') : (value && value !== 0 ? String(value) : '');
        break;
      default: // text, url, date
        input = el('input', Object.assign({}, common, { type: f.type === 'url' ? 'url' : (f.type === 'date' ? 'date' : 'text'), class: 'bh-input', maxlength: f.maxlength || null }));
        input.value = value == null ? '' : String(value);
    }
    if (label) wrap.appendChild(label);
    if (f.type !== 'checkbox') wrap.appendChild(input);
    if (help) wrap.appendChild(help);
    if (f.locked) wrap.appendChild(el('p', { class: 'bhw-locked', text: 'Your role cannot change this setting; it keeps its saved value.' }));
    if (error) wrap.appendChild(el('p', { class: 'bhw-error', id: id + '-err', text: error }));
    return wrap;
  }

  function collect(form, fields) {
    var out = {};
    fields.forEach(function (f) {
      if (f.locked) return;
      if (f.type === 'checkbox') {
        var c = form.querySelector('[name="' + CSS.escape(f.key) + '"]');
        out[f.key] = c && c.checked ? '1' : '0';
      } else if (f.type === 'multiselect' || (f.type === 'terms' && f.multiple)) {
        out[f.key] = Array.prototype.map.call(form.querySelectorAll('input[name="' + CSS.escape(f.key) + '"]:checked'), function (c) { return c.value; });
      } else {
        var n = form.querySelector('[name="' + CSS.escape(f.key) + '"]');
        if (n) out[f.key] = n.value;
      }
    });
    return out;
  }

  function visible(f, vals) {
    if (!f.show_if) return true;
    return Object.keys(f.show_if).every(function (k) {
      var want = [].concat(f.show_if[k]).map(String);
      var have = vals[k];
      if (Array.isArray(have)) return have.some(function (h) { return want.indexOf(String(h)) >= 0; });
      return want.indexOf(String(have == null ? '' : have)) >= 0;
    });
  }

  function showPreview(box, html, note) {
    box.hidden = false;
    box.textContent = '';
    if (note) { box.appendChild(el('p', { class: 'bhw-preview__note', text: note })); return; }
    // Scripts stay off (sandbox without allow-scripts); the widget's markup
    // and styles show as they would, without the theme's stylesheet.
    var frame = el('iframe', { sandbox: '', title: 'Preview', referrerpolicy: 'no-referrer' });
    frame.srcdoc = '<!doctype html><meta charset="utf-8"><base target="_blank"><style>body{font:14px/1.5 system-ui,sans-serif;color:#0f172a;margin:12px}a{color:#2563eb}img{max-width:100%;height:auto}</style>'
      + (html || '<p style="color:#64748b">Nothing to show with these settings.</p>');
    frame.addEventListener('load', function () {
      try { frame.style.height = Math.min(600, frame.contentDocument.documentElement.scrollHeight + 4) + 'px'; } catch (x) {}
    });
    box.appendChild(frame);
    box.appendChild(el('p', { class: 'bhw-preview__note', text: 'Shown without your theme’s styles, and without scripts. Save, then open the site to see it in place.' }));
  }

  // ── drag and drop ────────────────────────────────────────────────────────
  function wireDrop(list, a) {
    function indexAt(y) {
      var items = Array.prototype.filter.call(list.children, function (c) { return c.classList.contains('bhw-item') && c.getAttribute('data-id') !== dragId; });
      for (var i = 0; i < items.length; i++) {
        var r = items[i].getBoundingClientRect();
        if (y < r.top + r.height / 2) return i;
      }
      return items.length;
    }
    list.addEventListener('dragover', function (e) {
      if (!dragId) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      list.classList.add('is-over');
    });
    list.addEventListener('dragleave', function (e) { if (!list.contains(e.relatedTarget)) list.classList.remove('is-over'); });
    list.addEventListener('drop', function (e) {
      if (!dragId) return;
      e.preventDefault();
      list.classList.remove('is-over');
      var id = dragId, pos = indexAt(e.clientY), from = areaOf(id);
      dragId = null;
      if (from && from.area.key === a.key && from.index === pos) return;
      call('move', { id: id, area: a.key, position: pos }).then(function () {
        say((from ? from.inst.name : 'Widget') + ' moved to ' + a.name + ', position ' + (pos + 1) + '.');
      }, function (err) { fail(err, 'The widget could not be moved.'); });
    });
  }

  // ── picker ───────────────────────────────────────────────────────────────
  function refusal(area, w) {
    if (w.unavailable) return w.unavailable;
    if (w.areas.length && w.areas.indexOf(area.key) < 0) return 'Not for this area.';
    if ((area.supports.length || area.widgets.length) && area.widgets.indexOf(w.key) < 0 && area.supports.indexOf(w.category) < 0) return 'This area does not take this kind of widget.';
    if (area.max && area.instances.length >= area.max) return 'This area is full.';
    return null;
  }

  function drawPicker() {
    var q = pickerSearch.value.trim().toLowerCase();
    pickerList.textContent = '';
    var groups = {};
    state.widgets.forEach(function (w) {
      if (q && (w.title + ' ' + w.description + ' ' + w.key).toLowerCase().indexOf(q) < 0) return;
      (groups[w.category] = groups[w.category] || []).push(w);
    });
    var cats = Object.keys(state.categories).filter(function (c) { return groups[c]; });
    if (!cats.length) { pickerList.appendChild(el('p', { class: 'bh-help', text: q ? 'No widget matches "' + q + '".' : 'No widgets can be placed. Activate an app or theme that provides some.' })); return; }
    cats.forEach(function (c) {
      var items = el('div', { class: 'bhw-picker__items' });
      groups[c].forEach(function (w) {
        var why = refusal(pickerArea, w);
        var b = el('button', { type: 'button', class: 'bhw-pick', 'aria-disabled': why ? 'true' : null, onclick: function () {
          if (why) { say(why); return; }
          picker.close();
          call('add', { area: pickerArea.key, widget: w.key }, null).then(function (res) {
            if (res && res.added) { open[res.added] = true; render(); }
            toast(w.title + ' added.');
            var f = res && res.added && app.querySelector('[data-focus="edit:' + CSS.escape(res.added) + '"]');
            if (f) f.focus();
          }, function (err) { fail(err, 'The widget could not be added.'); });
        } }, [icon(w.icon), el('span', null, [
          el('span', { class: 'bhw-pick__title', text: w.title }), el('br'),
          el('span', { class: 'bhw-pick__text', text: why || w.description || '' })
        ])]);
        items.appendChild(b);
      });
      pickerList.appendChild(el('section', { class: 'bhw-picker__group' }, [el('h3', { text: state.categories[c] }), items]));
    });
  }

  function openPicker(a) {
    pickerArea = a;
    document.getElementById('bhw-picker-title').textContent = 'Add a widget to ' + a.name;
    pickerSearch.value = '';
    drawPicker();
    if (picker.showModal) picker.showModal(); else picker.setAttribute('open', '');
    pickerSearch.focus();
  }
  pickerSearch.addEventListener('input', drawPicker);
  picker.querySelector('[data-close]').addEventListener('click', function () { picker.close(); });
  picker.addEventListener('close', function () {
    if (pickerArea) { var b = app.querySelector('[data-focus="add:' + CSS.escape(pickerArea.key) + '"]'); if (b) b.focus(); }
  });

  // ── start ────────────────────────────────────────────────────────────────
  window.addEventListener('beforeunload', function (e) {
    if (Object.keys(drafts).length) { e.preventDefault(); e.returnValue = ''; }
  });
  call('state').catch(function (err) {
    app.textContent = '';
    app.appendChild(el('div', { class: 'bh-note bh-note--warn', text: ((err && err.message) || 'The widget areas could not be loaded.') + ' Reload the page to try again.' }));
  });
})();
