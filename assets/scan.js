(function(){
  var modalEl, modal, stream, raf, h5, cb, done;
  var FORMATS = ['qr_code','code_128','code_39','ean_13','ean_8','upc_a','upc_e','itf','codabar'];
  function $(id){ return document.getElementById(id); }
  function msg(t){ $('gpScanMsg').textContent = t; }
  function build(){
    if (modalEl) return;
    modalEl = document.createElement('div'); modalEl.className = 'modal fade'; modalEl.tabIndex = -1;
    modalEl.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Scan barcode / QR code</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><video id="gpVideo" playsinline muted class="w-100 rounded bg-dark" style="display:none"></video><div id="gpH5" style="display:none"></div><div id="gpScanMsg" class="small text-muted mt-2">Starting camera…</div><div class="input-group mt-3"><input class="form-control" id="gpManual" placeholder="Or type / paste a code"><button type="button" class="btn btn-primary" id="gpManualGo">Use</button></div></div></div></div>';
    document.body.appendChild(modalEl); modal = new bootstrap.Modal(modalEl);
    modalEl.addEventListener('hidden.bs.modal', stop);
    $('gpManualGo').onclick = function(){ var v = $('gpManual').value.trim(); if (v) found(v); };
    $('gpManual').addEventListener('keydown', function(e){ if (e.key === 'Enter'){ e.preventDefault(); $('gpManualGo').click(); } });
  }
  function stop(){
    if (raf) cancelAnimationFrame(raf); raf = null;
    if (stream){ stream.getTracks().forEach(function(t){ t.stop(); }); stream = null; }
    if (h5){ var x = h5; h5 = null; try { x.stop().then(function(){ x.clear(); }).catch(function(){}); } catch(e) { try { x.clear(); } catch(e2) {} } }
  }
  function found(v, fmt){ if (done) return; done = true; try { stop(); } catch(e) {} try { modal.hide(); } catch(e) {} if (navigator.vibrate) navigator.vibrate(60); cb(v, fmt); }
  function fail(e){ msg((window.isSecureContext ? 'Camera unavailable' : 'Camera needs HTTPS') + ' — type the code below instead.'); }
  function startNative(det){
    navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}}, audio:false}).then(function(s){
      stream = s; var v = $('gpVideo'); v.style.display = 'block'; v.srcObject = s; v.play(); msg('Point the camera at a code…');
      var last = 0;
      (function loop(t){ if (!stream) return; if (t - last > 200){ last = t; det.detect(v).then(function(r){ if (r && r.length) found(r[0].rawValue, r[0].format); }).catch(function(){}); } raf = requestAnimationFrame(loop); })(0);
    }).catch(fail);
  }
  function startH5(){
    var go = function(){ var el = $('gpH5'); el.style.display = 'block'; var F = Html5QrcodeSupportedFormats; h5 = new Html5Qrcode('gpH5', {formatsToSupport:[F.QR_CODE,F.CODE_128,F.CODE_39,F.EAN_13,F.EAN_8,F.UPC_A,F.UPC_E,F.ITF,F.CODABAR]});
      h5.start({facingMode:'environment'}, {fps:10, qrbox:function(vw,vh){ return {width:Math.floor(Math.min(vw*0.9,340)), height:Math.floor(Math.min(vh*0.6,220))}; }}, function(t, res){ found(t, res && res.result && res.result.format && res.result.format.formatName); }, function(){}).then(function(){ msg('Point the camera at a code…'); }).catch(fail); };
    if (window.Html5Qrcode) return go();
    var s = document.createElement('script'); s.src = 'https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js'; s.onload = go; s.onerror = fail; document.head.appendChild(s);
  }
  window.GPScan = function(callback){
    build(); cb = callback; done = false; $('gpManual').value = ''; msg('Starting camera…'); modal.show();
    if (!window.isSecureContext || !navigator.mediaDevices) return fail();
    if ('BarcodeDetector' in window && BarcodeDetector.getSupportedFormats){
      BarcodeDetector.getSupportedFormats().then(function(sup){ var f = FORMATS.filter(function(x){ return sup.indexOf(x) > -1; }); f.length ? startNative(new BarcodeDetector({formats:f})) : startH5(); }).catch(startH5);
    } else startH5();
  };
  document.querySelectorAll('[data-scan-target]').forEach(function(b){ b.addEventListener('click', function(){ GPScan(function(v){ var t = $(b.dataset.scanTarget); t.value = v; t.dispatchEvent(new Event('input')); }); }); });
  document.querySelectorAll('[data-scan-go]').forEach(function(b){ b.addEventListener('click', function(){ var mi = b.dataset.scanMode ? document.querySelector('input[name="' + b.dataset.scanMode + '"]:checked') : null; var mode = mi ? mi.value : ''; GPScan(function(v,f){ location.href = 'scan.php?code=' + encodeURIComponent(v) + (f ? '&fmt=' + encodeURIComponent(f) : '') + (mode === 'add' ? '&mode=add' : ''); }); }); });
})();
window.GPCodeType = function(f){
  f = String(f || '').toLowerCase().replace(/[^a-z0-9]/g, ''); if (!f) return '';
  var m = {qrcode:'qr',code128:'code128',code39:'code39',ean13:'ean13',ean8:'ean8',upca:'upca',upce:'upce',itf:'itf',codabar:'codabar'};
  return m[f] || 'other';
};
// Read a barcode / QR code from a photo (a picture file the person picked or just took).
window.GPScanFile = function(file, ok, bad){
  var fail = function(){ if (bad) bad(); };
  var viaH5 = function(){
    var go = function(){ var d = document.getElementById('gpH5file'); if (!d){ d = document.createElement('div'); d.id = 'gpH5file'; d.style.display = 'none'; document.body.appendChild(d); }
      var q = new Html5Qrcode('gpH5file'); q.scanFile(file, false).then(function(t){ try { q.clear(); } catch(e) {} ok(t, ''); }).catch(function(){ try { q.clear(); } catch(e) {} fail(); }); };
    if (window.Html5Qrcode) return go();
    var s = document.createElement('script'); s.src = 'https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js'; s.onload = go; s.onerror = fail; document.head.appendChild(s);
  };
  if ('BarcodeDetector' in window && BarcodeDetector.getSupportedFormats && window.createImageBitmap){
    BarcodeDetector.getSupportedFormats().then(function(sup){
      var f = ['qr_code','code_128','code_39','ean_13','ean_8','upc_a','upc_e','itf','codabar'].filter(function(x){ return sup.indexOf(x) > -1; }); if (!f.length) return viaH5();
      createImageBitmap(file).then(function(bm){ return new BarcodeDetector({formats:f}).detect(bm); }).then(function(r){ if (r && r.length) ok(r[0].rawValue, r[0].format); else viaH5(); }).catch(viaH5);
    }).catch(viaH5);
  } else viaH5();
};
