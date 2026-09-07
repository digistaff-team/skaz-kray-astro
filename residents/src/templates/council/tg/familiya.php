<?php use SkazResidents\View; ?>
<h1>Вход в Попечительский совет</h1>
<p class="res-meta">Вы входите в первый раз. Укажите свою фамилию для идентификации.</p>

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

<script src="https://telegram.org/js/telegram-web-app.js"></script>
<script>
(function () {
  var wa = window.Telegram && window.Telegram.WebApp;
  var field = document.getElementById('sovet-initdata');
  var note = document.getElementById('sovet-claim-note');
  if (wa && wa.initData) {
    try { wa.ready(); } catch (e) {}
    field.value = wa.initData;
  } else if (note) {
    note.textContent = 'Откройте раздел Совета через бота @SkazKray_bot в Telegram, иначе вход не сработает.';
  }
})();
</script>
