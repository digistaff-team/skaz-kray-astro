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
  var link = document.querySelector('.js-back');
  if (!link || window.history.length <= 1) { return; }   // прямой заход — оставляем обычную ссылку
  link.addEventListener('click', function (e) { e.preventDefault(); window.history.back(); });
})();
</script>
