<?php
use SkazResidents\{Csrf, View};
/** @var array|null $member @var array $errors */
$m = $member ?? [];
$isEdit = !empty($m['id']);
$action = $isEdit
    ? '/poselenie/moye-pomestie/zhitel/' . (int) $m['id'] . '/redaktirovat'
    : '/poselenie/moye-pomestie/zhitel/novyy';
$val = static fn(string $k): string => View::e((string) ($m[$k] ?? ''));
$err = static function (string $k) use ($errors): string {
    return isset($errors[$k]) ? '<div class="res-flash res-flash--error">' . View::e($errors[$k]) . '</div>' : '';
};
?>
<h1><?= $isEdit ? 'Изменить данные жителя' : 'Новый житель' ?></h1>
<form class="res-form" method="post" action="<?= $action ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <label>Фамилия и имя <input type="text" name="full_name" value="<?= $val('full_name') ?>" required></label>
    <?= $err('full_name') ?>
    <label>Дата рождения <input type="text" name="birth_raw" id="mfBirth" value="<?= $val('birth_raw') ?>" placeholder="дд.мм.гггг" inputmode="numeric" maxlength="10"></label>
    <label>Телефон <input type="tel" name="phone" id="mfPhone" value="<?= $val('phone') ?>" placeholder="+7 (___) ___-__-__" inputmode="tel"></label>
    <label>Email <input type="email" name="email" value="<?= $val('email') ?>" placeholder="имя@почта.ру"></label>
    <?= $err('email') ?>
    <label>Профиль VK <input type="text" name="vk" value="<?= $val('vk') ?>" placeholder="vk.com/username" inputmode="url"></label>
    <label>Вид деятельности, навыки, таланты <textarea name="skills"><?= $val('skills') ?></textarea></label>
    <label>Общественная деятельность в поселении <textarea name="community_role"><?= $val('community_role') ?></textarea></label>
    <label>Родной город <input type="text" name="hometown" value="<?= $val('hometown') ?>"></label>
    <label>Место проживания (если ещё не переехали) <input type="text" name="residence" value="<?= $val('residence') ?>"></label>
    <label>Дата переезда <input type="text" name="moved_text" value="<?= $val('moved_text') ?>"></label>
    <label>Комментарий <textarea name="comment"><?= $val('comment') ?></textarea></label>
    <?php require __DIR__ . '/_photo_input.php'; ?>
    <button class="res-btn" type="submit"><?= $isEdit ? 'Сохранить' : 'Добавить' ?></button>
    <a class="res-btn res-btn--ghost" href="/poselenie/moye-pomestie">Отмена</a>
</form>
<?php $photoDeleteBase = '/poselenie/moye-pomestie/zhitel/' . (int) ($m['id'] ?? 0) . '/foto'; require __DIR__ . '/_photo_list.php'; ?>

<script>
(function () {
  // Дата рождения: дд.мм.гггг (только цифры, точки авто).
  function fmtDate(v) {
    var d = v.replace(/\D/g, '').slice(0, 8), o = d.slice(0, 2);
    if (d.length > 2) { o += '.' + d.slice(2, 4); }
    if (d.length > 4) { o += '.' + d.slice(4, 8); }
    return o;
  }
  // Телефон: +7 (###) ###-##-##.
  function fmtPhone(v) {
    var d = v.replace(/\D/g, '');
    if (!d) { return ''; }
    if (d[0] === '8') { d = '7' + d.slice(1); }
    if (d[0] !== '7') { d = '7' + d; }
    var p = d.slice(1, 11), s = '+7';
    if (p.length) { s += ' (' + p.slice(0, 3); }
    if (p.length >= 3) { s += ')'; }
    if (p.length > 3) { s += ' ' + p.slice(3, 6); }
    if (p.length > 6) { s += '-' + p.slice(6, 8); }
    if (p.length > 8) { s += '-' + p.slice(8, 10); }
    return s;
  }
  function bind(id, fmt) {
    var el = document.getElementById(id);
    if (!el) { return; }
    if (el.value.trim() !== '') { el.value = fmt(el.value); } // нормализуем уже сохранённое
    el.addEventListener('input', function () {
      var atEnd = el.selectionStart === el.value.length;
      el.value = fmt(el.value);
      if (atEnd) { el.setSelectionRange(el.value.length, el.value.length); }
    });
  }
  bind('mfBirth', fmtDate);
  bind('mfPhone', fmtPhone);
})();
</script>
