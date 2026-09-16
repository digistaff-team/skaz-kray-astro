<h1>Вход в приложение</h1>
<p id="max-status" class="res-meta">Проверяем доступ через MAX…</p>
<noscript><p class="res-flash res-flash--error">Нужен включённый JavaScript.</p></noscript>

<script src="/poselenie/assets/max-webapp.js?v=<?= asset_ver('assets/max-webapp.js') ?>"></script>
<script>
(function () {
  var statusEl = document.getElementById('max-status');
  function show(msg) { if (statusEl) statusEl.textContent = msg; }

  function decodeStart(sp, fallback) {
    if (!sp) { return fallback; }
    try {
      var b64 = sp.replace(/-/g, '+').replace(/_/g, '/');
      var pad = b64.length % 4 === 0 ? '' : '='.repeat(4 - (b64.length % 4));
      var decoded = atob(b64 + pad);
      return decoded.indexOf('/sovet') === 0 ? decoded : fallback;
    } catch (e) { return fallback; }
  }

  <?php if ($alreadyLoggedIn): ?>
  // Уже авторизованы — SDK ждать незачем: deep-link читается из самой ссылки.
  window.location.assign(decodeStart(SkazMax.startParam(null), '/sovet'));
  return;
  <?php endif; ?>

  // SDK грузится асинхронно и с таймаутом: страница рисуется сразу, а вход не
  // зависит от доступности CDN MAX (см. assets/max-webapp.js).
  SkazMax.ensure(function (wa) {
    var startParam = SkazMax.startParam(wa);
    var initData = SkazMax.initData(wa);

    if (!initData) {
      show('Откройте раздел Совета через мини-приложение бота @SkazKray_bot в MAX.');
      return;
    }

    fetch('/max/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'initData=' + encodeURIComponent(initData) + '&startapp=' + encodeURIComponent(startParam)
    }).then(function (r) { return r.json().catch(function () { return { ok: false, reason: 'error' }; }); })
      .then(function (data) {
        if (data.ok && data.redirect) { window.location.assign(data.redirect); return; }
        if (data.reason === 'need_surname') { window.location.assign('/max/familiya'); return; }
        if (data.reason === 'blocked') { show('Ваш доступ заблокирован. Обратитесь к администратору совета.'); return; }
        show('Не удалось войти. Проверьте, что вы открыли раздел через мини-приложение бота @SkazKray_bot в MAX.');
      })
      .catch(function () { show('Ошибка связи. Попробуйте ещё раз.'); });
  });
})();
</script>
