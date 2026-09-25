<?php use SkazResidents\View; ?>
<h1>Вход в приложение</h1>
<p class="res-meta">Введите, пожалуйста, Вашу фамилию и код привязки. Код выдаёт администратор совета — он действует 7 дней и нужен только при первом входе.</p>

<?php if (!empty($error)): ?>
    <p class="res-flash res-flash--error"><?= View::e($error) ?></p>
<?php endif; ?>

<div class="res-card">
    <form class="res-form" method="post" action="/sovet/tg/claim" id="sovet-claim-form">
        <input type="hidden" name="initData" id="sovet-initdata" value="">
        <label>Фамилия
            <input type="text" name="surname" maxlength="120" autocomplete="family-name"
                   value="<?= View::e($surname) ?>" required autofocus>
        </label>
        <label>Код привязки
            <input type="text" name="code" maxlength="20" autocomplete="one-time-code"
                   autocapitalize="characters" spellcheck="false" placeholder="ABCD-EFGH" required>
        </label>
        <button class="res-btn" type="submit">Войти</button>
    </form>
    <p id="sovet-claim-note" class="res-meta" style="margin-top:.8rem;"></p>
</div>

<?php /* Эти страницы существуют только для потока входа через Telegram: заявляем
   платформу явно, иначе SkazTg не пойдёт за SDK (ссылка запуска с #tgWebAppData
   до сюда не доживает — переход делается через location.replace). */ ?>
<script>window.SkazPlatform = 'tg';</script>
<script src="/poselenie/assets/tg-webapp.js?v=<?= asset_ver('assets/tg-webapp.js') ?>"></script>
<script>
(function () {
  var field = document.getElementById('sovet-initdata');
  var note = document.getElementById('sovet-claim-note');

  // Подписанный initData берём из SDK, а если telegram.org недоступен — прямо
  // из ссылки запуска: форма должна отправляться и без SDK.
  var early = SkazTg.initData(null);
  if (early && field) { field.value = early; }

  SkazTg.ensure(function (wa) {
    var initData = SkazTg.initData(wa);
    if (initData && field) {
      if (wa) { try { wa.ready(); } catch (e) {} }
      field.value = initData;
    } else if (note) {
      note.textContent = 'Откройте раздел Совета через бота @SkazKray_bot в Telegram, иначе вход не сработает.';
    }
  });
})();
</script>
