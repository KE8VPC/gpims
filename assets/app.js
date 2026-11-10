(function(){
  var root=document.documentElement,t=localStorage.getItem('gpims-theme');
  if(t)root.setAttribute('data-bs-theme',t);
  var b=document.getElementById('themeBtn');
  if(b)b.onclick=function(){var n=root.getAttribute('data-bs-theme')==='dark'?'light':'dark';root.setAttribute('data-bs-theme',n);localStorage.setItem('gpims-theme',n);};
  document.querySelectorAll('[data-confirm]').forEach(function(e){e.addEventListener('click',function(ev){if(!confirm(e.dataset.confirm))ev.preventDefault();});});
  // item form: show custom fields for selected category
  var cat=document.getElementById('category_id');
  if(cat){var show=function(){document.querySelectorAll('.cf-wrap').forEach(function(w){var lin=(window.CAT_LINEAGE&&window.CAT_LINEAGE[cat.value])||[cat.value];var on=lin.map(String).indexOf(w.dataset.cat)>-1;w.style.display=on?'block':'none';w.querySelectorAll('input,select').forEach(function(i){i.disabled=!on;});});};cat.addEventListener('change',show);show();}
  var type=document.getElementById('type');
  if(type){var tt=function(){document.querySelectorAll('.only-consumable').forEach(function(e){e.style.display=type.value!=='asset'?'':'none';});document.querySelectorAll('.only-asset').forEach(function(e){e.style.display=type.value==='asset'?'':'none';});};type.addEventListener('change',tt);tt();}
  // quick table filter
  var f=document.getElementById('quickFilter');
  if(f)f.addEventListener('input',function(){var v=f.value.toLowerCase();document.querySelectorAll('#filterTable tbody tr').forEach(function(r){r.style.display=r.textContent.toLowerCase().indexOf(v)>-1?'':'none';});});
})();

// Generic photo inputs: <input type=file data-shrink [data-auto]> shrinks the image before upload; data-auto submits the form;
// <button data-pick="inputId"> opens that input (camera or album). data-preview="imgId" shows a thumbnail instead of submitting.
(function(){
  function shrink(file){return new Promise(function(ok){if(!/^image\/(jpe?g|png|webp)$/i.test(file.type)||!window.createImageBitmap||!window.DataTransfer)return ok(file);
    createImageBitmap(file).then(function(bm){var m=1600,r=Math.min(1,m/Math.max(bm.width,bm.height)),c=document.createElement('canvas');c.width=Math.round(bm.width*r);c.height=Math.round(bm.height*r);c.getContext('2d').drawImage(bm,0,0,c.width,c.height);
    c.toBlob(function(b){ok(b&&b.size<file.size?new File([b],(file.name||'photo').replace(/\.\w+$/,'')+'.jpg',{type:'image/jpeg'}):file);},'image/jpeg',0.85);}).catch(function(){ok(file);});});}
  document.addEventListener('click',function(e){var b=e.target.closest&&e.target.closest('[data-pick]');if(!b)return;var i=document.getElementById(b.dataset.pick);if(i)i.click();});
  document.addEventListener('change',function(e){var inp=e.target;if(!inp.matches||!inp.matches('input[type=file][data-shrink]')||!inp.files.length)return;
    var form=inp.form;if(inp.dataset.auto&&form)form.querySelectorAll('button').forEach(function(x){x.disabled=true;});
    Promise.all(Array.prototype.map.call(inp.files,shrink)).then(function(fs){try{var dt=new DataTransfer();fs.forEach(function(x){dt.items.add(x);});inp.files=dt.files;}catch(err){}
      if(inp.dataset.auto&&form)form.submit();
      else if(inp.dataset.mark){var m=document.getElementById(inp.dataset.mark);if(m){m.classList.remove('d-none');}}});});
})();

// Offline scan queue: keep a small lookup list on the device and remind the user when scans are waiting to sync.
(function(){ try {
  if (!window.GP_STOCK || /offline_sync/.test(location.pathname)) return;
  var at = +(localStorage.getItem('gp-offline-index-at') || 0);
  if (navigator.onLine && Date.now() - at > 6 * 3600 * 1000 && window.fetch) fetch('offline_sync.php?index=1', {credentials: 'same-origin'}).then(function(r){ return r.ok ? r.json() : null; }).then(function(j){ if (j && j.items) { localStorage.setItem('gp-offline-index', JSON.stringify(j.items)); localStorage.setItem('gp-offline-index-at', String(Date.now())); } }).catch(function(){});
  var q = JSON.parse(localStorage.getItem('gp-offline-queue') || '[]') || [], m = document.querySelector('main');
  if (q.length && m) m.insertAdjacentHTML('afterbegin', '<div class="alert alert-warning py-2 d-flex justify-content-between align-items-center"><span><i class="bi bi-cloud-upload"></i> <strong>' + q.length + '</strong> offline scan' + (q.length === 1 ? '' : 's') + ' waiting to sync</span><a class="btn btn-sm btn-warning py-0" href="offline_sync.php">Review &amp; sync</a></div>');
} catch (e) {} })();


