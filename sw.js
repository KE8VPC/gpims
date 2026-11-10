// GPIMS service worker: caches only static assets. Inventory pages are never cached, so counts are always live.
var V = 'gpims-6c';
var ASSETS = ['assets/app.css', 'assets/app.js', 'assets/scan.js', 'assets/passkey.js', 'assets/icon-192.png', 'offline.html'];
self.addEventListener('install', function(e){ e.waitUntil(caches.open(V).then(function(c){ return c.addAll(ASSETS); }).then(function(){ return self.skipWaiting(); })); });
self.addEventListener('activate', function(e){ e.waitUntil(caches.keys().then(function(ks){ return Promise.all(ks.filter(function(k){ return k !== V; }).map(function(k){ return caches.delete(k); })); }).then(function(){ return self.clients.claim(); })); });
self.addEventListener('fetch', function(e){
  var r = e.request; if (r.method !== 'GET') return;
  var u = new URL(r.url); if (u.origin !== location.origin) return;
  if (r.mode === 'navigate') { e.respondWith(fetch(r).catch(function(){ return caches.match('offline.html'); })); return; }
  if (u.pathname.indexOf('/assets/') !== -1) {
    e.respondWith(caches.match(r).then(function(c){
      var f = fetch(r).then(function(res){ var cp = res.clone(); caches.open(V).then(function(ca){ ca.put(r, cp); }); return res; }).catch(function(){ return c; });
      return c || f;
    }));
  }
});
