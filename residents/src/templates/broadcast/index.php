<?php
use SkazResidents\{Csrf, View};
$text   = (string) ($old['text'] ?? '');
$target = (string) ($old['target'] ?? 'all_tg');
$family = (int) ($old['family_id'] ?? 0);
$err = static fn(string $k): string => isset($errors[$k]) ? '<div class="res-flash res-flash--error">' . View::e($errors[$k]) . '</div>' : '';
?>
<?php $backFallback = '/poselenie/moderation'; require __DIR__ . '/../partials/back.php'; ?>
<h1>Рассылка в Telegram</h1>

<p class="res-meta">Сообщение уйдёт от @SkazKray_bot тем, кто открывал с ботом диалог. У кого диалога нет — Telegram ответит ошибкой, такие случаи попадут в лог сервера.</p>

<form class="res-form" method="post" action="/poselenie/moderation/rassylka">
    <?= Csrf::field() ?>

    <label>Текст сообщения
        <textarea name="text" maxlength="4000" required placeholder="Текст рассылки…"><?= View::e($text) ?></textarea>
    </label>
    <?= $err('text') ?>

    <fieldset class="delivery-kind">
        <legend>Кому</legend>
        <label class="res-checkbox"><input type="radio" name="target" value="all_tg"<?= $target === 'all_tg' ? ' checked' : '' ?>> Всем с Telegram</label>
        <label class="res-checkbox"><input type="radio" name="target" value="all"<?= $target === 'all' ? ' checked' : '' ?>> Всем</label>
        <label class="res-checkbox"><input type="radio" name="target" value="family"<?= $target === 'family' ? ' checked' : '' ?>> Одной семье</label>
    </fieldset>
    <?= $err('target') ?>

    <label>Семья (только при выборе «Одной семье»)
        <select name="family_id">
            <option value="0">— выберите семью —</option>
            <?php foreach ($targets as $t): ?>
                <option value="<?= (int) $t['id'] ?>"<?= $family === (int) $t['id'] ? ' selected' : '' ?>><?= htmlspecialchars((string) $t['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <span class="res-meta">В списке — только активные семьи с привязанным Telegram (<?= count($targets) ?>).</span>

    <button class="res-btn" type="submit">Отправить рассылку</button>
</form>

<script>
// Выбор «Одной семье» показывает только выпадающий список — без скрипта видно всё,
// сервер лишнее игнорирует (список адресатов и так сузит выбор).
(function () {
  var form = document.querySelector('form[action="/poselenie/moderation/rassylka"]');
  if (!form) { return; }
  var select = form.querySelector('select[name="family_id"]');
  if (!select) { return; }
  var label = select.closest('label');
  function sync() {
    var one = form.querySelector('input[name="target"]:checked').value === 'family';
    if (label) { label.hidden = !one; }
    select.required = one;
  }
  Array.prototype.forEach.call(form.querySelectorAll('input[name="target"]'), function (r) {
    r.addEventListener('change', sync);
  });
  sync();
})();
</script>