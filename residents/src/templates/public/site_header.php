<?php
/**
 * Точная копия шапки внешнего сайта (Header.astro + src/data/nav.js).
 * Используется ТОЛЬКО публичными страницами раздела жителей (дневники),
 * чтобы гость, пришедший с сайта, не замечал перехода в другое приложение.
 * При изменении nav.js на сайте — синхронизировать пункты меню здесь вручную.
 * $navActive (опц.) — slug текущего верхнего пункта для aria-current: 'stati'|'yarmarka'.
 */
$navActive = $navActive ?? null;
?>
<header class="site-header">
    <div class="wrap header-inner">
        <a href="/" class="brand" aria-label="Сказочный Край — на главную">
            <img src="/images/Logo_SK_204x204.png" alt="" width="60" height="60" class="brand-logo">
            <span class="brand-text">
                <span class="brand-name">Сказочный Край</span>
                <span class="brand-tag">Поселение родовых поместий</span>
            </span>
        </a>

        <input type="checkbox" id="nav-toggle" class="nav-toggle" aria-hidden="true">
        <label for="nav-toggle" class="nav-burger" aria-label="Меню"><span></span><span></span><span></span></label>

        <nav class="main-nav" aria-label="Основная навигация">
            <ul class="nav-list">
                <li><a href="/">Главная</a></li>
                <li class="has-children">
                    <a href="/o-nas/">О нас</a>
                    <ul class="submenu">
                        <li><a href="/o-nas/obraz-poseleniya/">Образ поселения</a></li>
                        <li><a href="/o-nas/plan-poseleniya/">План поселения</a></li>
                        <li><a href="/o-nas/pravila/">Правила</a></li>
                        <li><a href="/o-nas/novichkam/">Новичкам</a></li>
                        <li><a href="/o-nas/chastye-voprosy/">Частые вопросы</a></li>
                    </ul>
                </li>
                <li><a href="/category/novosti/">Новости</a></li>
                <li class="has-children">
                    <a href="/category/stati/"<?= $navActive === 'stati' ? ' aria-current="page"' : '' ?>>Статьи</a>
                    <ul class="submenu">
                        <li><a href="/dnevniki-pomestiy/">Дневники поместий</a></li>
                        <li><a href="/category/stati/cuisine/">Сказочная кухня</a></li>
                        <li><a href="/category/stati/skazochnye-reportazhi/">«Сказочные» репортажи</a></li>
                        <li><a href="/category/stati/kopilka-znanij/">Копилка знаний</a></li>
                    </ul>
                </li>
                <li><a href="/yarmarka/"<?= $navActive === 'yarmarka' ? ' aria-current="page"' : '' ?>>Ярмарка</a></li>
                <li><a href="/kontakty/">Контакты</a></li>
            </ul>
        </nav>
    </div>
</header>
