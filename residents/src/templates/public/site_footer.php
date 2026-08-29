<?php
/**
 * Точная копия подвала внешнего сайта (Footer.astro + src/data/nav.js).
 * См. комментарий в site_header.php — синхронизировать вручную при правке nav.js.
 */
?>
<footer class="site-footer">
    <div class="wrap footer-inner">
        <div class="footer-brand">
            <span class="footer-name">Сказочный Край</span>
            <p class="footer-tag">Поселение родовых поместий<br><span class="footer-address">Краснодарский край, Северский район,<br>рядом со станицей Григорьевская</span></p>
            <div class="footer-social">
                <a href="https://vk.com/skaz.kray" target="_blank" rel="noopener" class="social-link">ВКонтакте</a>
            </div>
        </div>

        <nav class="footer-nav" aria-label="Навигация в подвале">
            <ul>
                <li><a href="/">Главная</a></li>
                <li><a href="/o-nas/">О нас</a></li>
                <li><a href="/category/novosti/">Новости</a></li>
                <li><a href="/dnevniki-pomestiy/">Дневники поместий</a></li>
                <li><a href="/category/stati/">Статьи</a></li>
                <li><a href="/kontakty/">Контакты</a></li>
            </ul>
        </nav>
    </div>

    <div class="footer-bar">
        <div class="wrap footer-bar-inner">
            <span>© 2013–2026 ПРП Сказочный Край</span>
            <a href="/kontakty/">Связаться с нами</a>
        </div>
    </div>
</footer>
