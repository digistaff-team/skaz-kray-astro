<?php use SkazResidents\View; ?>
<h1>Вход в приложение</h1>
<p class="res-meta">Введите, пожалуйста, Вашу фамилию.</p>

<?php if (!empty($error)): ?>
    <p class="res-flash res-flash--error"><?= View::e($error) ?></p>
<?php endif; ?>

<div class="res-card">
    <form class="res-form" method="post" action="/max/claim" id="sovet-claim-form">
        <input type="hidden" name="initData" id="sovet-initdata" value="">
        <label>Фамилия
            <input type="text" name="surname" maxlength="120" autocomplete="family-name"
                   value="<?= View::e($surname) ?>" required autofocus>
        </label>
        <?php if (!empty($candidates)): ?>
            <label>Уточните, кто вы
                <select name="member_id" required>
                    <option value="">— выберите —</option>
                    <?php foreach ($candidates as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"><?= View::e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <button class="res-btn" type="submit">Войти</button>
    </form>
    <p id="sovet-claim-note" class="res-meta" style="margin-top:.8rem;"></p>
</div>

<script src="/poselenie/assets/max-webapp.js?v=<?= asset_ver('assets/max-webapp.js') ?>"></script>
<script>
(function () {
  var field = document.getElementById('sovet-initdata');
  var note = document.getElementById('sovet-claim-note');

  // Подписанный initData берём из SDK, а если CDN недоступен — прямо из ссылки
  // запуска: форма должна отправляться и без SDK.
  var early = SkazMax.initData(null);
  if (early && field) { field.value = early; }

  SkazMax.ensure(function (wa) {
    var initData = SkazMax.initData(wa);
    if (initData && field) {
      field.value = initData;
    } else if (note) {
      note.textContent = 'Откройте раздел Совета через мини-приложение бота @SkazKray_bot в MAX, иначе вход не сработает.';
    }
  });
})();
</script>
