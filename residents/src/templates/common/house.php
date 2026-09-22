<?php
use SkazResidents\View;
/** @var string $bookingLink @var bool $budgetOpen @var array $protocols */
?>
<?php $backFallback = '/poselenie/app'; require __DIR__ . '/../partials/back.php'; ?>
<h1>Общий дом</h1>
<p class="res-meta">Забронируйте помещение или посмотрите статистику по расходам на содержание Общего дома</p>

<div class="house-options">
    <a class="res-card house-option" href="<?= View::e($bookingLink) ?>" id="houseBooking">
        <b>Бронирование помещений</b>
        <span class="res-meta">Проверьте расписание и забронируйте нужное помещение на свободные часы</span>
    </a>

    <?php if ($budgetOpen): ?>
        <a class="res-card house-option" href="/poselenie/byudzhet">
            <b>Отчёт о расходах</b>
            <span class="res-meta">Расходы на содержание Общего дома — учёт ведёт Попечительский совет</span>
        </a>
    <?php else: ?>
        <div class="res-card house-option house-option--off">
            <b>Отчёт о расходах</b>
            <span class="res-meta">Раздел сейчас отключён в поселении</span>
        </div>
    <?php endif; ?>
</div>

<details class="res-card sovet-acc house-protocols">
    <summary><h2>Протоколы встреч Попечительского совета Общего дома</h2></summary>
    <ul class="sovet-doclist">
        <?php foreach ($protocols as $p): ?>
            <li><a href="<?= View::e($p['href']) ?>" target="_blank" rel="noopener"><?= View::e($p['title']) ?></a></li>
        <?php endforeach; ?>
    </ul>
</details>

<script src="/poselenie/assets/tg-webapp.js?v=<?= asset_ver('assets/tg-webapp.js') ?>"></script>
<script>
// Бронирование — отдельное мини-приложение. Когда портал открыт внутри
// Telegram, ссылку надо отдавать клиенту через openTelegramLink: обычный
// переход внутри WebView либо ничего не делает, либо выкидывает во внешний
// браузер, где человек оказывается разлогиненным. Вне Telegram (браузер, PWA)
// работает обычная ссылка — поэтому href настоящий, а не «#».
(function () {
  var link = document.getElementById('houseBooking');
  if (!link || !window.SkazTg) { return; }
  SkazTg.ensure(function (wa) {
    if (!wa || typeof wa.openTelegramLink !== 'function') { return; }
    link.addEventListener('click', function (e) {
      e.preventDefault();
      try { wa.openTelegramLink(link.getAttribute('href')); }
      catch (err) { window.location.assign(link.getAttribute('href')); }
    });
  });
})();
</script>
