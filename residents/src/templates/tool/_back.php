<?php
/**
 * Ссылка «Назад» на страницах раздела инструментов: уводит на предыдущую
 * страницу истории, а при прямом заходе (истории в этой вкладке нет) — по
 * адресу $backFallback, чтобы ссылка никогда не была тупиком.
 *
 * Подключается из шаблона: задать $backFallback и сделать require этого файла.
 */
use SkazResidents\View;
$backFallback = $backFallback ?? '/poselenie/instrumenty';
?>
<a class="res-back js-tool-back" href="<?= View::e($backFallback) ?>">← Назад</a>
<script>
(function () {
  var link = document.querySelector('.js-tool-back');
  if (!link || window.history.length <= 1) { return; }   // прямой заход — оставляем обычную ссылку
  link.addEventListener('click', function (e) { e.preventDefault(); window.history.back(); });
})();
</script>
