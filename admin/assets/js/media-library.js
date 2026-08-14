/* Basehim Media Library — client-driven grid with pills, sort, search,
   masonry layout, and a detail side pane. Exposes window.BasehimMediaLibrary. */
(function () {
  var root = document.querySelector('.nml');
  if (!root) return;
  var BASE = root.getAttribute('data-base') || '';
  var CSRF = root.getAttribute('data-csrf') || '';

  var gridEl = document.getElementById('nml-grid');
  var countEl = document.getElementById('nml-count');
  var pillsEl = document.getElementById('nml-pills');
  var searchEl = document.getElementById('nml-search');
  var searchClear = document.getElementById('nml-search-clear');
  var sortEl = document.getElementById('nml-sort');
  var moreWrap = document.getElementById('nml-more-wrap');
  var moreBtn = document.getElementById('nml-more');
  var pane = document.getElementById('nml-pane');
  var paneBody = document.getElementById('nml-pane-body');
  var paneClose = document.getElementById('nml-pane-close');
  var paneBackdrop = document.getElementById('nml-pane-backdrop');

  var state = { type:'all', sort:'newest', q:'', page:1, perPage:60, lastPage:1, items:[], view:'masonry', selectedId:null };
  var selectMode = false;
  var chosen = {};   // id -> true, current bulk selection
  var searchTimer = null;

  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
  function fmtSize(b){ b=+b||0; var u=['B','KB','MB','GB']; var i=0; while(b>=1024&&i<3){b/=1024;i++;} return (Math.round(b*10)/10)+' '+u[i]; }
  function fmtDate(s){ if(!s) return '—'; var d=new Date(String(s).replace(' ','T')); return isNaN(d)?s:d.toLocaleString(); }
  function absUrl(u){ if(!u) return ''; if(/^https?:\/\//.test(u)) return u; return window.location.origin + u; }

  function kind(mime){
    mime = String(mime||'');
    if (mime === 'image/svg+xml') return 'svg';
    if (mime.indexOf('image/')===0) return 'image';
    if (mime.indexOf('video/')===0) return 'video';
    if (mime.indexOf('audio/')===0) return 'audio';
    return 'document';
  }
  function docIcon(mime){
    if (mime.indexOf('pdf')>=0) return ['fa-file-pdf','#dc2626'];
    if (mime.indexOf('zip')>=0||mime.indexOf('compress')>=0) return ['fa-file-zipper','#d97706'];
    if (mime.indexOf('word')>=0||mime.indexOf('document')>=0) return ['fa-file-word','#2563eb'];
    if (mime.indexOf('sheet')>=0||mime.indexOf('excel')>=0) return ['fa-file-excel','#16a34a'];
    if (mime.indexOf('text')>=0) return ['fa-file-lines','#64748b'];
    return ['fa-file','#94a3b8'];
  }

  // ---- fetch + render ----
  function query(reset){
    if (reset){ state.page = 1; }
    var params = new URLSearchParams();
    params.set('type', state.type);
    params.set('sort', state.sort);
    params.set('per_page', state.perPage);
    params.set('page', state.page);
    if (state.q) params.set('q', state.q);

    return fetch(BASE + '/admin/media/json?' + params.toString(), { credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (reset) state.items = [];
        state.items = state.items.concat(d.data || []);
        state.lastPage = (d.meta && d.meta.last_page) || 1;
        if (d.counts) renderCounts(d.counts);
        renderGrid();
        updateCount(d.meta ? d.meta.total : state.items.length);
        moreWrap.classList.toggle('hidden', state.page >= state.lastPage);
      }).catch(function(){
        gridEl.innerHTML = '<div class="nml-empty">'+BasehimIcon('exclamation-triangle','w-4 h-4')+'<p>Could not load media.</p></div>';
      });
  }

  function renderCounts(c){
    pillsEl.querySelectorAll('[data-count]').forEach(function(span){
      var k = span.getAttribute('data-count');
      span.textContent = c[k] != null ? c[k] : 0;
    });
  }

  function updateCount(total){
    if (countEl) countEl.textContent = total + ' file' + (total===1?'':'s') + (state.type!=='all'||state.q ? ' shown' : ' in your library') + '.';
  }

  function cardHtml(m){
    var k = kind(m.mime_type);
    var name = m.title || m.original_name || m.file_name || 'untitled';
    var sel = String(m.id) === String(state.selectedId) ? ' is-selected' : '';
    var inner;
    if (k === 'image' || k === 'svg'){
      // Preserve aspect ratio: set the box ratio from width/height when known.
      var ratio = (m.width && m.height) ? (' style="aspect-ratio:'+m.width+'/'+m.height+'"') : '';
      inner = '<div class="nml-card__media nml-card__media--img"'+ratio+'>'
            + '<img src="'+esc(m.url)+'" alt="'+esc(m.alt_text||'')+'" loading="lazy">'
            + (k==='svg'?'<span class="nml-badge nml-badge--svg">SVG</span>':'')
            + '</div>';
    } else if (k === 'video'){
      inner = '<div class="nml-card__media nml-card__media--icon nml-media-video">'+BasehimIcon('play-circle','w-4 h-4')+'<span class="nml-badge">VIDEO</span></div>';
    } else if (k === 'audio'){
      inner = '<div class="nml-card__media nml-card__media--icon nml-media-audio">'+BasehimIcon('musical-note','w-4 h-4')+'<span class="nml-badge">AUDIO</span></div>';
    } else {
      var di = docIcon(String(m.mime_type||''));
      var ext = (m.file_name||'').split('.').pop().toUpperCase();
      inner = '<div class="nml-card__media nml-card__media--icon"><span style="color:'+di[1]+'">'+BasehimIcon(di[0],'w-10 h-10')+'</span><span class="nml-ext">'+esc(ext)+'</span></div>';
    }
    return '<div class="nml-card'+sel+(chosen[m.id]?' is-chosen':'')+'" data-id="'+m.id+'" tabindex="0">'
         + '<span class="nml-check" data-check="'+m.id+'">'+BasehimIcon('check','w-4 h-4')+'</span>'
         + inner
         + '<div class="nml-card__foot"><span class="nml-card__name" title="'+esc(name)+'">'+esc(name)+'</span>'
         + '<span class="nml-card__size">'+fmtSize(m.file_size)+'</span></div>'
         + '</div>';
  }

  function listRowHtml(m){
    var k = kind(m.mime_type);
    var name = m.title || m.original_name || m.file_name || 'untitled';
    var thumb;
    if (k==='image'||k==='svg') thumb = '<img src="'+esc(m.url)+'" alt="">';
    else { var di=docIcon(String(m.mime_type||'')); thumb=BasehimIcon(k==='video'?'fa-circle-play':k==='audio'?'fa-music':di[0],'w-5 h-5'); }
    var dims = (m.width&&m.height)?(m.width+'×'+m.height):'—';
    var sel = String(m.id)===String(state.selectedId)?' is-selected':'';
    return '<div class="nml-row'+sel+(chosen[m.id]?' is-chosen':'')+'" data-id="'+m.id+'" tabindex="0">'
      + '<span class="nml-row__thumb">'+thumb+'</span>'
      + '<span class="nml-row__name">'+esc(name)+'</span>'
      + '<span class="nml-row__type">'+esc(k)+'</span>'
      + '<span class="nml-row__dims">'+dims+'</span>'
      + '<span class="nml-row__size">'+fmtSize(m.file_size)+'</span>'
      + '<span class="nml-row__date">'+esc(fmtDate(m.created_at))+'</span>'
      + '</div>';
  }

  function renderGrid(){
    if (!state.items.length){
      gridEl.innerHTML = '<div class="nml-empty">'+BasehimIcon('photo','w-4 h-4')+'<p>'
        + (state.q ? 'No media matches "'+esc(state.q)+'".' : (state.type!=='all' ? 'No '+state.type+' files.' : 'No media yet. Upload your first file above.'))
        + '</p></div>';
      return;
    }
    if (state.view === 'list'){
      gridEl.className = 'nml-grid nml-grid--list';
      gridEl.innerHTML = '<div class="nml-list__head"><span></span><span>Name</span><span>Type</span><span>Dimensions</span><span>Size</span><span>Uploaded</span></div>'
        + state.items.map(listRowHtml).join('');
    } else if (state.view === 'grid'){
      gridEl.className = 'nml-grid nml-grid--uniform';
      gridEl.innerHTML = state.items.map(cardHtml).join('');
    } else {
      gridEl.className = 'nml-grid nml-grid--masonry';
      gridEl.innerHTML = state.items.map(cardHtml).join('');
    }
  }

  // ---- detail pane ----
  function openPane(id){
    var m = state.items.filter(function(x){ return String(x.id)===String(id); })[0];
    if (!m) return;
    state.selectedId = id;
    highlightSelected();

    var k = kind(m.mime_type);
    var preview;
    if (k==='image'||k==='svg') preview = '<img src="'+esc(m.url)+'" alt="'+esc(m.alt_text||'')+'">';
    else if (k==='video') preview = '<video src="'+esc(m.url)+'" controls></video>';
    else if (k==='audio') preview = '<div class="nml-pane__audio">'+BasehimIcon('musical-note','w-4 h-4')+'</div><audio src="'+esc(m.url)+'" controls></audio>';
    else { var di=docIcon(String(m.mime_type||'')); preview='<div class="nml-pane__doc"><span style="color:'+di[1]+'">'+BasehimIcon(di[0],'w-16 h-16')+'</span></div>'; }

    var dims = (m.width&&m.height)?(m.width+' × '+m.height+' px'):'—';
    var full = absUrl(m.url);

    paneBody.innerHTML =
      '<div class="nml-pane__preview nml-pane__preview--'+k+'">'+preview+'</div>'
      // Everything except the preview lives in one column, so the modal can go
      // preview-left / details-right on desktop and stack on mobile.
      + '<div class="nml-pane__side">'
      + '<div class="nml-pane__name">'+esc(m.original_name||m.file_name||'')+'</div>'

      + '<div class="nml-pane__section">'
      +   '<label class="nml-pane__lbl">Title</label>'
      +   '<input class="nml-pane__in" data-f="title" value="'+esc(m.title||'')+'">'
      +   '<label class="nml-pane__lbl">Alt text</label>'
      +   '<input class="nml-pane__in" data-f="alt_text" value="'+esc(m.alt_text||'')+'">'
      +   '<label class="nml-pane__lbl">Caption</label>'
      +   '<textarea class="nml-pane__in" data-f="caption" rows="2">'+esc(m.caption||'')+'</textarea>'
      +   '<label class="nml-pane__lbl">Description</label>'
      +   '<textarea class="nml-pane__in" data-f="description" rows="3">'+esc(m.description||'')+'</textarea>'
      +   '<button class="nml-pane__save" id="nml-save-meta">Save changes</button>'
      +   '<span class="nml-pane__saved hidden" id="nml-saved">Saved</span>'
      + '</div>'

      + '<div class="nml-pane__section">'
      +   '<div class="nml-pane__meta"><span>Type</span><span>'+esc(m.mime_type||'—')+'</span></div>'
      +   '<div class="nml-pane__meta"><span>Dimensions</span><span>'+dims+'</span></div>'
      +   '<div class="nml-pane__meta"><span>Size</span><span>'+fmtSize(m.file_size)+'</span></div>'
      +   '<div class="nml-pane__meta"><span>Uploaded</span><span>'+esc(fmtDate(m.created_at))+'</span></div>'
      +   (m.updated_at?'<div class="nml-pane__meta"><span>Modified</span><span>'+esc(fmtDate(m.updated_at))+'</span></div>':'')
      +   '<div class="nml-pane__meta"><span>ID</span><span>'+esc(String(m.id))+'</span></div>'
      + '</div>'

      + '<div class="nml-pane__section">'
      +   '<label class="nml-pane__lbl">File URL</label>'
      +   '<div class="nml-pane__urlrow"><input class="nml-pane__in" id="nml-url" value="'+esc(full)+'" readonly><button class="nml-pane__copy" id="nml-copy">'+BasehimIcon('document-duplicate','w-4 h-4')+'</button></div>'
      + '</div>'

      + '<div class="nml-pane__actions">'
      +   '<a class="nml-pane__btn" href="'+esc(m.url)+'" target="_blank" rel="noopener">'+BasehimIcon('arrow-top-right-on-square','w-4 h-4')+' Open</a>'
      +   '<a class="nml-pane__btn" href="'+esc(m.url)+'" download>'+BasehimIcon('arrow-down-tray','w-4 h-4')+' Download</a>'
      +   '<button class="nml-pane__btn nml-pane__btn--danger" id="nml-del">'+BasehimIcon('trash','w-4 h-4')+' Delete</button>'
      + '</div>'
      + '</div>';

    pane.classList.add('is-open');
    pane.setAttribute('aria-hidden','false');
    paneBackdrop.classList.remove('hidden');
    wirePane(m);
  }

  function wirePane(m){
    var save = document.getElementById('nml-save-meta');
    if (save) save.addEventListener('click', function(){
      var fields = {};
      paneBody.querySelectorAll('[data-f]').forEach(function(inp){ fields[inp.getAttribute('data-f')] = inp.value; });
      save.disabled = true; save.textContent = 'Saving…';
      var body = new URLSearchParams(); body.set('_csrf', CSRF);
      Object.keys(fields).forEach(function(k){ body.set(k, fields[k]); });
      fetch(BASE+'/admin/media/'+m.id+'/update', {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':CSRF},body:body.toString()})
        .then(function(r){return r.json();}).then(function(d){
          save.disabled = false; save.textContent = 'Save changes';
          if (d.success){
            // Update local cache so the grid label reflects a new title.
            for (var i=0;i<state.items.length;i++){ if(String(state.items[i].id)===String(m.id)){ state.items[i]=d.data; break; } }
            renderGrid(); highlightSelected();
            var sv = document.getElementById('nml-saved'); if(sv){ sv.classList.remove('hidden'); setTimeout(function(){sv.classList.add('hidden');},1500); }
          } else { alert(d.error||'Could not save.'); }
        }).catch(function(){ save.disabled=false; save.textContent='Save changes'; });
    });

    var copy = document.getElementById('nml-copy');
    if (copy) copy.addEventListener('click', function(){
      var inp = document.getElementById('nml-url');
      if (navigator.clipboard) navigator.clipboard.writeText(inp.value);
      else { inp.select(); try{document.execCommand('copy');}catch(e){} }
      copy.innerHTML = ''+BasehimIcon('check','w-4 h-4')+'';
      setTimeout(function(){ copy.innerHTML = ''+BasehimIcon('document-duplicate','w-4 h-4')+''; }, 1200);
    });

    var del = document.getElementById('nml-del');
    if (del) del.addEventListener('click', function(){
      if (!confirm('Delete this file? This cannot be undone.')) return;
      var body = new URLSearchParams(); body.set('_csrf', CSRF);
      fetch(BASE+'/admin/media/'+m.id+'/delete', {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':CSRF},body:body.toString()})
        .then(function(r){ return r.ok ? r.json().catch(function(){return {success:true};}) : {error:'Delete failed'}; })
        .then(function(d){
          if (d && d.error){ alert(d.error); return; }
          closePane();
          state.items = state.items.filter(function(x){ return String(x.id)!==String(m.id); });
          renderGrid(); query(true);   // refresh counts
        });
    });
  }

  function closePane(){
    pane.classList.remove('is-open');
    pane.setAttribute('aria-hidden','true');
    paneBackdrop.classList.add('hidden');
    state.selectedId = null;
    highlightSelected();
  }
  function highlightSelected(){
    gridEl.querySelectorAll('.nml-card,.nml-row').forEach(function(c){
      c.classList.toggle('is-selected', String(c.getAttribute('data-id'))===String(state.selectedId));
    });
  }

  // ---- events ----
  gridEl.addEventListener('click', function(ev){
    // Clicking the checkbox toggles selection and enters select mode if needed.
    var check = ev.target.closest('.nml-check');
    if (check){
      ev.stopPropagation();
      if (!selectMode) setSelectMode(true);
      toggleChosen(check.getAttribute('data-check'));
      return;
    }
    var card = ev.target.closest('.nml-card,.nml-row');
    if (!card) return;
    var id = card.getAttribute('data-id');
    if (selectMode){ toggleChosen(id); }
    else { openPane(id); }
  });
  gridEl.addEventListener('keydown', function(ev){
    if (ev.key==='Enter'){ var card = ev.target.closest('.nml-card,.nml-row'); if(card){ if(selectMode) toggleChosen(card.getAttribute('data-id')); else openPane(card.getAttribute('data-id')); } }
  });

  // ---- bulk selection ----
  var bulkbar = document.getElementById('nml-bulkbar');
  var bulkCount = document.getElementById('nml-bulk-count');
  var selectAllCb = document.getElementById('nml-select-all');
  var selectToggle = document.getElementById('nml-select-toggle');

  function setSelectMode(on){
    selectMode = on;
    root.querySelector('.nml').classList; // no-op guard
    document.querySelector('.nml').classList.toggle('nml--selecting', on);
    bulkbar.classList.toggle('hidden', !on);
    selectToggle.classList.toggle('is-active', on);
    if (!on){ chosen = {}; if(selectAllCb) selectAllCb.checked = false; renderGrid(); }
    updateBulkCount();
  }
  function toggleChosen(id){
    if (chosen[id]) delete chosen[id]; else chosen[id] = true;
    var card = gridEl.querySelector('[data-id="'+CSS.escape(String(id))+'"]');
    if (card) card.classList.toggle('is-chosen', !!chosen[id]);
    updateBulkCount();
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

  selectToggle.addEventListener('click', function(){ setSelectMode(!selectMode); });
  document.getElementById('nml-bulk-cancel').addEventListener('click', function(){ setSelectMode(false); });
  if (selectAllCb) selectAllCb.addEventListener('change', function(){
    if (selectAllCb.checked){ state.items.forEach(function(m){ chosen[m.id] = true; }); }
    else { chosen = {}; }
    renderGrid(); updateBulkCount();
  });
  document.getElementById('nml-bulk-delete').addEventListener('click', function(){
    var ids = chosenIds();
    if (!ids.length) return;
    if (!confirm('Delete ' + ids.length + ' selected file' + (ids.length===1?'':'s') + '? This cannot be undone.')) return;
    var btn = document.getElementById('nml-bulk-delete');
    btn.disabled = true; btn.innerHTML = ''+BasehimIcon('arrow-path','w-4 h-4 animate-spin')+' Deleting…';
    var body = new URLSearchParams(); body.set('_csrf', CSRF);
    ids.forEach(function(id){ body.append('ids[]', id); });
    fetch(BASE+'/admin/media/bulk-delete', {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':CSRF},body:body.toString()})
      .then(function(r){ return r.json(); })
      .then(function(d){
        btn.disabled = false; btn.innerHTML = ''+BasehimIcon('trash','w-4 h-4')+' Delete';
        if (d && d.success){ chosen = {}; setSelectMode(false); query(true); }
        else { alert((d && d.error) || 'Bulk delete failed.'); }
      }).catch(function(){ btn.disabled=false; btn.innerHTML=''+BasehimIcon('trash','w-4 h-4')+' Delete'; alert('Bulk delete failed.'); });
  });

  pillsEl.addEventListener('click', function(ev){
    var pill = ev.target.closest('.nml-pill'); if(!pill) return;
    pillsEl.querySelectorAll('.nml-pill').forEach(function(p){ p.classList.toggle('is-active', p===pill); });
    state.type = pill.getAttribute('data-type');
    query(true);
  });

  sortEl.addEventListener('change', function(){ state.sort = sortEl.value; query(true); });

  searchEl.addEventListener('input', function(){
    clearTimeout(searchTimer);
    searchClear.classList.toggle('hidden', !searchEl.value);
    searchTimer = setTimeout(function(){ state.q = searchEl.value.trim(); query(true); }, 250);
  });
  searchClear.addEventListener('click', function(){ searchEl.value=''; state.q=''; searchClear.classList.add('hidden'); query(true); searchEl.focus(); });

  document.querySelector('.nml-viewtoggle').addEventListener('click', function(ev){
    var b = ev.target.closest('.nml-vt'); if(!b) return;
    document.querySelectorAll('.nml-vt').forEach(function(x){ x.classList.toggle('is-active', x===b); });
    state.view = b.getAttribute('data-view');
    try { localStorage.setItem('nml.view', state.view); } catch(e){}
    renderGrid();
  });

  moreBtn.addEventListener('click', function(){ if(state.page<state.lastPage){ state.page++; query(false); } });
  paneClose.addEventListener('click', closePane);
  paneBackdrop.addEventListener('click', closePane);
  document.addEventListener('keydown', function(ev){ if(ev.key==='Escape' && pane.classList.contains('is-open')) closePane(); });

  // ---- boot ----
  try { var v = localStorage.getItem('nml.view'); if (v){ state.view=v; document.querySelectorAll('.nml-vt').forEach(function(x){ x.classList.toggle('is-active', x.getAttribute('data-view')===v); }); } } catch(e){}
  window.BasehimMediaLibrary = { reload: function(){ query(true); } };
  query(true);
})();
