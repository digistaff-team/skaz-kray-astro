<?php
use SkazResidents\{Csrf, View};
$text   = (string) ($old['text'] ?? '');
$target = (string) ($old['target'] ?? 'all_tg');
$family = (int) ($old['family_id'] ?? 0);
$listId = (int) ($old['list_id'] ?? 0);
$name   = (string) ($old['name'] ?? '');
$err = static fn(string $k): string => isset($errors[$k]) ? '<div class="res-flash res-flash--error">' . View::e($errors[$k]) . '</div>' : '';
$e   = static fn(mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
        <label class="res-checkbox"><input type="radio" name="target" value="list"<?= $target === 'list' ? ' checked' : '' ?>> Списку адресатов</label>
    </fieldset>
    <?= $err('target') ?>

    <label>Семья (только при выборе «Одной семье»)
        <select name="family_id">
            <option value="0">— выберите семью —</option>
            <?php foreach ($targets as $t): ?>
                <option value="<?= (int) $t['id'] ?>"<?= $family === (int) $t['id'] ? ' selected' : '' ?>><?= $e($t['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <span class="res-meta">В списке — только активные семьи с привязанным Telegram (<?= count($targets) ?>).</span>

    <label>Список адресатов (только при выборе «Списку адресатов»)
        <select name="list_id">
            <option value="0">— выберите список —</option>
            <?php foreach ($lists as $l): ?>
                <option value="<?= (int) $l['id'] ?>"<?= $listId === (int) $l['id'] ? ' selected' : '' ?>><?= $e($l['name']) ?> (<?= (int) $l['members'] ?>)</option>
            <?php endforeach; ?>
        </select>
    </label>

    <button class="res-btn" type="submit">Отправить рассылку</button>
</form>

<section>
    <h2>Списки адресатов</h2>
    <p class="res-meta">Сохранённый список — набор поместий для повторной рассылки. Сообщение получат только те, у кого на момент отправки привязан Telegram.</p>

    <?php if (!$lists): ?>
        <p class="res-meta">Списков пока нет — составьте первый.</p>
    <?php else: ?>
        <ul>
            <?php foreach ($lists as $l): ?>
                <li>
                    <a href="/poselenie/moderation/rassylka/spiski/<?= (int) $l['id'] ?>"><?= $e($l['name']) ?></a>
                    — <?= (int) $l['members'] ?> адресат(ов)
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form class="res-form" method="post" action="/poselenie/moderation/rassylka/spiski/novyy">
        <?= Csrf::field() ?>
        <label>Название нового списка
            <input type="text" name="name" maxlength="120" required placeholder="Например: Дачники посёлка" value="<?= View::e($name) ?>">
        </label>
        <?= $err('name') ?>
        <button class="res-btn" type="submit">Создать список</button>
    </form>
</section>

<script>
// Выбор «Одной семье» / «Списку адресатов» показывает только нужный выпадающий
// список — без скрипта видно всё, сервер лишнее игнорирует (адресаты и так сузит выбор).
(function () {
  var form = document.querySelector('form[action="/poselenie/moderation/rassylka"]');
  if (!form) { return; }
  var familyLabel = form.querySelector('select[name="family_id"]').closest('label');
  var listLabel   = form.querySelector('select[name="list_id"]').closest('label');
  function sync() {
    var t = form.querySelector('input[name="target"]:checked').value;
    familyLabel.hidden = t !== 'family';
    listLabel.hidden   = t !== 'list';
    form.querySelector('select[name="family_id"]').required = t === 'family';
    form.querySelector('select[name="list_id"]').required   = t === 'list';
  }
  Array.prototype.forEach.call(form.querySelectorAll('input[name="target"]'), function (r) {
    r.addEventListener('change', sync);
  });
  sync();
})();
</script>