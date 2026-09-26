<?php
/** Вкладки «Поездки | Доставка». $tab — 'poezdki' | 'dostavka'. Без раздела доставки вкладок нет. */
use SkazResidents\Sections;
if (!Sections::isEnabled('dostavka')) { return; }
?>
<nav class="trip-tabs" aria-label="Поездки и доставка">
    <a href="/poselenie/poezdki"<?= $tab === 'poezdki' ? ' aria-current="page"' : '' ?>>Поездки</a>
    <a href="/poselenie/dostavka"<?= $tab === 'dostavka' ? ' aria-current="page"' : '' ?>>Доставка</a>
</nav>
