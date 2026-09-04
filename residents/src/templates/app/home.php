<?php use SkazResidents\View; /** @var array $dash */ /** @var bool $hasCouncil */ ?>
<div class="app-wrap">
  <div class="app-head">
    <img class="app-logo" src="/poselenie/assets/icons/icon-192.png" alt="" width="52" height="52">
    <div class="app-hello">
      <b>Сказочный Край</b>
      <span>Здравствуйте, <?= View::e($me) ?></span>
    </div>
  </div>

  <div class="app-card">
    <div class="app-card-body">
      <span class="app-eyebrow">Ближайшее</span>
      <span class="app-card-title"><?= View::e($dash['meeting']['title'] ?? 'Собрание совета') ?></span>
      <span class="app-card-meta"><?= View::e($dash['meeting']['date']) ?></span>
      <span class="app-card-meta"><?= View::e($dash['meeting']['place']) ?> · <?= (int) $dash['agendaCount'] ?> <?= View::e(plural_ru((int) $dash['agendaCount'], 'вопрос', 'вопроса', 'вопросов')) ?> в повестке</span>
    </div>
    <div class="app-card-actions">
      <?php if ($hasCouncil): ?>
        <a class="app-btn app-btn--fill" href="/sovet">Повестка</a>
        <a class="app-btn app-btn--ghost" href="/sovet/zadachi">Мои задачи · <?= (int) $dash['counts']['councilMine'] ?></a>
      <?php else: ?>
        <a class="app-btn app-btn--fill" href="/sovet/vhod">Войти как член совета</a>
      <?php endif; ?>
    </div>
  </div>

  <?php $d = $dash['diary']; ?>
  <?php if ($d['count'] > 0): ?>
    <a class="app-diary" href="/poselenie">
      <b><?= View::e(diary_status_line($d)) ?></b>
      <span><?= View::e($d['latestTitle']) ?></span>
    </a>
  <?php else: ?>
    <a class="app-diary" href="/poselenie/dnevnik/novaya">
      <b>Дневник поместья пуст</b>
      <span>Добавьте первую запись</span>
    </a>
  <?php endif; ?>

  <div class="app-sec-label">Разделы</div>
  <div class="app-grid">
    <a class="app-tile" href="/poselenie"><b>Дневник<br>поместья</b><span><?= (int) $d['count'] ?> <?= View::e(plural_ru((int) $d['count'], 'запись', 'записи', 'записей')) ?></span></a>
    <a class="app-tile" href="<?= $hasCouncil ? '/sovet' : '/sovet/vhod' ?>"><b>Попечительский совет</b><span class="accent"><?= (int) $dash['counts']['councilActive'] ?> <?= View::e(plural_ru((int) $dash['counts']['councilActive'], 'задача', 'задачи', 'задач')) ?> в работе</span></a>
    <a class="app-tile" href="/poselenie/instrumenty"><b>Инструменты</b><span>свободно <?= (int) $dash['counts']['toolsFree'] ?></span></a>
    <a class="app-tile" href="/poselenie/knigi"><b>Книги</b><span>на полке <?= (int) $dash['counts']['books'] ?></span></a>
    <a class="app-tile" href="/poselenie/poezdki"><b>Поездки</b><span><?= (int) $dash['counts']['trips'] ?> <?= View::e(plural_ru((int) $dash['counts']['trips'], 'поездка', 'поездки', 'поездок')) ?></span></a>
    <a class="app-tile" href="/yarmarka"><b>Ярмарка</b><span>товары соседей</span></a>
  </div>

  <div class="app-offline-banner" id="appOffline">
    <span class="app-offline-dot"></span>
    <span>Сети нет. Показываем сохранённое на <?= View::e($savedAt) ?>, изменения уйдут при связи.</span>
  </div>
</div>

<script>
(function () {
  var b = document.getElementById('appOffline');
  function sync() { if (!b) return; b.classList.toggle('show', !navigator.onLine); }
  window.addEventListener('online', sync);
  window.addEventListener('offline', sync);
  sync();
})();
</script>
