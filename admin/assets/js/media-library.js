/* Basehim Media Library (1.2.67).
 *
 * One toolbar (type, date, search, sort) drives two views:
 *   Grid  read a page at a time from media.list, with Load More;
 *   List  the shared Data Table `media.library`, driven by its bh:dt-* events.
 * The details dialog (edit, copy URL, delete) serves both. Every request
 * goes through the AJAX gateway; the server checks who may do what, and this
 * screen only hides what would be refused anyway.
 *
 * Exposes window.BasehimMediaLibrary = { reload() }.
 */
(function () {
  var root = document.querySelector('.nml');
  if (!root) return;

  var USER_ID = String(root.getAttribute('data-user-id') || '');
  var CAN_DELETE_OTHERS = root.getAttribute('data-can-delete-others') === '1';
  var CAN_EDIT_OTHERS = root.getAttribute('data-can-edit-others') === '1';
  var PER_PAGE = 60;

  var gridEl = document.getElementById('nml-grid');
  var countEl = document.getElementById('nml-count');
  var pillsEl = document.getElementById('nml-pills');
  var searchEl = document.getElementById('nml-search');
  var searchClear = document.getElementById('nml-search-clear');
  var monthEl = document.getElementById('nml-month');
  var sortEl = document.getElementById('nml-sort');
  var moreWrap = document.getElementById('nml-more-wrap');
  var pane = document.getElementById('nml-pane');
  var paneBody = document.getElementById('nml-pane-body');
  var paneClose = document.getElementById('nml-pane-close');
  var paneSub = document.getElementById('nml-pane-sub');
  var paneFoot = document.getElementById('nml-pane-foot');
  var paneBackdrop = document.getElementById('nml-pane-backdrop');
  var gridPanel = root.querySelector('[data-nml-panel="grid"]');
  var listPanel = root.querySelector('[data-nml-panel="list"]');
  var dtRoot = listPanel ? listPanel.querySelector('.bh-dt') : null;
  var selectToggle = document.getElementById('nml-select-toggle');

  var state = { type: 'all', month: 'all', sort: 'newest', q: '', page: 0, hasMore: false, total: 0, items: [], view: 'masonry', selectedId: null };
  var byId = Object.create(null);   // id -> item, for the grid's current list
  var loading = false;              // a grid request is in flight
  var seq = 0;                      // bumped by every new search/filter: older answers are dropped
  var monthsLoaded = false;
  var listDirty = true;             // the table needs the toolbar's filters pushed to it
  var selectMode = false;
  var chosen = {};                  // id -> true, the grid's bulk selection
  var searchTimer = null;
  var paneOpener = null;

  // ---- helpers ----
  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function (c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function fmtSize(b){ b = +b || 0; var u = ['B','KB','MB','GB']; var i = 0; while (b >= 1024 && i < 3){ b /= 1024; i++; } return (Math.round(b * 10) / 10) + ' ' + u[i]; }
  function fmtDate(s){ if (!s) return '—'; return window.BasehimTime ? BasehimTime.format(s) : s; }
  function absUrl(u){ if (!u) return ''; if (/^https?:\/\//.test(u)) return u; return window.location.origin + u; }
  function toast(msg, type){ if (typeof window.bhToast === 'function') window.bhToast(msg, type || 'info'); else window.alert(msg); }
  function ask(msg, label){
    return window.bhConfirm ? window.bhConfirm(msg, { danger: true, confirmLabel: label || 'Delete' }) : Promise.resolve(window.confirm(msg));
  }
  function plural(n, one, many){ return n + ' ' + (n === 1 ? one : (many || one + 's')); }
  function canDelete(m){ return String(m.author_id) === USER_ID || CAN_DELETE_OTHERS; }
  function canEdit(m){ return String(m.author_id) === USER_ID || CAN_EDIT_OTHERS; }

  function sizesOf(m){
    if (m._sz) return m._sz;
    var s = m.sizes;
    if (typeof s === 'string'){ try { s = JSON.parse(s); } catch (e){ s = null; } }
    m._sz = (s && typeof s === 'object' && !Array.isArray(s)) ? s : {};
    return m._sz;
  }
  // src (and srcset) for a preview: generated copies, never the original first.
  function imgSrc(m, sizesAttr){
    var s = sizesOf(m);
    var set = [];
    ['medium', 'large'].forEach(function (n){ if (s[n] && s[n].url && s[n].width) set.push(esc(s[n].url) + ' ' + s[n].width + 'w'); });
    var src = (s.medium && s.medium.url) || (s.thumbnail && s.thumbnail.url) || m.url;
    return 'src="' + esc(src) + '"' + (set.length > 1 ? ' srcset="' + set.join(', ') + '" sizes="' + (sizesAttr || '240px') + '"' : '');
  }
  function kind(mime){
    mime = String(mime || '');
    if (mime === 'image/svg+xml') return 'svg';
    if (mime.indexOf('image/') === 0) return 'image';
    if (mime.indexOf('video/') === 0) return 'video';
    if (mime.indexOf('audio/') === 0) return 'audio';
    return 'document';
  }
  function docIcon(mime){
    if (mime.indexOf('pdf') >= 0) return ['fa-file-pdf', '#dc2626'];
    if (mime.indexOf('zip') >= 0 || mime.indexOf('compress') >= 0) return ['fa-file-zipper', '#d97706'];
    if (mime.indexOf('word') >= 0 || mime.indexOf('document') >= 0) return ['fa-file-word', '#2563eb'];
    if (mime.indexOf('sheet') >= 0 || mime.indexOf('excel') >= 0) return ['fa-file-excel', '#16a34a'];
    if (mime.indexOf('text') >= 0) return ['fa-file-lines', '#64748b'];
    return ['fa-file', '#94a3b8'];
  }
  function filtered(){ return !!(state.q || state.type !== 'all' || state.month !== 'all'); }

  // The grid's and the table's sort names for the same order.
  var TABLE_SORT = { newest: 'newest', oldest: 'oldest', name: 'title_az', name_desc: 'title_za', largest: 'size_desc', smallest: 'size_asc' };
  var PAGE_SORT = {}; Object.keys(TABLE_SORT).forEach(function (k){ PAGE_SORT[TABLE_SORT[k]] = k; });

  // ---- grid: fetch ----
  /**
   * Page 1 of the current filters (reset) or the next page (Load More).
   * One grid request at a time; a reset makes the answer to any older
   * request stale, and it is ignored when it arrives.
   */
  function query(reset){
    if (reset){
      seq++; state.page = 0; state.hasMore = false; loading = false;
    } else if (loading || !state.hasMore){
      return Promise.resolve();
    }
    var my = seq;
    loading = true;
    gridEl.setAttribute('aria-busy', 'true');
    if (reset) renderState('loading'); else renderMore('loading');

    var params = { sort: state.sort, per_page: PER_PAGE, page: state.page + 1 };
    if (state.type !== 'all') params.type = state.type;
    if (state.month !== 'all') params.month = state.month;
    if (state.q) params.q = state.q;
    if (!monthsLoaded && reset) params.months = 1;

    return window.Basehim.ajax.get('media.list', params).then(function (d){
      if (my !== seq) return;
      loading = false;
      gridEl.setAttribute('aria-busy', 'false');
      var meta = d.meta || {};
      if (reset){ state.items = []; byId = Object.create(null); }
      var added = [];
      (d.data || []).forEach(function (m){
        if (byId[m.id]) return;               // never the same file twice
        byId[m.id] = m; state.items.push(m); added.push(m);
      });
      state.page = meta.page || state.page + 1;
      state.total = meta.total || 0;
      state.hasMore = meta.has_more != null ? !!meta.has_more : state.page < (meta.last_page || 1);
      if (d.counts) renderCounts(d.counts);
      if (d.months && !monthsLoaded) fillMonths(d.months);
      if (reset) renderGrid(); else appendCards(added);
      renderMore();
      updateCount(state.total);
      updateBulkCount();
    }).catch(function (err){
      if (my !== seq) return;
      loading = false;
      gridEl.setAttribute('aria-busy', 'false');
      var msg = (err && err.message) || 'Could not load media.';
      if (reset || !state.items.length) renderState('error', msg); else renderMore('error', msg);
    });
  }

  function renderCounts(c){
    pillsEl.querySelectorAll('[data-count]').forEach(function (span){
      var k = span.getAttribute('data-count');
      span.textContent = c[k] != null ? c[k] : 0;
    });
  }
  function fillMonths(months){
    monthsLoaded = true;
    (months || []).forEach(function (m){
      var o = document.createElement('option');
      o.value = m.value; o.textContent = (m.label || m.value) + ' (' + m.count + ')';
      monthEl.appendChild(o);
    });
  }
  function updateCount(total){
    if (!countEl) return;
    countEl.textContent = plural(total, 'file') + (filtered() ? ' match.' : ' in your library.');
  }

  // ---- grid: thumbnails show a shimmer until they arrive (1.2.70) ----
  // 'load' and 'error' don't bubble, so listen in the capture phase.
  function markThumb(ev){
    var img = ev.target;
    if (!img || img.tagName !== 'IMG') return;
    var box = img.closest && img.closest('.nml-card__media--img');
    if (box) box.classList.add(ev.type === 'error' ? 'is-broken' : 'is-loaded');
  }
  document.addEventListener('load', markThumb, true);
  document.addEventListener('error', markThumb, true);
  // Images already in the browser's cache can finish before the listener
  // sees them; catch those after each render.
  function markCachedThumbs(root){
    (root || document).querySelectorAll('.nml-card__media--img img').forEach(function (img){
      if (img.complete) {
        var box = img.closest('.nml-card__media--img');
        if (box) box.classList.add(img.naturalWidth ? 'is-loaded' : 'is-broken');
      }
    });
  }

  // ---- grid: render ----
  function cardHtml(m){
    var k = kind(m.mime_type);
    var name = m.title || m.original_name || m.file_name || 'untitled';
    var sel = String(m.id) === String(state.selectedId) ? ' is-selected' : '';
    var inner;
    if (k === 'image' || k === 'svg'){
      var ratio = (state.view === 'masonry' && m.width && m.height) ? (' style="aspect-ratio:' + (+m.width) + '/' + (+m.height) + '"') : '';
      inner = '<div class="nml-card__media nml-card__media--img"' + ratio + '>'
            + '<img ' + imgSrc(m, '(max-width: 640px) 50vw, 240px') + ' alt="' + esc(m.alt_text || '') + '" loading="lazy" decoding="async">'
            + (k === 'svg' ? '<span class="nml-badge nml-badge--svg">SVG</span>' : '')
            + '</div>';
    } else if (k === 'video'){
      inner = '<div class="nml-card__media nml-card__media--icon nml-media-video">' + BasehimIcon('play-circle', 'w-4 h-4') + '<span class="nml-badge">VIDEO</span></div>';
    } else if (k === 'audio'){
      inner = '<div class="nml-card__media nml-card__media--icon nml-media-audio">' + BasehimIcon('musical-note', 'w-4 h-4') + '<span class="nml-badge">AUDIO</span></div>';
    } else {
      var di = docIcon(String(m.mime_type || ''));
      var ext = (m.file_name || '').split('.').pop().toUpperCase();
      inner = '<div class="nml-card__media nml-card__media--icon"><span style="color:' + di[1] + '">' + BasehimIcon(di[0], 'w-10 h-10') + '</span><span class="nml-ext">' + esc(ext) + '</span></div>';
    }
    // Labels apps add (BasehimMedia.addItemBadge, 1.2.87); escaped by media.js.
    var badges = (window.BasehimMedia && BasehimMedia.itemBadgesHtml) ? BasehimMedia.itemBadgesHtml(m) : '';
    return '<div class="nml-card' + sel + (chosen[m.id] ? ' is-chosen' : '') + '" data-id="' + (+m.id) + '" tabindex="0" role="listitem" aria-label="' + esc(name) + '">'
         + '<span class="nml-check" data-check="' + (+m.id) + '" role="checkbox" aria-checked="' + (chosen[m.id] ? 'true' : 'false') + '" aria-label="Select ' + esc(name) + '">' + BasehimIcon('check', 'w-4 h-4') + '</span>'
         + inner
         + (badges ? '<div class="nml-ext-badges">' + badges + '</div>' : '')
         + '<div class="nml-card__foot"><span class="nml-card__name" title="' + esc(name) + '">' + esc(name) + '</span>'
         + '<span class="nml-card__size">' + fmtSize(m.file_size) + '</span></div>'
         + '</div>';
  }
  function gridClass(){ return 'nml-grid ' + (state.view === 'grid' ? 'nml-grid--uniform' : 'nml-grid--masonry'); }
  function renderGrid(){
    if (!state.items.length){ renderState(filtered() ? 'nomatch' : 'empty'); return; }
    gridEl.className = gridClass();
    gridEl.innerHTML = state.items.map(cardHtml).join('');
    markCachedThumbs(gridEl);
  }
  function appendCards(items){
    if (!items.length) return;
    var tmp = document.createElement('div');
    tmp.innerHTML = items.map(cardHtml).join('');
    var first = tmp.firstElementChild;
    while (tmp.firstChild) gridEl.appendChild(tmp.firstChild);
    markCachedThumbs(gridEl);
    // Keyboard users carry on from the first new file.
    if (first && moreWrap.contains(document.activeElement)) first.focus();
  }
  /** The whole-grid states: loading, empty, no matches, error (with Retry). */
  function renderState(kindOfState, msg){
    gridEl.className = 'nml-grid nml-grid--state';
    var html;
    if (kindOfState === 'loading'){
      html = '<div class="nml-state" role="status"><span class="nml-spinner" aria-hidden="true"></span><p class="nml-state__title">Loading media…</p></div>';
    } else if (kindOfState === 'error'){
      html = '<div class="nml-state nml-state--error" role="alert"><span class="nml-state__icon" aria-hidden="true">' + BasehimIcon('exclamation-triangle', 'w-4 h-4') + '</span>'
           + '<p class="nml-state__title">Could not load media</p><p class="nml-state__text">' + esc(msg || '') + '</p>'
           + '<button type="button" class="nml-more" data-nml-retry>Try again</button></div>';
    } else if (kindOfState === 'nomatch'){
      html = '<div class="nml-state" role="status"><span class="nml-state__icon" aria-hidden="true">' + BasehimIcon('magnifying-glass', 'w-4 h-4') + '</span>'
           + '<p class="nml-state__title">No matches</p><p class="nml-state__text">No files match the current search or filters.</p>'
           + '<button type="button" class="nml-more" data-nml-clear>Clear filters</button></div>';
    } else {
      html = '<div class="nml-state" role="status"><span class="nml-state__icon" aria-hidden="true">' + BasehimIcon('photo', 'w-4 h-4') + '</span>'
           + '<p class="nml-state__title">No media yet</p><p class="nml-state__text">Upload images, video, audio or documents to use them in posts and pages.</p>'
           + '<button type="button" class="nml-upload-btn" data-nml-upload>' + BasehimIcon('cloud-arrow-up', 'w-4 h-4') + ' Upload Media</button></div>';
    }
    gridEl.innerHTML = html;
    moreWrap.innerHTML = '';
  }
  /** The Load More row: a button, a spinner, the end, or an error with Retry. */
  function renderMore(kindOfState, msg){
    if (!state.items.length && kindOfState !== 'error'){ moreWrap.innerHTML = ''; return; }
    if (kindOfState === 'loading'){
      moreWrap.innerHTML = '<span class="nml-more-status" role="status"><span class="nml-spinner" aria-hidden="true"></span> Loading more…</span>';
    } else if (kindOfState === 'error'){
      moreWrap.innerHTML = '<span class="nml-more-status nml-more-status--error" role="alert">' + esc(msg || 'Could not load more files.') + '</span> <button type="button" class="nml-more" data-nml-more>Retry</button>';
    } else if (state.hasMore){
      moreWrap.innerHTML = '<button type="button" class="nml-more" data-nml-more>Load more</button>'
        + '<span class="nml-more-status">Showing ' + state.items.length + ' of ' + state.total + '</span>';
    } else {
      moreWrap.innerHTML = state.page > 1 ? '<span class="nml-more-status">All ' + plural(state.total, 'file') + ' shown.</span>' : '';
    }
  }

  // ---- details dialog ----
  function findItem(id){ return byId[id] || null; }
  /** Open by id: from the grid's list, or fetched (media.get) for the List view. */
  function openById(id, opener){
    var m = findItem(id);
    paneOpener = opener || document.activeElement;
    if (m){ openPane(m); return; }
    window.Basehim.ajax.get('media.get', { id: parseInt(id, 10) }).then(function (d){
      if (d && d.item) openPane(d.item, d.can);
    }).catch(function (err){ toast((err && err.message) || 'Could not open that file.', 'error'); });
  }

  function openPane(m, can){
    can = can || { edit: canEdit(m), 'delete': canDelete(m) };
    state.selectedId = m.id;
    highlightSelected();

    var k = kind(m.mime_type);
    var preview;
    var sz = sizesOf(m), quick = '';
    if (k === 'image'){ quick = (sz.medium && sz.medium.url) || (sz.large && sz.large.url) || (sz.thumbnail && sz.thumbnail.url) || ''; }
    if (k === 'image' || k === 'svg'){
      preview = '<img id="nml-pane-img" src="' + esc(quick || m.url) + '" alt="' + esc(m.alt_text || '') + '">'
              + '<span class="nml-pane__loader bh-loader bh-loader--over" role="status" aria-live="polite"><span class="bh-loader__spin"></span><span class="bh-loader__text">Loading original…</span></span>';
    }
    else if (k === 'video') preview = '<video src="' + esc(m.url) + '" controls preload="metadata"></video>';
    else if (k === 'audio') preview = '<div class="nml-pane__audio">' + BasehimIcon('musical-note', 'w-4 h-4') + '</div><audio src="' + esc(m.url) + '" controls preload="metadata"></audio>';
    else { var di = docIcon(String(m.mime_type || '')); preview = '<div class="nml-pane__doc"><span style="color:' + di[1] + '">' + BasehimIcon(di[0], 'w-16 h-16') + '</span></div>'; }

    var dims = (m.width && m.height) ? (m.width + ' × ' + m.height + ' px') : '—';
    var full = absUrl(m.url);
    var ro = can.edit ? '' : ' readonly';

    paneBody.innerHTML =
      // The box keeps the image's shape while it loads (1.2.70). Before, it
      // had no height until the image arrived — on phones, where the preview
      // stacks above the fields, the "Loading original…" pill inside it was
      // clipped away and the dialog showed a blank gap.
      '<div class="nml-pane__preview nml-pane__preview--' + k + ((k === 'image' || k === 'svg') ? ' is-loading' : '') + '" id="nml-pane-preview"'
      + ((k === 'image' && +m.width > 0 && +m.height > 0) ? ' style="--nml-ar:' + (+m.width) + ' / ' + (+m.height) + '"' : '')
      + '>' + preview + '</div>'
      + '<div class="nml-pane__side">'

      + '<form class="nml-pane__section" id="nml-meta-form" novalidate>'
      +   '<label class="nml-pane__lbl" for="nml-f-title">Title</label>'
      +   '<input class="nml-pane__in" id="nml-f-title" data-f="title" maxlength="255" value="' + esc(m.title || '') + '"' + ro + '>'
      +   (k === 'image' || k === 'svg'
            ? '<label class="nml-pane__lbl" for="nml-f-alt">Alt text</label>'
            + '<input class="nml-pane__in" id="nml-f-alt" data-f="alt_text" maxlength="255" value="' + esc(m.alt_text || '') + '"' + ro + '>'
            : '')
      +   '<label class="nml-pane__lbl" for="nml-f-caption">Caption</label>'
      +   '<textarea class="nml-pane__in" id="nml-f-caption" data-f="caption" rows="2"' + ro + '>' + esc(m.caption || '') + '</textarea>'
      +   '<label class="nml-pane__lbl" for="nml-f-desc">Description</label>'
      +   '<textarea class="nml-pane__in" id="nml-f-desc" data-f="description" rows="3"' + ro + '>' + esc(m.description || '') + '</textarea>'
      +   (can.edit ? '' : '<p class="nml-pane__note">You can view this file. Editing it needs permission to edit other people\'s files.</p>')
      + '</form>'

      + '<div class="nml-pane__section">'
      +   '<div class="nml-pane__meta"><span>Type</span><span>' + esc(m.mime_type || '—') + '</span></div>'
      +   '<div class="nml-pane__meta"><span>Dimensions</span><span>' + dims + '</span></div>'
      +   '<div class="nml-pane__meta"><span>Size</span><span>' + fmtSize(m.file_size) + '</span></div>'
      +   (k === 'image' ? '<div class="nml-pane__meta nml-pane__sizes"><span>Sizes</span><span>' + (['thumbnail', 'medium', 'large'].filter(function (n){ return sz[n] && sz[n].url; }).map(function (n){
            return '<a href="' + esc(sz[n].url) + '" target="_blank" rel="noopener">' + n + '</a> ' + (+sz[n].width || '?') + '×' + (+sz[n].height || '?');
          }).join('<br>') || 'none yet — Settings → Media → Regenerate') + '</span></div>' : '')
      +   '<div class="nml-pane__meta"><span>Uploaded</span><span>' + esc(fmtDate(m.created_at)) + '</span></div>'
      +   (m.updated_at ? '<div class="nml-pane__meta"><span>Modified</span><span>' + esc(fmtDate(m.updated_at)) + '</span></div>' : '')
      +   '<div class="nml-pane__meta"><span>ID</span><span>' + esc(String(m.id)) + '</span></div>'
      + '</div>'

      + '<div class="nml-pane__section">'
      +   '<label class="nml-pane__lbl" for="nml-url">File URL</label>'
      +   '<div class="nml-pane__urlrow"><input class="nml-pane__in" id="nml-url" value="' + esc(full) + '" readonly><button type="button" class="nml-pane__copy" id="nml-copy" aria-label="Copy URL">' + BasehimIcon('document-duplicate', 'w-4 h-4') + '</button></div>'
      + '</div>'

      + '<div class="nml-pane__actions">'
      +   '<a class="nml-pane__btn" href="' + esc(m.url) + '" target="_blank" rel="noopener">' + BasehimIcon('arrow-top-right-on-square', 'w-4 h-4') + ' Open</a>'
      +   '<a class="nml-pane__btn" href="' + esc(m.url) + '" download>' + BasehimIcon('arrow-down-tray', 'w-4 h-4') + ' Download</a>'
      +   (can['delete'] ? '<button type="button" class="nml-pane__btn nml-pane__btn--danger" id="nml-del">' + BasehimIcon('trash', 'w-4 h-4') + ' Delete</button>' : '')
      + '</div>'
      + '</div>';

    // Header: the file's name under the title. Footer: Close, and Save
    // changes for people who may edit — submits the form from outside it.
    if (paneSub) paneSub.textContent = m.original_name || m.file_name || '';
    if (paneFoot){
      paneFoot.innerHTML = '<span class="nml-pane__saved hidden" id="nml-saved" role="status">Saved</span>'
        + '<button type="button" class="bh-btn bh-btn--secondary" id="nml-pane-done">Close</button>'
        + (can.edit ? '<button type="submit" form="nml-meta-form" class="bh-btn bh-btn--primary" id="nml-save-meta">Save changes</button>' : '');
      paneFoot.hidden = false;
      var doneBtn = document.getElementById('nml-pane-done');
      if (doneBtn) doneBtn.addEventListener('click', closePane);
    }
    pane.classList.add('is-open');
    pane.setAttribute('aria-hidden', 'false');
    paneBackdrop.classList.remove('hidden');
    wirePane(m);
    if (k === 'image' || k === 'svg') loadOriginal(m, quick);
    setTimeout(function (){ try { paneClose.focus(); } catch (e){} }, 0);
  }

  // Swap the quick preview for the original once it has downloaded. A newer
  // dialog (another file opened meanwhile) wins: its token differs.
  var paneToken = 0;
  function loadOriginal(m, quick){
    var token = ++paneToken;
    var img = document.getElementById('nml-pane-img'), box = document.getElementById('nml-pane-preview');
    if (!img || !box) return;
    var done = function (){ if (token === paneToken) box.classList.remove('is-loading'); };
    if (!quick || quick === m.url){
      if (img.complete && img.naturalWidth) done(); else { img.addEventListener('load', done); img.addEventListener('error', done); }
      return;
    }
    var full = new Image();
    full.onload = function (){ if (token !== paneToken) return; img.src = m.url; done(); };
    full.onerror = function (){
      if (token !== paneToken) return; done();
      var l = box.querySelector('.nml-pane__loader');
      if (l){ l.classList.add('is-error'); var tx = l.querySelector('.bh-loader__text'); if (tx) tx.textContent = 'Original could not be loaded'; box.classList.add('is-error'); }
    };
    full.src = m.url;
  }

  function copyText(text, btn){
    var ok = function (){
      if (btn){ var old = btn.innerHTML; btn.innerHTML = BasehimIcon('check', 'w-4 h-4'); setTimeout(function (){ btn.innerHTML = old; }, 1200); }
      toast('URL copied.', 'success');
    };
    if (navigator.clipboard && window.isSecureContext){
      navigator.clipboard.writeText(text).then(ok, function (){ fallbackCopy(text) ? ok() : toast('Copy failed. Select the URL and copy it.', 'error'); });
    } else if (fallbackCopy(text)) ok();
    else toast('Copy failed. Select the URL and copy it.', 'error');
  }
  function fallbackCopy(text){
    var t = document.createElement('textarea'); t.value = text; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0';
    document.body.appendChild(t); t.select();
    var ok = false; try { ok = document.execCommand('copy'); } catch (e){ ok = false; }
    t.remove(); return ok;
  }

  function wirePane(m){
    var form = document.getElementById('nml-meta-form');
    var save = document.getElementById('nml-save-meta');
    if (form && save) form.addEventListener('submit', function (ev){
      ev.preventDefault();
      if (save.getAttribute('aria-busy') === 'true') return;
      var payload = { id: parseInt(m.id, 10) };
      paneBody.querySelectorAll('[data-f]').forEach(function (inp){ payload[inp.getAttribute('data-f')] = inp.value; });
      var l = window.bhLoader ? window.bhLoader(save, 'Saving…') : null;
      // media.update: only the four descriptive fields are accepted there.
      window.Basehim.ajax.request('media.update', payload).then(function (d){
        if (l) l.done('Saved');
        if (d && d.item){
          if (byId[m.id]){
            for (var i = 0; i < state.items.length; i++){ if (String(state.items[i].id) === String(m.id)){ state.items[i] = d.item; break; } }
            byId[m.id] = d.item;
            var card = gridEl.querySelector('[data-id="' + (+m.id) + '"]');
            if (card){ var tmp = document.createElement('div'); tmp.innerHTML = cardHtml(d.item); card.replaceWith(tmp.firstElementChild); }
          }
          m = d.item;
        }
        if (state.view === 'list') reloadTable(false);
        // Without the shared loader, say it in the form instead.
        var sv = document.getElementById('nml-saved'); if (sv && !l){ sv.classList.remove('hidden'); setTimeout(function (){ sv.classList.add('hidden'); }, 1500); }
      }).catch(function (err){
        if (l) l.fail('Not saved');
        toast((err && err.message) || 'Could not save.', 'error');
      });
    });

    var copy = document.getElementById('nml-copy');
    if (copy) copy.addEventListener('click', function (){ copyText(document.getElementById('nml-url').value, copy); });

    var del = document.getElementById('nml-del');
    if (del) del.addEventListener('click', function (){
      ask('Delete "' + (m.title || m.original_name || m.file_name || 'this file') + '"? This cannot be undone.').then(function (ok){
        if (!ok) return;
        var l = window.bhLoader ? window.bhLoader(del, 'Deleting…') : null;
        deleteIds([parseInt(m.id, 10)]).then(function (d){
          if (d.deleted === 1 || d.missing === 1){ if (l) l.remove(); closePane(); }
          else if (l) l.fail('Not deleted');
        }, function (){ if (l) l.fail('Not deleted'); });
      });
    });
  }

  function closePane(){
    if (!pane.classList.contains('is-open')) return;
    pane.classList.remove('is-open');
    pane.setAttribute('aria-hidden', 'true');
    paneBackdrop.classList.add('hidden');
    paneToken++;
    paneBody.innerHTML = '';   // stops a playing video
    if (paneFoot){ paneFoot.innerHTML = ''; paneFoot.hidden = true; }
    if (paneSub) paneSub.textContent = '';
    state.selectedId = null;
    highlightSelected();
    if (paneOpener && document.contains(paneOpener)){ try { paneOpener.focus(); } catch (e){} }
    paneOpener = null;
  }
  function highlightSelected(){
    gridEl.querySelectorAll('.nml-card').forEach(function (c){
      c.classList.toggle('is-selected', String(c.getAttribute('data-id')) === String(state.selectedId));
    });
  }

  // ---- delete (grid, dialog and table share it) ----
  /**
   * media.delete with a result per file. Resolves with the counts; the
   * message says exactly what happened — a file that was not deleted is
   * never reported as deleted.
   */
  function deleteIds(ids){
    return window.Basehim.ajax.request('media.delete', { ids: ids }).then(function (d){
      var parts = [];
      if (d.deleted) parts.push(plural(d.deleted, 'file') + ' deleted');
      if (d.missing) parts.push(plural(d.missing, 'file') + ' already gone');
      if (d.denied) parts.push(plural(d.denied, 'file') + ' not deleted (you can only delete your own)');
      if (d.failed) parts.push(plural(d.failed, 'file') + ' could not be deleted');
      toast((parts.join('; ') || 'Nothing was deleted') + '.', d.failed || d.denied ? (d.deleted ? 'warning' : 'error') : 'success');
      afterChange((d.results || []).filter(function (r){ return r.status === 'deleted' || r.status === 'missing'; }).map(function (r){ return r.id; }));
      return d;
    }, function (err){
      toast((err && err.message) || 'Could not delete.', 'error');
      throw err;
    });
  }
  /** Bring both views up to date after files were deleted or uploaded. */
  function afterChange(goneIds){
    (goneIds || []).forEach(function (id){ delete chosen[id]; });
    listDirty = true;
    if (state.view === 'list'){ reloadTable(true); query(true); }
    else query(true);
  }

  // ---- grid events ----
  var anchorId = null;
  gridEl.addEventListener('mousedown', function (ev){ if (ev.shiftKey) ev.preventDefault(); });
  gridEl.addEventListener('click', function (ev){
    if (ev.target.closest('[data-nml-retry]')){ query(true); return; }
    if (ev.target.closest('[data-nml-clear]')){ clearFilters(); return; }
    if (ev.target.closest('[data-nml-upload]')){ var t = document.getElementById('nml-upload-toggle'); if (t){ var z = document.getElementById('upload-zone'); if (z && z.classList.contains('is-collapsed')) t.click(); else document.getElementById('upload-input').click(); } return; }
    var check = ev.target.closest('.nml-check');
    var card = ev.target.closest('.nml-card');
    var id = check ? check.getAttribute('data-check') : (card ? card.getAttribute('data-id') : null);
    if (!id) return;
    if (check) ev.stopPropagation();
    if (ev.shiftKey){
      if (!selectMode) setSelectMode(true);
      if (anchorId === null || !selectRange(anchorId, id)) toggleChosen(id);
      anchorId = id;
      return;
    }
    if (check){ if (!selectMode) setSelectMode(true); toggleChosen(id); anchorId = id; return; }
    if (selectMode){ toggleChosen(id); anchorId = id; }
    else openById(id, card);
  });
  gridEl.addEventListener('keydown', function (ev){
    var card = ev.target.closest('.nml-card'); if (!card) return;
    if (ev.key === 'Enter'){ ev.preventDefault(); if (selectMode) toggleChosen(card.getAttribute('data-id')); else openById(card.getAttribute('data-id'), card); }
    else if (ev.key === ' '){ ev.preventDefault(); if (!selectMode) setSelectMode(true); toggleChosen(card.getAttribute('data-id')); }
  });
  moreWrap.addEventListener('click', function (ev){
    if (ev.target.closest('[data-nml-more]')) query(false);
  });

  // ---- grid bulk selection ----
  var bulkbar = document.getElementById('nml-bulkbar');
  var bulkCount = document.getElementById('nml-bulk-count');
  var selectAllCb = document.getElementById('nml-select-all');

  function setSelectMode(on){
    selectMode = on;
    root.classList.toggle('nml--selecting', on);
    bulkbar.classList.toggle('hidden', !on);
    selectToggle.classList.toggle('is-active', on);
    selectToggle.setAttribute('aria-pressed', on ? 'true' : 'false');
    if (!on){ chosen = {}; anchorId = null; if (selectAllCb) selectAllCb.checked = false; paintChosen(); }
    updateBulkCount();
  }
  function paintChosen(){
    gridEl.querySelectorAll('.nml-card').forEach(function (c){
      var on = !!chosen[c.getAttribute('data-id')];
      c.classList.toggle('is-chosen', on);
      var ck = c.querySelector('.nml-check'); if (ck) ck.setAttribute('aria-checked', on ? 'true' : 'false');
    });
  }
  function toggleChosen(id){
    if (chosen[id]) delete chosen[id]; else chosen[id] = true;
    paintChosen();
    updateBulkCount();
  }
  function selectRange(fromId, toId){
    var ids = state.items.map(function (x){ return String(x.id); });
    var a = ids.indexOf(String(fromId)), b = ids.indexOf(String(toId));
    if (a < 0 || b < 0) return false;
    var on = !!chosen[fromId];
    for (var i = Math.min(a, b); i <= Math.max(a, b); i++){ if (on) chosen[ids[i]] = true; else delete chosen[ids[i]]; }
    paintChosen(); updateBulkCount();
    return true;
  }
  function chosenIds(){ return Object.keys(chosen); }
  function updateBulkCount(){
    var n = chosenIds().length;
    if (bulkCount) bulkCount.textContent = n + ' selected';
    var delBtn = document.getElementById('nml-bulk-delete');
    if (delBtn) delBtn.disabled = n === 0;
    if (selectAllCb){
      var total = state.items.length;
      selectAllCb.checked = total > 0 && n >= total;
      selectAllCb.indeterminate = n > 0 && n < total;
    }
  }
  selectToggle.addEventListener('click', function (){ setSelectMode(!selectMode); });
  document.getElementById('nml-bulk-cancel').addEventListener('click', function (){ setSelectMode(false); });
  if (selectAllCb) selectAllCb.addEventListener('change', function (){
    if (selectAllCb.checked) state.items.forEach(function (m){ chosen[m.id] = true; });
    else chosen = {};
    paintChosen(); updateBulkCount();
  });
  document.getElementById('nml-bulk-delete').addEventListener('click', function (){
    var ids = chosenIds().map(function (id){ return parseInt(id, 10); });
    if (!ids.length) return;
    var btn = this;
    ask('Delete ' + plural(ids.length, 'selected file') + '? This cannot be undone.').then(function (ok){
      if (!ok) return;
      var l = window.bhLoader ? window.bhLoader(btn, 'Deleting…') : null;
      // At most 500 per request (the action's limit); the rest go in turn.
      var batches = []; for (var i = 0; i < ids.length; i += 500) batches.push(ids.slice(i, i + 500));
      batches.reduce(function (p, batch){ return p.then(function (){ return deleteIds(batch); }); }, Promise.resolve())
        .then(function (){ if (l) l.remove(); setSelectMode(false); },
              function (){ if (l) l.fail('Not deleted'); });
    });
  });

  // ---- toolbar ----
  function setType(t){
    state.type = t;
    pillsEl.querySelectorAll('.nml-pill').forEach(function (p){
      var on = p.getAttribute('data-type') === t;
      p.classList.toggle('is-active', on); p.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }
  function filtersChanged(){
    listDirty = true;
    if (state.view === 'list') pushTableQuery(); else query(true);
  }
  function clearFilters(){
    setType('all'); state.month = 'all'; monthEl.value = 'all';
    state.q = ''; searchEl.value = ''; searchClear.classList.add('hidden');
    filtersChanged();
  }
  pillsEl.addEventListener('click', function (ev){
    var pill = ev.target.closest('.nml-pill'); if (!pill) return;
    setType(pill.getAttribute('data-type'));
    filtersChanged();
  });
  monthEl.addEventListener('change', function (){ state.month = monthEl.value; filtersChanged(); });
  sortEl.addEventListener('change', function (){ state.sort = sortEl.value; filtersChanged(); });
  searchEl.addEventListener('input', function (){
    clearTimeout(searchTimer);
    searchClear.classList.toggle('hidden', !searchEl.value);
    searchTimer = setTimeout(function (){
      var v = searchEl.value.trim();
      if (v === state.q) return;
      state.q = v; filtersChanged();
    }, 300);
  });
  searchClear.addEventListener('click', function (){ searchEl.value = ''; state.q = ''; searchClear.classList.add('hidden'); filtersChanged(); searchEl.focus(); });

  // ---- List view: the Data Table ----
  function pushTableQuery(){
    if (!dtRoot) return;
    listDirty = false;
    dtRoot.dispatchEvent(new CustomEvent('bh:dt-query', { detail: {
      search: state.q,
      sort: TABLE_SORT[state.sort] || 'newest',
      filters: { type: state.type === 'all' ? '' : state.type, month: state.month === 'all' ? '' : state.month }
    } }));
  }
  function reloadTable(clearSelection){
    if (dtRoot) dtRoot.dispatchEvent(new CustomEvent('bh:dt-reload', { detail: { clearSelection: !!clearSelection } }));
  }
  if (dtRoot){
    dtRoot.addEventListener('bh:dt-loaded', function (e){
      if (state.view !== 'list') return;
      var d = e.detail || {};
      if (d.meta) updateCount(d.meta.total || 0);
      // A column header sort shows in the toolbar too.
      if (d.sort && PAGE_SORT[d.sort] && PAGE_SORT[d.sort] !== state.sort){ state.sort = PAGE_SORT[d.sort]; sortEl.value = state.sort; }
    });
    // Bulk Delete (and a row's Delete) run here, over AJAX, instead of the
    // table's form post: per-file results, and the table reloads in place.
    dtRoot.addEventListener('bh:dt-bulk', function (e){
      var d = e.detail || {};
      if (d.table !== 'media.library') return;
      e.preventDefault();
      if (d.action !== 'delete') return;
      var ids = (d.ids || []).map(function (x){ return parseInt(x, 10); }).filter(function (x){ return x > 0; });
      if (ids.length) deleteIds(ids).catch(function (){});
    });
    dtRoot.addEventListener('click', function (e){
      var open = e.target.closest('[data-media-open]');
      if (open){ e.preventDefault(); openById(open.getAttribute('data-media-open'), open); return; }
      var copy = e.target.closest('[data-media-copy]');
      if (copy){ e.preventDefault(); copyText(absUrl(copy.getAttribute('data-media-copy')), copy); }
    });
  }

  // ---- view switch ----
  function setView(v, initial){
    if (['masonry', 'grid', 'list'].indexOf(v) === -1) v = 'masonry';
    var wasList = state.view === 'list';
    state.view = v;
    document.querySelectorAll('.nml-vt').forEach(function (x){
      var on = x.getAttribute('data-view') === v;
      x.classList.toggle('is-active', on); x.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    var list = v === 'list';
    if (gridPanel) gridPanel.hidden = list;
    if (listPanel) listPanel.hidden = !list;
    selectToggle.hidden = list;   // the table has its own checkboxes
    if (list){
      if (selectMode) setSelectMode(false);
      pushTableQuery();
    } else if (initial || wasList){
      query(true);
    } else {
      gridEl.className = gridClass();
      renderGrid();
    }
    try { localStorage.setItem('nml.view', v); } catch (e){}
  }
  document.querySelector('.nml-viewtoggle').addEventListener('click', function (ev){
    var b = ev.target.closest('.nml-vt'); if (!b) return;
    setView(b.getAttribute('data-view'));
  });

  paneClose.addEventListener('click', closePane);
  paneBackdrop.addEventListener('click', closePane);
  document.addEventListener('keydown', function (ev){
    if (!pane.classList.contains('is-open')) return;
    if (ev.key === 'Escape'){ closePane(); return; }
    if (ev.key === 'Tab'){
      var f = Array.prototype.filter.call(pane.querySelectorAll('a[href], button, input, textarea, select, video, audio'), function (n){ return !n.disabled && n.offsetParent !== null; });
      if (!f.length) return;
      if (ev.shiftKey && document.activeElement === f[0]){ ev.preventDefault(); f[f.length - 1].focus(); }
      else if (!ev.shiftKey && document.activeElement === f[f.length - 1]){ ev.preventDefault(); f[0].focus(); }
    }
  });

  // ---- boot ----
  var saved = null;
  try { saved = localStorage.getItem('nml.view'); } catch (e){}
  window.BasehimMediaLibrary = { reload: function (){ afterChange([]); } };
  if (saved === 'list'){
    // The grid still loads once, for the type counts and the month list.
    query(true);
    setView('list', true);
  } else {
    setView(saved || 'masonry', true);
  }
})();
