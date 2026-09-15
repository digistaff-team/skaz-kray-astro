<?php use SkazResidents\{View, CouncilData}; ?>
<section class="sovet-hero">
    <p class="sovet-eyebrow">Внутренний портал</p>
    <h1>Попечительский совет Общего дома</h1>
    <p class="sovet-lead">Положение, протоколы и правила, состав совета, ближайшее собрание и живой список текущих задач.</p>
</section>

<?php if (!empty($me)): ?>
    <p class="sovet-whoami">Вы вошли как <strong><?= View::e($me['name']) ?></strong> — <?= View::e(CouncilData::roleLabel($me)) ?></p>
<?php endif; ?>

<!-- Приглашение установить ярлык на главный экран. Скрыт по умолчанию —
     показывается скриптом только когда это уместно (см. ниже). -->
<div id="sovet-a2hs" class="sovet-a2hs" hidden>
    <span class="sovet-a2hs-ico" aria-hidden="true">📲</span>
    <div class="sovet-a2hs-body">
        <b>Установите «Совет» на главный экран</b>
        <span id="sovet-a2hs-hint">Быстрый доступ в один тап, как обычное приложение.</span>
    </div>
    <button type="button" id="sovet-a2hs-add" class="res-btn sovet-a2hs-add">Добавить</button>
    <button type="button" id="sovet-a2hs-close" class="sovet-a2hs-close" aria-label="Скрыть приглашение">×</button>
</div>

<div class="res-card">
    <div class="sovet-card-head">
        <h2>Встреча Совета</h2>
        <?php if (!empty($canEditMeeting)): ?>
            <a class="res-link-btn" href="/sovet/vstrecha">Редактировать</a>
        <?php endif; ?>
    </div>
    <p class="sovet-meet-date"><?= View::e($nextMeeting['date']) ?></p>
    <p class="res-meta"><?= View::e($nextMeeting['place']) ?></p>
    <p class="res-meta">
        Дежурный председатель: <strong><?= View::e($nextMeeting['dutyChair']) ?></strong><br>
        Дежурный секретарь: <strong><?= View::e($nextMeeting['dutySecretary']) ?></strong>
    </p>
    <p><button type="button" id="agenda-open" class="sovet-agenda-trigger">Повестка встречи</button></p>

    <dialog id="agenda-dialog" class="sovet-dialog">
        <h3 class="sovet-h3">Повестка встречи</h3>
        <?php if (empty($agendaItems)): ?>
            <p class="res-meta">Повестка пока пуста. Добавьте свои пункты.</p>
        <?php else: ?>
            <div class="sovet-agenda">
                <?php foreach ($agendaItems as $it): ?>
                    <p<?= !empty($it['discussed']) ? ' class="is-discussed"' : '' ?>><?= View::e($it['title']) ?><?php if ($it['author'] !== ''): ?> <span class="sovet-agenda-author">— <?= View::e($it['author']) ?></span><?php endif; ?><?php if (!empty($it['carried_over'])): ?> <span class="sovet-agenda-carried">с прошлой встречи</span><?php endif; ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="sovet-dialog-actions">
            <a class="res-btn" href="/sovet/povestka">Добавить / изменить пункты</a>
            <form method="dialog" style="display:inline;"><button class="res-btn res-btn--ghost" type="submit">Закрыть</button></form>
        </div>
    </dialog>
    <script>
    (function () {
        var btn = document.getElementById('agenda-open');
        var dlg = document.getElementById('agenda-dialog');
        if (!btn || !dlg || !dlg.showModal) { return; }
        btn.addEventListener('click', function () { dlg.showModal(); });
        dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
    })();
    </script>
</div>

<div class="sovet-actions">
    <a class="res-btn sovet-action" href="/sovet/napravleniya"><span>Направления работы</span><span class="sovet-action-n"><?= (int) $directionsCount ?></span></a>
    <a class="res-btn res-btn--ghost sovet-action" href="/sovet/zadachi"><span>Текущие задачи</span><span class="sovet-action-n"><?= (int) $activeCount ?></span></a>
</div>

