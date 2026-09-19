<?php
/**
 * Ссылки t.me на странице: внутри Telegram Mini App их надо открывать через
 * openTelegramLink — обычная ссылка target=_blank в webview не срабатывает.
 * Вне Telegram (или если SDK недоступен) остаются обычными ссылками.
 */
?>
<script src="/poselenie/assets/tg-webapp.js?v=<?= asset_ver('assets/tg-webapp.js') ?>"></script>
<script>
(function () {
  if (typeof SkazTg === 'undefined') { return; }
  SkazTg.ensure(function (wa) {
    if (!wa || !wa.initData || !wa.openTelegramLink) { return; }
    Array.prototype.forEach.call(document.querySelectorAll('.js-tg-link'), function (a) {
      a.addEventListener('click', function (e) { e.preventDefault(); wa.openTelegramLink(a.href); });
    });
  });
})();
</script>
