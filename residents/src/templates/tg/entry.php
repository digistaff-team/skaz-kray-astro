<h1>Вход жителя</h1>
<p id="tg-status" class="res-meta">Проверяем доступ через Telegram…</p>
<noscript><p class="res-flash res-flash--error">Нужен включённый JavaScript.</p></noscript>

<script src="https://telegram.org/js/telegram-web-app.js"></script>
<script>
(function () {
  var statusEl = document.getElementById('tg-status');
  function show(msg) { if (statusEl) statusEl.textContent = msg; }

  var wa = window.Telegram && window.Telegram.WebApp;
  if (!wa || !wa.initData) {
    show('Откройте портал жителей через бота @SkazKray_bot в Telegram.');
    return;
  }
  try { wa.ready(); } catch (e) {}
  try { wa.expand(); } catch (e) {}

  fetch('/poselenie/tg/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'initData=' + encodeURIComponent(wa.initData)
  }).then(function (r) { return r.json().catch(function () { return { ok: false, reason: 'error' }; }); })
    .then(function (data) {
      if (data.ok && data.redirect) { window.location.assign(data.redirect); return; }
      if (data.reason === 'not_subscribed') { window.location.assign('/poselenie/tg/gate'); return; }
      if (data.reason === 'error') { window.location.assign('/poselenie/tg/gate?reason=error'); return; }
      if (data.reason === 'blocked') { show('Ваш доступ заблокирован. Обратитесь к администратору поселения.'); return; }
      show('Не удалось войти. Проверьте, что вы открыли портал через бота @SkazKray_bot.');
    })
    .catch(function () { window.location.assign('/poselenie/tg/gate?reason=error'); });
})();
</script>
