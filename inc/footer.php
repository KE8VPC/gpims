</main><footer class="text-center text-muted small my-5">GPIMS · General Purpose Inventory Management System · v<?=h(APP_VERSION)?></footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/app.js?v=<?=APP_VERSION?>"></script>
<script src="assets/scan.js?v=<?=APP_VERSION?>"></script>
<script>if("serviceWorker" in navigator && location.protocol==="https:")navigator.serviceWorker.register("sw.js").catch(function(){});window.addEventListener("beforeinstallprompt",function(e){e.preventDefault();window.GP_INSTALL=e;document.dispatchEvent(new Event("gp-install"));});</script></body></html>
