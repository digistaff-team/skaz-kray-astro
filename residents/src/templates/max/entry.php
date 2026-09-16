<h1>Вход жителя</h1>
<p id="max-status" class="res-meta">Проверяем доступ через MAX…</p>
<noscript><p class="res-flash res-flash--error">Нужен включённый JavaScript.</p></noscript>

<script src="/poselenie/assets/max-webapp.js?v=<?= asset_ver('assets/max-webapp.js') ?>"></script>
<script>
(function () {
  var statusEl = document.getElementById('max-status');
  function show(msg) { if (statusEl) statusEl.textContent = msg; }

  function decodeStartParam(sp, fallback) {
    if (!sp) { return fallback; }
    try {
      var b64 = sp.replace(/-/g, '+').replace(/_/g, '/');
      var pad = b64.length % 4 === 0 ? '' : '='.repeat(4 - (b64.length % 4));
      var decoded = atob(b64 + pad);
      return decoded.indexOf('/poselenie/') === 0 ? decoded : fallback;
    } catch (e) { return fallback; }
  }

  <?php if ($alreadyLoggedIn): ?>
  // Уже авторизованы — SDK ждать незачем: deep-link читается из самой ссылки.
  window.location.assign(decodeStartParam(SkazMax.startParam(null), '/poselenie/app'));
  return;
  <?php endif; ?>

  // SDK грузится асинхронно и с таймаутом: страница рисуется сразу, а вход не
  // зависит от доступности CDN MAX (см. assets/max-webapp.js).
  SkazMax.ensure(function (wa) {
    var startParam = SkazMax.startParam(wa);
    var initData = SkazMax.initData(wa);

    if (!initData) {
      show('Откройте портал жителей через мини-приложение бота @SkazKray_bot в MAX.');
      return;
    }

    fetch('/max/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'initData=' + encodeURIComponent(initData) + '&startapp=' + encodeURIComponent(startParam)
    }).then(function (r) { return r.json().catch(function () { return { ok: false, reason: 'error' }; }); })
      .then(function (data) {
        if (data.ok && data.redirect) { window.location.assign(data.redirect); return; }
        if (data.reason === 'not_subscribed') { window.location.assign('/max/gate'); return; }
        if (data.reason === 'error') { window.location.assign('/max/gate?reason=error'); return; }
        if (data.reason === 'blocked') { show('Ваш доступ заблокирован. Обратитесь к администратору поселения.'); return; }
        show('Не удалось войти. Проверьте, что вы открыли портал через мини-приложение бота @SkazKray_bot в MAX.');
      })
      .catch(function () { window.location.assign('/max/gate?reason=error'); });
  });
})();
</script>
