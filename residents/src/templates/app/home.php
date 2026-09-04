<?php use SkazResidents\View; /** @var array $dash */ ?>
<div class="app-wrap">
  <div class="app-head">
    <img class="app-logo" src="/poselenie/assets/icons/icon-192.png" alt="" width="52" height="52">
    <div class="app-hello">
      <b>Сказочный Край</b>
      <span><?= View::e($me) ?></span>
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
      <b>Дневник нашего поместья</b>
      <span>Добавьте первую запись</span>
    </a>
  <?php endif; ?>

  <?php if (!empty($dash['otherDiaries'])): ?>
    <div class="app-sec-label">Новое в дневниках соседей</div>
    <?php foreach ($dash['otherDiaries'] as $od): ?>
      <a class="app-diary app-diary--other" href="/poselenie/dnevniki/<?= (int) $od['id'] ?>">
        <b><?= View::e($od['family']) ?></b>
        <span><?= View::e($od['title']) ?></span>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="app-sec-label">Разделы</div>
  <div class="app-grid">
    <a class="app-tile" href="/poselenie/dnevniki"><b>Дневники<br>поместий</b><span>лента поселения</span></a>
    <a class="app-tile" href="/poselenie/instrumenty"><b>Инструменты</b><span>свободно <?= (int) $dash['counts']['toolsFree'] ?></span></a>
    <a class="app-tile" href="/poselenie/knigi"><b>Книги</b><span>на полке <?= (int) $dash['counts']['books'] ?></span></a>
    <a class="app-tile" href="/poselenie/poezdki"><b>Поездки</b><span><?= (int) $dash['counts']['trips'] ?> <?= View::e(plural_ru((int) $dash['counts']['trips'], 'поездка', 'поездки', 'поездок')) ?></span></a>
    <a class="app-tile" href="/poselenie/byudzhet"><b>Бюджет<br>Общего дома</b><span>приход и расход</span></a>
    <a class="app-tile" href="/yarmarka"><b>Ярмарка</b><span>товары соседей</span></a>
  </div>

  <div class="app-offline-banner" id="appOffline">
    <span class="app-offline-dot"></span>
    <span>Сети нет. Показываем сохранённое на <?= View::e($savedAt) ?>, изменения уйдут при связи.</span>
  </div>

  <a class="app-logout" href="/poselenie/vyhod">Выход</a>
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