// Check photos: several photos per line (camera button adds one at a time), thumbnails with remove, and "photo required" guard.
(function(){
  var MAX = 4;
  document.addEventListener('click', function(e){
    var b = e.target.closest && e.target.closest('[data-addphoto]'); if (!b) return;
    var lid = b.dataset.addphoto, box = document.getElementById('phs' + lid); if (!box) return;
    if (box.querySelectorAll('.gp-ph').length >= MAX) { alert('Up to ' + MAX + ' photos per line.'); return; }
    var inp = document.createElement('input'); inp.type = 'file'; inp.name = 'ph[' + lid + '][]'; inp.accept = 'image/*'; inp.setAttribute('capture', 'environment'); inp.className = 'd-none'; inp.setAttribute('data-shrink', '');
    var wrap = document.createElement('span'); wrap.className = 'gp-ph position-relative d-none'; wrap.appendChild(inp);
    box.appendChild(wrap);
    inp.addEventListener('change', function(){
      if (!inp.files.length) { wrap.remove(); upd(lid); return; }
      var im = document.createElement('img'); im.className = 'rounded'; im.style.cssText = 'height:48px;width:64px;object-fit:cover'; im.alt = 'Photo to attach'; im.src = URL.createObjectURL(inp.files[0]);
      var x = document.createElement('button'); x.type = 'button'; x.className = 'btn-close position-absolute top-0 end-0 bg-white rounded-circle p-1'; x.style.cssText = 'transform:translate(35%,-35%);font-size:.55rem'; x.setAttribute('aria-label', 'Remove photo');
      x.onclick = function(){ wrap.remove(); upd(lid); };
      wrap.appendChild(im); wrap.appendChild(x); wrap.classList.remove('d-none'); wrap.classList.add('d-inline-block'); upd(lid);
    });
    inp.click();
  });
  function upd(lid){ var n = document.querySelectorAll('#phs' + lid + ' .gp-ph:not(.d-none)').length, c = document.getElementById('pc' + lid); if (c) { c.textContent = n ? n : ''; c.classList.toggle('d-none', !n); } }
  var f = document.getElementById('runForm');
  if (f && f.dataset.reqphoto === '1') f.addEventListener('submit', function(e){
    var bad = [];
    f.querySelectorAll('li[data-lid]').forEach(function(li){
      var lid = li.dataset.lid, fail = false;
      var r = li.querySelector('input[name="st[' + lid + ']"]:checked'); if (r && r.value !== 'ok') fail = true;
      var c = li.querySelector('input[name="fl[' + lid + ']"]'); if (c && c.checked) fail = true;
      var has = li.querySelectorAll('#phs' + lid + ' .gp-ph:not(.d-none)').length > 0;
      li.classList.toggle('border', fail && !has); li.classList.toggle('border-danger', fail && !has);
      if (fail && !has) bad.push(li);
    });
    if (bad.length) { e.preventDefault(); alert('Add a photo for each problem (' + bad.length + ' missing). The lines are outlined in red.'); bad[0].scrollIntoView({behavior: 'smooth', block: 'center'}); }
  });
})();

// Photo viewer: <a data-lb="url" [data-ref="url"] [data-cap="text"]> opens the picture big, with the reference photo beside it.
(function(){
  var modal, bs;
  function build(){
    if (modal) return; var d = document.createElement('div'); d.className = 'modal fade'; d.tabIndex = -1;
    d.innerHTML = '<div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content"><div class="modal-header py-2"><div class="modal-title small" id="gpLbCap"></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="row g-2" id="gpLbBody"></div></div></div></div>';
    document.body.appendChild(d); modal = d; bs = new bootstrap.Modal(d);
  }
  document.addEventListener('click', function(e){
    var a = e.target.closest && e.target.closest('a[data-lb]'); if (!a || !window.bootstrap) return;
    e.preventDefault(); build();
    var body = document.getElementById('gpLbBody'); body.innerHTML = '';
    function col(url, label){ var c = document.createElement('div'); c.className = a.dataset.ref ? 'col-md-6' : 'col-12'; var h = label ? '<div class="small text-muted mb-1">' + label + '</div>' : ''; c.innerHTML = h; var im = document.createElement('img'); im.src = url; im.className = 'img-fluid rounded w-100'; im.style.maxHeight = '75vh'; im.style.objectFit = 'contain'; im.alt = label || ''; c.appendChild(im); body.appendChild(c); }
    col(a.dataset.lb, a.dataset.ref ? 'Reported' : '');
    if (a.dataset.ref) col(a.dataset.ref, 'How it should look');
    document.getElementById('gpLbCap').textContent = a.dataset.cap || ''; bs.show();
  });
})();

