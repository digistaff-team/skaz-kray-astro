<?php /** Полноэкранная карта поселения с пинч-зумом. Открывается любым элементом с классом .js-map-open. */ ?>
<div class="map-overlay" id="mapOverlay" hidden>
    <button type="button" class="map-close" id="mapClose" aria-label="Закрыть карту" title="Закрыть">&times;</button>
    <div class="map-scroll" id="mapScroll">
        <img src="/poselenie/assets/karta-sk.png?v=<?= asset_ver('assets/karta-sk.png') ?>" alt="Карта поселения «Сказочный Край»" class="map-img" id="mapImg">
    </div>
</div>
<script>
(function () {
  var openers = document.querySelectorAll('.js-map-open');
  var overlay = document.getElementById('mapOverlay');
  var closeBtn = document.getElementById('mapClose');
  var scroll = document.getElementById('mapScroll');
  var img = document.getElementById('mapImg');
  if (!openers.length || !overlay) return;

  var s = 1, tx = 0, ty = 0, MIN = 1, MAX = 6;
  function apply() { img.style.transform = 'translate(' + tx + 'px,' + ty + 'px) scale(' + s + ')'; }
  function reset() { s = 1; tx = 0; ty = 0; apply(); }
  function clamp(v, a, b) { return Math.min(b, Math.max(a, v)); }
  function crect() { return scroll.getBoundingClientRect(); }

  // Масштаб вокруг точки (px,py в координатах контейнера); центр — начало отсчёта transform.
  function zoomTo(ns, px, py) {
    ns = clamp(ns, MIN, MAX);
    var r = crect(), cx = r.width / 2, cy = r.height / 2;
    var ix = (px - cx - tx) / s, iy = (py - cy - ty) / s;
    tx = px - cx - ix * ns; ty = py - cy - iy * ns;
    s = ns;
    if (s <= 1.001) { s = 1; tx = 0; ty = 0; }
    apply();
  }

  function show() { overlay.hidden = false; document.body.classList.add('map-open'); reset(); }
  function hide() { overlay.hidden = true; document.body.classList.remove('map-open'); reset(); }
  Array.prototype.forEach.call(openers, function (b) { b.addEventListener('click', show); });
  closeBtn.addEventListener('click', hide);
  // Клик по краю тёмного фона (мимо контейнера с картой) — закрывает.
  overlay.addEventListener('click', function (e) { if (e.target === overlay) hide(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !overlay.hidden) hide(); });

  // --- Сенсор: пинч-зум двумя пальцами, панорамирование одним, двойной тап ---
  var prevDist = 0, lastX = 0, lastY = 0, panning = false, lastTap = 0;
  function dist(t) { return Math.hypot(t[0].clientX - t[1].clientX, t[0].clientY - t[1].clientY); }
  function midC(t) { var r = crect(); return { x: (t[0].clientX + t[1].clientX) / 2 - r.left, y: (t[0].clientY + t[1].clientY) / 2 - r.top }; }

  scroll.addEventListener('touchstart', function (e) {
    if (e.touches.length === 2) { prevDist = dist(e.touches); }
    else if (e.touches.length === 1) {
      panning = s > 1; lastX = e.touches[0].clientX; lastY = e.touches[0].clientY;
      var now = e.timeStamp || 0;
      if (now - lastTap < 300) {           // двойной тап — приблизить/сбросить
        var r = crect();
        zoomTo(s > 1 ? 1 : 2.5, e.touches[0].clientX - r.left, e.touches[0].clientY - r.top);
        lastTap = 0;
      } else { lastTap = now; }
    }
  }, { passive: false });

  scroll.addEventListener('touchmove', function (e) {
    if (e.touches.length === 2) {
      e.preventDefault();
      var nd = dist(e.touches); if (!prevDist) { prevDist = nd; return; }
      var m = midC(e.touches);
      zoomTo(s * nd / prevDist, m.x, m.y);
      prevDist = nd;
    } else if (e.touches.length === 1 && panning) {
      e.preventDefault();
      tx += e.touches[0].clientX - lastX; ty += e.touches[0].clientY - lastY;
      lastX = e.touches[0].clientX; lastY = e.touches[0].clientY; apply();
    }
  }, { passive: false });

  scroll.addEventListener('touchend', function (e) {
    if (e.touches.length < 2) prevDist = 0;
    if (e.touches.length === 0) panning = false;
  });

  // --- Мышь (десктоп): колесо — зум к курсору, перетаскивание, двойной клик ---
  scroll.addEventListener('wheel', function (e) {
    e.preventDefault();
    var r = crect();
    zoomTo(s * (e.deltaY < 0 ? 1.15 : 1 / 1.15), e.clientX - r.left, e.clientY - r.top);
  }, { passive: false });
  var mdown = false, mx = 0, my = 0;
  scroll.addEventListener('mousedown', function (e) { if (s > 1) { mdown = true; mx = e.clientX; my = e.clientY; img.style.cursor = 'grabbing'; e.preventDefault(); } });
  window.addEventListener('mousemove', function (e) { if (mdown) { tx += e.clientX - mx; ty += e.clientY - my; mx = e.clientX; my = e.clientY; apply(); } });
  window.addEventListener('mouseup', function () { mdown = false; img.style.cursor = 'grab'; });
  scroll.addEventListener('dblclick', function (e) { var r = crect(); zoomTo(s > 1 ? 1 : 2.5, e.clientX - r.left, e.clientY - r.top); });
})();
</script>
