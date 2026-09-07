<h1>Вход в приложение</h1>
<p id="tg-status" class="res-meta">Проверяем доступ через Telegram…</p>
<noscript><p class="res-flash res-flash--error">Нужен включённый JavaScript.</p></noscript>

<script src="https://telegram.org/js/telegram-web-app.js"></script>
<script>
(function () {
  var statusEl = document.getElementById('tg-status');
  function show(msg) { if (statusEl) statusEl.textContent = msg; }

  var wa = window.Telegram && window.Telegram.WebApp;

  <?php if ($alreadyLoggedIn): ?>
  window.location.assign('/sovet');
  return;
  <?php endif; ?>

  if (!wa || !wa.initData) {
    show('Откройте раздел Совета через бота @SkazKray_bot в Telegram.');
    return;
  }
  try { wa.ready(); } catch (e) {}
  try { wa.expand(); } catch (e) {}

  fetch('/sovet/tg/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'initData=' + encodeURIComponent(wa.initData)
  }).then(function (r) { return r.json().catch(function () { return { ok: false, reason: 'error' }; }); })
    .then(function (data) {
      if (data.ok && data.redirect) { window.location.assign(data.redirect); return; }
      if (data.reason === 'need_surname') { window.location.assign('/sovet/tg/familiya'); return; }
      if (data.reason === 'blocked') { show('Ваш доступ заблокирован. Обратитесь к администратору совета.'); return; }
      show('Не удалось войти. Проверьте, что вы открыли раздел через бота @SkazKray_bot.');
    })
    .catch(function () { show('Ошибка связи. Попробуйте ещё раз.'); });
})();
</script>
