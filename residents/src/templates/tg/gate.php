<?php use SkazResidents\View; ?>
<h1>Доступ для жителей поселения</h1>

<?php if ($reason === 'error'): ?>
    <div class="res-flash res-flash--error">Не удалось проверить подписку на группу жителей. Попробуйте позже или обратитесь к администратору поселения.</div>
<?php else: ?>
    <p>Внутренний портал доступен только участникам группы жителей в Telegram. Вступите в группу и вернитесь.</p>
<?php endif; ?>

<?php if (!empty($groupLink) && strpos($groupLink, 'CHANGE_ME') === false): ?>
    <p><a class="res-btn" id="tg-group-link" href="<?= View::e($groupLink) ?>" target="_blank" rel="noopener">Вступить в группу жителей</a></p>
<?php endif; ?>
<p><a class="res-btn res-btn--ghost" href="/poselenie/tg">Я вступил(а) — проверить снова</a></p>

<script src="https://telegram.org/js/telegram-web-app.js"></script>
<script>
// Внутри Telegram ссылку на группу открываем нативно, а не новой вкладкой браузера.
(function () {
  var wa = window.Telegram && window.Telegram.WebApp;
  var link = document.getElementById('tg-group-link');
  if (wa && link) {
    link.addEventListener('click', function (e) {
      var url = link.getAttribute('href');
      if (wa.openTelegramLink) { e.preventDefault(); wa.openTelegramLink(url); }
      else if (wa.openLink) { e.preventDefault(); wa.openLink(url); }
    });
  }
})();
</script>
