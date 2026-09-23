<?php
/**
 * Навигация вверху страницы: «← Назад» и «На главную».
 *
 * Правило одно на весь портал: с любой страницы можно уйти либо на предыдущую,
 * либо сразу на главную. Подписи при этом не врут — «Назад» показывается только
 * когда назад действительно есть куда:
 *
 *  - история во вкладке есть  → «← Назад» (history.back) и рядом «На главную»;
 *  - истории нет (прямой заход, диплинк из чата, запуск Mini App) → только
 *    «На главную», ссылка «Назад» убирается скриптом.
 *
 * $backFallback — куда вести «Назад» до того, как отработает скрипт, и если
 * скриптов нет вовсе: обычно это список раздела, то есть логичный «уровень
 * выше». $homeUrl — главная своего портала (у Совета она своя).
 *
 * Подключается из шаблона: задать $backFallback и сделать require этого файла.
 */
use SkazResidents\View;
$backFallback = $backFallback ?? '/poselenie/app';
$homeUrl = $homeUrl ?? '/poselenie/app';
?>
<div class="res-back-row">
    <a class="res-back js-back js-back-top" href="<?= View::e($backFallback) ?>">← Назад</a>
    <a class="res-back" href="<?= View::e($homeUrl) ?>">На главную</a>
</div>
<script>
(function () {
  // Связываемся после разбора страницы: кроме верхней ссылки на формах бывает
  // ещё кнопка «Отменить» ниже по разметке.
  function bind() {
    if (window.history.length <= 1 || fromMiniAppEntry()) {
      // Возвращаться некуда — убираем «Назад», чтобы подпись не обещала лишнего.
      // Остальные .js-back (например «Отменить» в форме) остаются обычными
      // ссылками: у них свой осмысленный адрес.
      var top = document.querySelector('.js-back-top');
      if (top) { top.remove(); }
      return;
    }
    Array.prototype.forEach.call(document.querySelectorAll('.js-back'), function (link) {
      link.addEventListener('click', function (e) { e.preventDefault(); window.history.back(); });
    });
  }

  /**
   * Заход по диплинку из чата (кнопка «Открыть и посмотреть» под анонсом):
   * Mini App открывается на технической странице-входе, а та уже уводит на
   * нужный экран. Возвращаться на неё нельзя — она тут же залогинит и по тому
   * же startapp вернёт вперёд. Такой переход видно по referrer.
   */
  function fromMiniAppEntry() {
    if (!document.referrer) { return false; }
    try {
      var u = new URL(document.referrer, window.location.href);
      return u.origin === window.location.origin
          && /^\/(poselenie\/tg|max|sovet\/tg)(\/|$)/.test(u.pathname);
    } catch (e) { return false; }
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', bind); } else { bind(); }
})();
</script>
