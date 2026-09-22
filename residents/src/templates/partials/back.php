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
  /**
   * Заход по диплинку из чата (кнопка «Открыть и посмотреть» под анонсом):
   * Mini App открывается на технической странице-входе, а та уже уводит на
   * нужный экран. Возвращаться на неё нельзя — она тут же залогинит и по тому
   * же startapp вернёт вперёд, и кнопка выглядит сломанной. Такой переход видно
   * по referrer: оставляем обычную ссылку на $backFallback.
   */
  function fromMiniAppEntry() {
    if (!document.referrer) { return false; }
    try {
      var u = new URL(document.referrer, window.location.href);
      return u.origin === window.location.origin
          && /^\/(poselenie\/tg|max|sovet\/tg)(\/|$)/.test(u.pathname);
    } catch (e) { return false; }
  }

  // Связываемся после разбора страницы: кроме верхней ссылки на формах бывает
  // ещё кнопка «Отменить» ниже по разметке.
  function bind() {
    if (window.history.length <= 1) { return; }   // прямой заход — оставляем обычные ссылки
    if (fromMiniAppEntry()) { return; }
    Array.prototype.forEach.call(document.querySelectorAll('.js-back'), function (link) {
      link.addEventListener('click', function (e) { e.preventDefault(); window.history.back(); });
    });
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', bind); } else { bind(); }
})();
</script>
