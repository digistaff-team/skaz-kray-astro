<h1>Вход жителя</h1>
<p id="tg-status" class="res-meta">Проверяем доступ через Telegram…</p>
<noscript><p class="res-flash res-flash--error">Нужен включённый JavaScript.</p></noscript>

<script src="/poselenie/assets/tg-webapp.js?v=<?= asset_ver('assets/tg-webapp.js') ?>"></script>
<script>
(function () {
  // Все переходы отсюда — location.replace, а не assign: страница-вход
  // техническая, в истории вкладки ей делать нечего. Иначе после захода по
  // диплинку из чата «Назад» на первом же экране возвращал бы сюда, а отсюда
  // тот же startapp мгновенно уводил бы вперёд — кнопка как будто не работает.
  var statusEl = document.getElementById('tg-status');
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
  window.location.replace(decodeStartParam(SkazTg.startParam(null), '/poselenie/app'));
  return;
  <?php endif; ?>

  // SDK грузится асинхронно и с таймаутом, поэтому страница отрисовывается
  // сразу, а вход не зависит от доступности telegram.org: подписанный initData
  // Telegram кладёт в саму ссылку запуска (см. assets/tg-webapp.js).
  SkazTg.ensure(function (wa) {
    var startParam = SkazTg.startParam(wa);
    var initData = SkazTg.initData(wa);

    if (!initData) {
      show('Откройте портал жителей через бота @SkazKray_bot в Telegram.');
      return;
    }
    if (wa) {
      try { wa.ready(); } catch (e) {}
      try { wa.expand(); } catch (e) {}
    }

    fetch('/poselenie/tg/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'initData=' + encodeURIComponent(initData) + '&startapp=' + encodeURIComponent(startParam)
    }).then(function (r) { return r.json().catch(function () { return { ok: false, reason: 'error' }; }); })
      .then(function (data) {
        if (data.ok && data.redirect) {
          // Перед уходом в портал один раз спрашиваем разрешение боту писать в
          // личку — без него уведомления о бронях и выдаче не дойдут. Диалог не
          // может задержать вход: askWriteAccess всегда вызывает колбэк.
          SkazTg.askWriteAccess(wa, function () { window.location.replace(data.redirect); });
          return;
        }
        if (data.reason === 'not_subscribed') { window.location.replace('/poselenie/tg/gate'); return; }
        if (data.reason === 'error') { window.location.replace('/poselenie/tg/gate?reason=error'); return; }
        if (data.reason === 'blocked') { show('Ваш доступ заблокирован. Обратитесь к администратору поселения.'); return; }
        show('Не удалось войти. Проверьте, что вы открыли портал через бота @SkazKray_bot.');
      })
      .catch(function () { window.location.replace('/poselenie/tg/gate?reason=error'); });
  });
})();
</script>
