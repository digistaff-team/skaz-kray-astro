<?php use SkazResidents\{Csrf, View}; /** @var string|null $next */ $next = $next ?? null; ?>
<h1>Вход для жителей</h1>
<?php if (!empty($error)): ?><div class="res-flash res-flash--error"><?= View::e($error) ?></div><?php endif; ?>
<form class="res-form" method="post" action="/poselenie/login">
    <?= Csrf::field() ?>
    <label>Email
        <input type="email" name="email" value="<?= View::e($old['email'] ?? '') ?>" required>
    </label>
    <label>Пароль
        <input type="password" name="password" required>
    </label>
    <button class="res-btn" type="submit">Войти</button>
</form>
<p class="res-meta">
    Нет аккаунта? <a href="/poselenie/register">Подать заявку</a>.<br>
    <a href="/poselenie/vosstanovit">Забыли пароль?</a>
</p>
<p class="res-meta">
    Заходите через Telegram или MAX? Откройте портал через бота @SkazKray_bot —
    вход произойдёт сам, пароль не нужен.
</p>

<script>
(function () {
  // Житель из мессенджера попадает сюда, когда истекла сессия, — а пароля у него
  // нет. Если мини-приложение уже запускалось в этой вкладке, его подпись запуска
  // сохранена (assets/tg-webapp.js, assets/max-webapp.js): входим по ней заново и
  // возвращаемся на страницу, где житель был.
  var get = function (k) { try { return window.sessionStorage.getItem(k) || ''; } catch (e) { return ''; } };

  // Житель нажал «Выход» сам — назад не впускаем и забываем подпись запуска.
  // Войти снова — открыть мини-приложение заново.
  if (/[?&]vyshli=1(&|$)/.test(window.location.search)) {
    try {
      window.sessionStorage.removeItem('skazTgInitData');
      window.sessionStorage.removeItem('skazMaxInitData');
    } catch (e) {}
    return;
  }

  var tg = get('skazTgInitData');
  var mx = get('skazMaxInitData');
  if (!tg && !mx) { return; }

  // Страховка от петли: если вход не удержался (например, сессия не записалась),
  // второй раз подряд не уводим — житель увидит обычную страницу с подсказкой.
  var LOOP_KEY = 'skazRelogin';
  var now = Date.now();
  if (now - (+get(LOOP_KEY) || 0) < 30000) { return; }
  try { window.sessionStorage.setItem(LOOP_KEY, String(now)); } catch (e) {}

  var next = <?= json_encode($next, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var query = '';
  if (next) {
    // base64url от UTF-8 — так же, как BotNotify::deepLink на сервере.
    var b64 = window.btoa(unescape(encodeURIComponent(next)));
    query = '?startapp=' + b64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  window.location.replace((tg ? '/poselenie/tg' : '/max') + query);
})();
</script>
