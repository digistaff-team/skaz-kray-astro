<?php /** Модальное окно «Уровень воды в Шебше». Открывается любым элементом с классом .js-water-open. */ ?>
<div class="water-overlay" id="waterOverlay" hidden>
    <div class="water-card" role="dialog" aria-modal="true" aria-label="Уровень воды в Шебше">
        <button type="button" class="water-close" id="waterClose" aria-label="Закрыть" title="Закрыть">&times;</button>
        <h2 class="water-title">Шебш у моста</h2>
        <!-- Содержимое подгружается при первом открытии: замер спрашивать на каждой
             странице портала не нужно (так же лениво, как картинка карты). -->
        <div class="water-content" id="waterContent"><p class="water-empty">Смотрим уровень…</p></div>
    </div>
</div>
<script>
(function () {
  var openers = document.querySelectorAll('.js-water-open');
  var overlay = document.getElementById('waterOverlay');
  var closeBtn = document.getElementById('waterClose');
  var box = document.getElementById('waterContent');
  if (!openers.length || !overlay) return;

  var loaded = false;
  function load() {
    fetch('/poselenie/uroven-vody', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.text(); })
      .then(function (html) { box.innerHTML = html; loaded = true; })
      .catch(function () {
        box.innerHTML = '<p class="water-empty">Не удалось получить замер. Проверьте связь и откройте снова.</p>';
      });
  }
  function show() {
    overlay.hidden = false;
    document.body.classList.add('water-open');
    if (!loaded) { load(); }
  }
  function hide() { overlay.hidden = true; document.body.classList.remove('water-open'); }

  Array.prototype.forEach.call(openers, function (b) { b.addEventListener('click', show); });
  closeBtn.addEventListener('click', hide);
  // Клик по тёмному фону мимо карточки — закрывает.
  overlay.addEventListener('click', function (e) { if (e.target === overlay) hide(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !overlay.hidden) hide(); });
})();
</script>