// ---- Inventory list: live search, paging, lazy category bodies ----
(function(){
  var form = document.getElementById('invForm'), res = document.getElementById('invResults'); if (!form || !res) return;
  var csv = document.getElementById('invCsv'), timer = null, seq = 0, base = location.pathname.replace(/[^\/]*$/, '') + 'items.php';
  var CK = 'gp-inv-collapsed', OK_ = 'gp-inv-open';
  function rd(k){ try { return JSON.parse(localStorage.getItem(k) || '[]'); } catch(e) { return []; } }
  function wr(k, v){ try { localStorage.setItem(k, JSON.stringify(v)); } catch(e) {} }
  function qs(extra){
    var u = new URLSearchParams(); new FormData(form).forEach(function(v, k){ if (v !== '') u.append(k, v); });
    if (extra) Object.keys(extra).forEach(function(k){ if (extra[k] === null) u.delete(k); else u.set(k, extra[k]); });
    return u;
  }
  function setCsv(u){ if (csv){ var c = new URLSearchParams(u); c.delete('pg'); c.set('export', '1'); csv.href = '?' + c.toString(); } }
  function load(query){
    var my = ++seq; res.style.opacity = '.55';
    fetch(base + '?' + query + '&ajax=list', {credentials: 'same-origin'}).then(function(r){ if (!r.ok) throw 0; return r.text(); }).then(function(h){
      if (my !== seq) return; res.innerHTML = h; res.style.opacity = ''; var q = new URLSearchParams(query); q.delete('ajax');
      try { history.replaceState(null, '', '?' + q.toString()); } catch(e) {} var hv = form.querySelector('[name=view]'), hp = form.querySelector('[name=pp]'); if (hv) hv.value = q.get('view') || ''; if (hp && q.get('pp')) hp.value = q.get('pp'); setCsv(q); initTree();
    }).catch(function(){ if (my === seq){ res.style.opacity = ''; form.submit(); } });
  }
  function go(extra){ var u = qs(extra); if (!extra || extra.pg === undefined) u.delete('pg'); load(u.toString()); }
  var s = form.querySelector('[name=s]');
  if (s) s.addEventListener('input', function(){ clearTimeout(timer); timer = setTimeout(function(){ go(); }, 250); });
  form.querySelectorAll('select,input[type=checkbox]').forEach(function(e){ e.addEventListener('change', function(){ go(); }); });
  form.addEventListener('submit', function(e){ e.preventDefault(); clearTimeout(timer); go(); });
  res.addEventListener('click', function(e){
    var a = e.target.closest('a.inv-nav'); if (a && !a.closest('.disabled')){ e.preventDefault(); load(a.getAttribute('href').replace(/^\?/, '')); window.scrollTo({top: Math.max(0, form.getBoundingClientRect().top + window.scrollY - 70)}); return; }
    var mb = e.target.closest('.inv-more button'); if (mb){ var box = mb.closest('.inv-lazy'); fetchRows(box); }
  });
  res.addEventListener('change', function(e){ if (e.target.id === 'invPP'){ var h = form.querySelector('[name=pp]'); h.value = e.target.value; go(); } });
  function fetchRows(box){
    var off = parseInt(box.dataset.off || '0', 10), more = box.querySelector('.inv-more'), q = qs({}); q.delete('pg'); q.set('ajax', 'cat'); q.set('c', box.dataset.c); q.set('off', String(off));
    if (box.dataset.busy) return; box.dataset.busy = '1';
    fetch(base + '?' + q.toString(), {credentials: 'same-origin'}).then(function(r){ return r.json(); }).then(function(d){
      box.querySelector('tbody').insertAdjacentHTML('beforeend', d.html); box.dataset.loaded = '1'; box.dataset.busy = '';
      if (d.next !== null){ box.dataset.off = d.next; more.hidden = false; more.querySelector('button').textContent = 'Show more (' + d.left + ' left)'; } else more.hidden = true;
    }).catch(function(){ box.dataset.busy = ''; });
  }
  function initTree(){
    var tree = document.getElementById('invTree'); if (!tree) return; var lazy = tree.dataset.lazy === '1', key = lazy ? OK_ : CK, store = rd(key);
    function isCollapsed(id){ return lazy ? store.indexOf(id) < 0 : store.indexOf(id) > -1; }
    function set(b, c){
      var box = document.getElementById(b.dataset.target); if (!box) return; box.style.display = c ? 'none' : ''; b.querySelector('i').className = 'bi ' + (c ? 'bi-chevron-right' : 'bi-chevron-down');
      var id = box.id.slice(2), k = store.indexOf(id), mark = lazy ? !c : c; if (mark && k < 0) store.push(id); if (!mark && k > -1) store.splice(k, 1);
      if (lazy && !c){ var lz = box.querySelector(':scope > .inv-lazy'); if (lz && !lz.dataset.loaded) fetchRows(lz); }
    }
    var bs = tree.querySelectorAll('.inv-toggle');
    bs.forEach(function(b){ set(b, isCollapsed(b.dataset.target.slice(2)));
      b.addEventListener('click', function(){ set(b, document.getElementById(b.dataset.target).style.display !== 'none'); wr(key, store); });
      b.closest('.d-flex').addEventListener('click', function(e){ if (e.target.closest('button') || e.target.closest('a')) return; b.click(); }); });
    var ex = document.getElementById('invExpand'), co = document.getElementById('invCollapse');
    if (ex) ex.onclick = function(){ bs.forEach(function(b){ set(b, false); }); wr(key, store); };
    if (co) co.onclick = function(){ bs.forEach(function(b){ set(b, true); }); wr(key, store); };
  }
  initTree();
})();