<details class="res-card sovet-acc">
    <summary><h2>Документы</h2></summary>
    <ul class="sovet-doclist">
        <?php foreach ($documents as $d): ?>
            <li>
                <a href="<?= View::e($d['href']) ?>" target="_blank" rel="noopener"><?= View::e($d['title']) ?></a>
            </li>
        <?php endforeach; ?>
        <li>
            <a href="/sovet/buhgalteriya">Бюджет Общего дома — приход, расход, остатки</a>
        </li>
    </ul>
</details>

<details class="res-card sovet-acc">
    <summary><h2>Протоколы встреч</h2></summary>
    <ul class="sovet-doclist">
        <?php foreach ($protocols as $p): ?>
            <li><a href="<?= View::e($p['href']) ?>" target="_blank" rel="noopener"><?= View::e($p['title']) ?></a></li>
        <?php endforeach; ?>
    </ul>
</details>

<details class="res-card sovet-acc">
    <summary><h2>Состав Попечительского совета Общего дома</h2></summary>
    <ul class="sovet-roster sovet-roster--cols">
        <?php foreach ($members as $m): ?>
            <li><span class="sovet-roster-name"><?= View::e($m['name']) ?></span></li>
        <?php endforeach; ?>
    </ul>
</details>

<!-- SDK Telegram нужен, чтобы внутри мини-приложения работали
     checkHomeScreenStatus/addToHomeScreen. В обычном браузере он безвреден. -->
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<script>
/**
 * Баннер «Добавить на главный экран».
 *  • Внутри Telegram: показываем по checkHomeScreenStatus (added/unsupported → прячем),
 *    добавляем через addToHomeScreen(), прячем по событию homeScreenAdded.
 *  • В браузере: показываем по beforeinstallprompt (в Chrome не сработает, если уже
 *    установлено), прячем по appinstalled и в display-mode: standalone.
 *  • iOS Safari: программной установки нет — показываем короткую инструкцию.
 *  • Ручное закрытие запоминаем в localStorage.
 */
(function () {
  var KEY = 'sovet_a2hs_dismissed';
  var box = document.getElementById('sovet-a2hs');
  if (!box) { return; }
  var addBtn = document.getElementById('sovet-a2hs-add');
  var closeBtn = document.getElementById('sovet-a2hs-close');
  var hint = document.getElementById('sovet-a2hs-hint');

  function dismissed() { try { return localStorage.getItem(KEY) === '1'; } catch (e) { return false; } }
  function hide() { box.hidden = true; }
  function show() { if (!dismissed()) { box.hidden = false; } }
  function dismissForever() { try { localStorage.setItem(KEY, '1'); } catch (e) {} hide(); }

  closeBtn.addEventListener('click', dismissForever);
  if (dismissed()) { return; }

  var wa = window.Telegram && window.Telegram.WebApp;
  var inTelegram = !!(wa && wa.initData && wa.initData.length);

  if (inTelegram) {
    var supported = wa.isVersionAtLeast && wa.isVersionAtLeast('8.0') && typeof wa.checkHomeScreenStatus === 'function';
    if (!supported) { return; } // старый клиент Telegram — используйте меню «⋮»
    addBtn.addEventListener('click', function () { try { wa.addToHomeScreen(); } catch (e) {} });
    try { wa.onEvent('homeScreenAdded', hide); } catch (e) {}
    wa.checkHomeScreenStatus(function (status) {
      if (status === 'added' || status === 'unsupported') { hide(); return; }
      show(); // missed / unknown
    });
    return;
  }

  // Обычный браузер.
  var standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
    || window.navigator.standalone === true;
  if (standalone) { return; } // уже запущено из установленной иконки

  var deferred = null;
  window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferred = e; show(); });
  window.addEventListener('appinstalled', dismissForever);
  addBtn.addEventListener('click', function () {
    if (!deferred) { return; }
    deferred.prompt();
    deferred.userChoice.then(function () { deferred = null; hide(); });
  });

  // iOS Safari: beforeinstallprompt не поддерживается — показываем инструкцию.
  var ua = navigator.userAgent || '';
  if (/iP(hone|ad|od)/.test(ua) && /Safari/.test(ua) && !/(CriOS|FxiOS|EdgiOS)/.test(ua)) {
    if (hint) { hint.textContent = 'В Safari: «Поделиться» → «На экран „Домой“».'; }
    if (addBtn) { addBtn.hidden = true; }
    show();
  }
})();
</script>
