<?php
/**
 * Ссылка «Назад» на страницах разделов (инструменты, книги): уводит на предыдущую
 * страницу истории, а при прямом заходе (истории в этой вкладке нет) — по
 * адресу $backFallback, чтобы ссылка никогда не была тупиком.
 *
 * Подключается из шаблона: задать $backFallback и сделать require этого файла.
 */
use SkazResidents\View;
$backFallback = $backFallback ?? '/poselenie/app';
?>
<a class="res-back js-back" href="<?= View::e($backFallback) ?>">← Назад</a>
<script>
(function () {
  // Связываемся после разбора страницы: кроме верхней ссылки на формах бывает
  // ещё кнопка «Отменить» ниже по разметке.
  function bind() {
    if (window.history.length <= 1) { return; }   // прямой заход — оставляем обычные ссылки
    Array.prototype.forEach.call(document.querySelectorAll('.js-back'), function (link) {
      link.addEventListener('click', function (e) { e.preventDefault(); window.history.back(); });
    });
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', bind); } else { bind(); }
})();
</script>
