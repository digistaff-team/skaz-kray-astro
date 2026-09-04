# Мобильный PWA «Сказочный Край» (v1) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Мобильный лаунчер `/poselenie/app` в стиле утверждённого макета + PWA-слой (manifest, service worker, офлайн-чтение), интегрированный в существующий раздел жителей. Разделы переиспользуют текущие страницы.

**Architecture:** Гибрид C. Новая server-rendered PHP-страница-лаунчер со счётчиками из существующих репозиториев (агрегатор `AppDashboard`, только чтение). PWA: `manifest.webmanifest` + `sw.js` отдаёт тонкий `PwaController` (без правок nginx); иконки — статикой под `public/assets/icons/`. SW scope `/poselenie/`, офлайн только чтение. Действия — через существующие контроллеры, нового API нет.

**Tech Stack:** PHP 8.3 + PDO, PHPUnit 11 (SQLite in-memory), существующие `Auth`/`CouncilAuth`/`View`/`CouncilData` и репозитории; клиентский JS — только регистрация SW + online/offline-баннер; sharp (node) для генерации иконок из логотипа.

---

## Ключевые соглашения (прочитать перед стартом)

- **Локально PHP/vendor нет.** Тесты и `php -l` гоняются на сервере во временном чекауте:
  `git archive HEAD:residents | ssh abconsult 'tar -x -C /root/ledger-test'` затем
  `ssh abconsult 'cd /root/ledger-test && ([ -f vendor/bin/phpunit ] || php8.3 /root/composer.phar install --no-interaction -q) && php8.3 vendor/bin/phpunit'`.
  (Есть готовый скрипт харнесса из модуля бюджета; если каталог удалён — composer install отработает заново.)
- **Гарды:** лаунчер и офлайн-страница — `Auth::requireLogin()`. `manifest`/`sw.js`/иконки — публичны (без ПДн).
- **Стиль:** фирменные токены (`--green #008757`, `--ochre #c98a3a`, `--paper #fbfaf5`, PT Serif/PT Sans), как в `residents.css`. Мобильные размеры из макета: кегль 16px, кнопки/строки ≥56px, плитки ≥104px.
- **Иконки — под `public/assets/icons/`** (отдаётся существующей nginx-локацией `/poselenie/assets/`), поэтому правок nginx не требуется. Manifest ссылается на `/poselenie/assets/icons/…`.
- **Даты/время в сервисе не вычисляем внутри** — `today` передаётся параметром (переносимо, тестируемо).
- Каждый новый PHP-файл проверяется `php -l` перед коммитом. Частые коммиты. Ветка уже создана: `feature/mobile-pwa`.

## Файловая структура

**Создать:**
- `residents/src/Service/AppDashboard.php` — агрегатор счётчиков/статуса дневника/собрания (read-only).
- `residents/src/Controller/AppController.php` — лаунчер `/poselenie/app` + офлайн-страница `/poselenie/offline`.
- `residents/src/Controller/PwaController.php` — отдаёт `manifest.webmanifest` и `sw.js`.
- `residents/src/templates/app/home.php` — шаблон лаунчера.
- `residents/src/templates/app/offline.php` — офлайн-страница.
- `residents/public/assets/icons/icon-192.png`, `icon-512.png`, `icon-maskable-512.png` — из логотипа.
- `residents/tests/AppDashboardTest.php`.

**Изменить:**
- `residents/public/index.php` — маршруты app/offline/manifest/sw.
- `residents/src/templates/layout.php` — `<head>` (manifest/theme-color/apple-touch-icon + регистрация SW) и пункт «Приложение» в шапке жителей.
- `residents/public/assets/residents.css` — стили `.app-*`.

---

## Task 1: AppDashboard — агрегатор лаунчера (TDD)

**Files:**
- Create: `residents/src/Service/AppDashboard.php`
- Test: `residents/tests/AppDashboardTest.php`

- [ ] **Step 1: Написать падающий тест**

Create `residents/tests/AppDashboardTest.php`:

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Service\AppDashboard;
use SkazResidents\Repository\{ToolRepository, BookRepository, TripRepository, DiaryRepository, CouncilTaskRepository};

final class AppDashboardTest extends TestCase
{
    protected function setUp(): void { make_test_db(); }

    private function seed(): int
    {
        $familyId = 7;
        // Дневник семьи: 2 записи, последняя — на проверке.
        $d = new DiaryRepository();
        $d->create($familyId, 'Старая запись', 'тело', false, '2026-08-01 10:00:00');
        $d->create($familyId, 'Как мы копали пруд', 'тело', false, '2026-09-02 10:00:00'); // pending по умолчанию
        // Инструменты: 2 доступных, 1 на руках.
        $t = new ToolRepository();
        $t->create($familyId, 'Дрель', 'Электро', null, null, null, '2026-09-01 10:00:00');
        $t->create($familyId, 'Лопата', 'Сад', null, null, null, '2026-09-01 10:00:00');
        $busy = $t->create($familyId, 'Пила', 'Сад', null, null, null, '2026-09-01 10:00:00');
        $t->setStatus($busy, 'on_loan');
        // Книги: 3 на полке.
        $b = new BookRepository();
        $b->create($familyId, 'Книга А', 'Автор', 'Жанр', null, null, '2026-09-01 10:00:00');
        $b->create($familyId, 'Книга Б', 'Автор', 'Жанр', null, null, '2026-09-01 10:00:00');
        $b->create($familyId, 'Книга В', 'Автор', 'Жанр', null, null, '2026-09-01 10:00:00');
        // Поездки: 1 будущая, 1 прошедшая.
        $tr = new TripRepository();
        $tr->create($familyId, 'Терем', 'Северская', '2026-09-10', '09:00', 3, null, '2026-09-01 10:00:00');
        $tr->create($familyId, 'Терем', 'Краснодар', '2026-08-01', '09:00', 3, null, '2026-08-01 10:00:00');
        // Задачи совета: 1 активная на С. Шубина, 1 активная на другом.
        $c = new CouncilTaskRepository();
        $c->create('Заправить газгольдер', null, 'Е. Моисеенко', 'С. Шубин', 'высокая');
        $c->create('Химчистка мебели', null, 'Е. Моисеенко', '', 'средняя');
        return $familyId;
    }

    public function test_counts_and_diary_status(): void
    {
        $familyId = $this->seed();
        $dash = new AppDashboard();
        $r = $dash->build($familyId, 'С. Шубин', '2026-09-04');

        $this->assertSame(2, $r['counts']['toolsFree']);
        $this->assertSame(3, $r['counts']['books']);
        $this->assertSame(1, $r['counts']['trips']);
        $this->assertSame(2, $r['counts']['councilActive']);
        $this->assertSame(1, $r['counts']['councilMine']);

        $this->assertSame(2, $r['diary']['count']);
        $this->assertSame('Как мы копали пруд', $r['diary']['latestTitle']);
        $this->assertSame('pending', $r['diary']['latestStatus']);

        $this->assertNotEmpty($r['meeting']['date']);
        $this->assertGreaterThan(0, $r['agendaCount']);
    }

    public function test_council_mine_zero_without_name(): void
    {
        $familyId = $this->seed();
        $r = (new AppDashboard())->build($familyId, null, '2026-09-04');
        $this->assertSame(0, $r['counts']['councilMine']);
        $this->assertSame(2, $r['counts']['councilActive']); // активные считаются всегда
    }

    public function test_empty_family_does_not_crash(): void
    {
        $r = (new AppDashboard())->build(999, 'С. Шубин', '2026-09-04');
        $this->assertSame(0, $r['diary']['count']);
        $this->assertNull($r['diary']['latestTitle']);
        $this->assertSame(0, $r['counts']['toolsFree']);
    }
}
```

- [ ] **Step 2: Прогнать — должен упасть**

Run (server harness): `... php8.3 vendor/bin/phpunit --filter AppDashboardTest`
Expected: FAIL — `Class "SkazResidents\Service\AppDashboard" not found`.

- [ ] **Step 3: Реализовать сервис**

Create `residents/src/Service/AppDashboard.php`:

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Service;

use SkazResidents\CouncilData;
use SkazResidents\Repository\{ToolRepository, BookRepository, TripRepository, DiaryRepository, CouncilTaskRepository};

/**
 * Собирает данные для мобильного лаунчера /poselenie/app: ближайшее собрание,
 * статус дневника семьи и счётчики разделов. Только чтение из существующих
 * репозиториев — единый источник, чтобы контроллер оставался тонким.
 * $today передаётся параметром (переносимо/тестируемо, без date() внутри).
 */
final class AppDashboard
{
    public function __construct(
        private CouncilTaskRepository $tasks = new CouncilTaskRepository(),
        private ToolRepository $tools = new ToolRepository(),
        private BookRepository $books = new BookRepository(),
        private TripRepository $trips = new TripRepository(),
        private DiaryRepository $diary = new DiaryRepository()
    ) {}

    /** @return array<string,mixed> */
    public function build(int $familyId, ?string $councilName, string $today): array
    {
        $meeting = CouncilData::nextMeeting();

        // Дневник семьи: новее сверху (по id), последняя запись + её статус.
        $entries = $this->diary->listByFamily($familyId);
        usort($entries, static fn($a, $b) => (int) $b['id'] <=> (int) $a['id']);
        $latest = $entries[0] ?? null;

        $active = $this->tasks->listWithSubtasks(false, 'priority');
        $councilMine = 0;
        if ($councilName !== null && $councilName !== '') {
            $councilMine = count(array_filter($active, static fn($t) => (string) $t['assignee'] === $councilName));
        }

        return [
            'meeting'     => $meeting,
            'agendaCount' => count($meeting['agenda'] ?? []),
            'diary'       => [
                'count'        => count($entries),
                'latestTitle'  => $latest['title'] ?? null,
                'latestStatus' => $latest['status'] ?? null,
            ],
            'counts' => [
                'toolsFree'     => count($this->tools->listCatalog('', '', 'available')),
                'books'         => count($this->books->listCatalog('', '', '')),
                'trips'         => count($this->trips->listUpcoming($today)),
                'councilActive' => count($active),
                'councilMine'   => $councilMine,
            ],
        ];
    }
}
```

- [ ] **Step 4: Прогнать — должен пройти**

Run: `... php8.3 vendor/bin/phpunit --filter AppDashboardTest`
Expected: PASS (3 теста). Затем `php8.3 -l src/Service/AppDashboard.php`.

> Если тест `test_counts_and_diary_status` упадёт на `trips` или `books`: проверь фактическое поведение `TripRepository::listUpcoming($today)` (может фильтровать по `seats_free>0`/`status='active'`) и `BookRepository::listCatalog('', '', '')` (пустой статус = все). Приведи ожидания теста к реальному поведению репозитория (реальное поведение — источник правды, не выдумывай новое).

- [ ] **Step 5: Коммит**

```bash
git add residents/src/Service/AppDashboard.php residents/tests/AppDashboardTest.php
git commit -m "feat(pwa): агрегатор данных мобильного лаунчера"
```

---

## Task 2: CSS лаунчера

**Files:**
- Modify: `residents/public/assets/residents.css` (в конец)

- [ ] **Step 1: Дописать стили в конец `residents/public/assets/residents.css`**

```css
/* ==== Мобильный лаунчер (PWA) ==== */
.app-wrap { max-width: 460px; margin: 0 auto; }
.app-head { display: flex; align-items: center; gap: 12px; padding: 4px 4px 16px; }
.app-logo { width: 52px; height: 52px; border-radius: 10px; flex: none; }
.app-hello { display: flex; flex-direction: column; line-height: 1.2; }
.app-hello b { font-family: var(--font-head); font-weight: 700; font-size: 20px; color: #036844; }
.app-hello span { font-size: 13px; color: var(--muted); }

.app-card { background: #fff; border: 1px solid #e6e4d8; border-radius: 14px; overflow: hidden; margin-bottom: 16px; }
.app-card-body { padding: 16px 18px 6px; display: flex; flex-direction: column; gap: 2px; }
.app-eyebrow { font-size: 13px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: var(--ochre); }
.app-card-title { font-family: var(--font-head); font-weight: 700; font-size: 20px; color: var(--ink); }
.app-card-meta { font-size: 14px; color: var(--muted); }
.app-card-actions { padding: 14px 18px 18px; display: flex; gap: 10px; }
.app-btn { flex: 1; min-height: 52px; display: flex; align-items: center; justify-content: center; border-radius: 999px; font-weight: 700; font-size: 15px; text-decoration: none; }
.app-btn--fill { background: var(--green); color: #fff; }
.app-btn--fill:active { background: #036844; }
.app-btn--ghost { border: 1.5px solid var(--green); color: var(--green); }
.app-btn--ghost:active { background: #e7f2ec; }

.app-diary { margin-bottom: 14px; background: #f5ecdd; border-radius: 14px; padding: 14px 18px; display: flex; flex-direction: column; gap: 2px; text-decoration: none; color: var(--ink); }
.app-diary b { font-weight: 700; font-size: 15px; }
.app-diary span { font-size: 14px; color: var(--muted); }

.app-sec-label { padding: 8px 4px 0; font-size: 13px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: var(--muted); }
.app-grid { padding: 10px 0 0; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.app-tile { min-height: 104px; background: #fff; border: 1px solid #e6e4d8; border-radius: 14px; padding: 14px; display: flex; flex-direction: column; justify-content: space-between; text-decoration: none; color: var(--ink); }
.app-tile:active { background: #e7f2ec; }
.app-tile b { font-family: var(--font-head); font-weight: 700; font-size: 16px; line-height: 1.3; color: #036844; }
.app-tile span { font-size: 13px; color: var(--muted); }
.app-tile span.accent { color: var(--ochre); font-weight: 700; }

.app-offline-banner { display: none; margin: 18px 4px 0; padding: 12px 16px; border: 1px dashed #e6e4d8; border-radius: 12px; align-items: center; gap: 10px; background: #f3f1e8; }
.app-offline-banner.show { display: flex; }
.app-offline-dot { width: 12px; height: 12px; border-radius: 50%; background: var(--ochre); flex: none; }
.app-offline-banner span { font-size: 13px; color: var(--muted); }

.app-offline-page { max-width: 460px; margin: 0 auto; padding: 40px 20px; text-align: center; }
.app-offline-page h1 { font-family: var(--font-head); color: var(--green); }
```

- [ ] **Step 2: Проверка синтаксиса CSS (визуальный просмотр) и коммит**

CSS не линтуется автоматически — просто убедиться, что скобки сбалансированы.

```bash
git add residents/public/assets/residents.css
git commit -m "feat(pwa): стили мобильного лаунчера"
```

---

## Task 3: AppController + шаблоны лаунчера и офлайна

**Files:**
- Create: `residents/src/Controller/AppController.php`
- Create: `residents/src/templates/app/home.php`
- Create: `residents/src/templates/app/offline.php`
- Modify: `residents/public/index.php`

- [ ] **Step 1: Создать контроллер**

Create `residents/src/Controller/AppController.php`:

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, CouncilAuth, Config, View};
use SkazResidents\Service\AppDashboard;

/**
 * Мобильный лаунчер /poselenie/app и офлайн-страница /poselenie/offline.
 * Гард — Auth::requireLogin() (нужны имя жителя и статус дневника). Плитки/действия
 * совета показываются только при активной council-сессии (CouncilAuth::id()).
 */
final class AppController
{
    public function __construct(
        private AppDashboard $dashboard = new AppDashboard()
    ) {}

    public function home(): void
    {
        Auth::requireLogin();
        $hasCouncil = CouncilAuth::id() !== null;
        $data = $this->dashboard->build(
            Auth::id(),
            $hasCouncil ? CouncilAuth::name() : null,
            date('Y-m-d')
        );
        View::render('app/home', [
            'dash'       => $data,
            'me'         => Auth::name(),
            'hasCouncil' => $hasCouncil,
            'savedAt'    => date('H:i'),
            'uploadsUrl' => rtrim((string) Config::get('uploads_url'), '/'),
        ], 'Приложение');
    }

    public function offline(): void
    {
        Auth::requireLogin();
        View::render('app/offline', [], 'Нет сети');
    }
}
```

- [ ] **Step 2: Создать шаблон лаунчера**

Create `residents/src/templates/app/home.php`:

```php
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
      <span class="app-card-meta"><?= View::e($dash['meeting']['place']) ?> · <?= (int) $dash['agendaCount'] ?> вопроса в повестке</span>
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
```

- [ ] **Step 3: Добавить хелперы в `residents/src/bootstrap.php`**

Лаунчер использует `diary_status_line()` и `plural_ru()`. Открой `residents/src/bootstrap.php`, найди блок функций-хелперов (там уже есть `status_label`/`ru_date`) и добавь рядом:

```php
if (!function_exists('plural_ru')) {
    /** Русское склонение: plural_ru(2,'задача','задачи','задач'). */
    function plural_ru(int $n, string $one, string $few, string $many): string
    {
        $n = abs($n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) { return $many; }
        if ($n1 > 1 && $n1 < 5) { return $few; }
        if ($n1 === 1) { return $one; }
        return $many;
    }
}
if (!function_exists('diary_status_line')) {
    /** Строка статуса дневника для лаунчера. $d = ['count','latestStatus',...]. */
    function diary_status_line(array $d): string
    {
        $map = ['pending' => 'на проверке', 'published' => 'опубликована', 'rejected' => 'отклонена'];
        $st = $map[$d['latestStatus'] ?? ''] ?? 'в дневнике';
        return 'Ваш дневник: последняя запись ' . $st;
    }
}
```

> Перед вставкой ПРОЧИТАЙ `bootstrap.php` и вставь по образцу существующих хелперов (тот же стиль объявления). Если хелперы там объявлены иначе (класс/неймспейс) — следуй фактическому паттерну файла.

- [ ] **Step 4: Создать офлайн-шаблон**

Create `residents/src/templates/app/offline.php`:

```php
<div class="app-offline-page">
  <h1>Нет сети</h1>
  <p>Эта страница ещё не сохранена для офлайна. Проверьте соединение и повторите.</p>
  <p style="margin-top:1.4rem"><a class="res-btn" href="/poselenie/app">На главную приложения</a></p>
</div>
```

- [ ] **Step 5: Маршруты в `residents/public/index.php`**

Рядом с блоком бюджета жителей (`$budget = new BudgetController(); ...`) добавить импорт и маршруты. Импорт — вверху к остальным `use ...Controller...`:

```php
use SkazResidents\Controller\AppController;
```

И регистрация (specific-пути; конфликтов с `{id}` нет — в `/poselenie/*` generic-роута нет):

```php
$app = new AppController();
$router->get('/poselenie/app', [$app, 'home']);
$router->get('/poselenie/offline', [$app, 'offline']);
```

- [ ] **Step 6: Проверка синтаксиса и полный прогон тестов (сервер)**

Run:
```
php8.3 -l src/Controller/AppController.php
php8.3 -l src/templates/app/home.php
php8.3 -l src/templates/app/offline.php
php8.3 -l src/bootstrap.php
php8.3 -l public/index.php
php8.3 vendor/bin/phpunit
```
Expected: все `-l` без ошибок; весь набор PHPUnit зелёный.

- [ ] **Step 7: Коммит**

```bash
git add residents/src/Controller/AppController.php residents/src/templates/app/home.php residents/src/templates/app/offline.php residents/src/bootstrap.php residents/public/index.php
git commit -m "feat(pwa): лаунчер /poselenie/app и офлайн-страница"
```

---

## Task 4: Иконки PWA (генерация из логотипа)

**Files:**
- Create: `residents/public/assets/icons/icon-192.png`, `icon-512.png`, `icon-maskable-512.png`
- Create (временный, не коммитить): скрипт генерации в scratchpad.

- [ ] **Step 1: Сгенерировать иконки sharp'ом из логотипа**

Источник: `public/images/Logo_SK_204x204.png` (в корне astro-проекта). Node+sharp доступны локально.
Написать во временный файл (scratchpad) и выполнить:

```js
// gen-icons.mjs
import sharp from 'sharp';
import { mkdirSync } from 'node:fs';
const SRC = 'public/images/Logo_SK_204x204.png';
const OUT = 'residents/public/assets/icons';
mkdirSync(OUT, { recursive: true });
const paper = { r: 251, g: 250, b: 245, alpha: 1 }; // #fbfaf5
// 192 и 512 — логотип на бумажном фоне (contain), без обрезки.
for (const size of [192, 512]) {
  await sharp(SRC).resize(size, size, { fit: 'contain', background: paper })
    .flatten({ background: paper }).png().toFile(`${OUT}/icon-${size}.png`);
}
// maskable 512 — логотип в безопасной зоне (~72% площади) на бумажном фоне.
const inner = Math.round(512 * 0.72);
const logo = await sharp(SRC).resize(inner, inner, { fit: 'contain', background: paper }).png().toBuffer();
await sharp({ create: { width: 512, height: 512, channels: 4, background: paper } })
  .composite([{ input: logo, gravity: 'centre' }]).png().toFile(`${OUT}/icon-maskable-512.png`);
console.log('icons written to', OUT);
```

Run (из корня astro-проекта): `node <scratchpad>/gen-icons.mjs`
Expected: `icons written to residents/public/assets/icons` + три файла на диске.

- [ ] **Step 2: Проверить, что файлы созданы**

Run: `ls -la residents/public/assets/icons/`
Expected: `icon-192.png`, `icon-512.png`, `icon-maskable-512.png` (ненулевого размера).

- [ ] **Step 3: Коммит бинарных иконок**

```bash
git add residents/public/assets/icons/icon-192.png residents/public/assets/icons/icon-512.png residents/public/assets/icons/icon-maskable-512.png
git commit -m "feat(pwa): иконки приложения из логотипа поселения"
```

---

## Task 5: PwaController — manifest и service worker

**Files:**
- Create: `residents/src/Controller/PwaController.php`
- Modify: `residents/public/index.php`

- [ ] **Step 1: Создать контроллер, отдающий manifest и sw.js**

Create `residents/src/Controller/PwaController.php`:

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

/**
 * Отдаёт PWA-манифест и service worker как статические ответы с корректным MIME.
 * Через PHP front-controller — чтобы не править nginx. sw.js — no-cache (браузер
 * должен получать свежую версию). Публичны (без ПДн). SW scope — /poselenie/.
 */
final class PwaController
{
    private const CACHE_VERSION = 'skazapp-v1';

    public function manifest(): void
    {
        header('Content-Type: application/manifest+json; charset=utf-8');
        echo json_encode([
            'name'             => 'Сказочный Край',
            'short_name'       => 'Сказочный Край',
            'lang'             => 'ru',
            'start_url'        => '/poselenie/app',
            'scope'            => '/poselenie/',
            'display'          => 'standalone',
            'orientation'      => 'portrait',
            'background_color' => '#fbfaf5',
            'theme_color'      => '#008757',
            'icons'            => [
                ['src' => '/poselenie/assets/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/poselenie/assets/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/poselenie/assets/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public function serviceWorker(): void
    {
        header('Content-Type: text/javascript; charset=utf-8');
        header('Cache-Control: no-cache');
        $v = self::CACHE_VERSION;
        echo <<<JS
const CACHE = '{$v}';
const PRECACHE = [
  '/poselenie/app',
  '/poselenie/offline',
  '/poselenie/assets/residents.css',
  '/poselenie/assets/icons/icon-192.png',
  '/poselenie/assets/fonts/pt-sans-cyrillic-400-normal.woff2',
  '/poselenie/assets/fonts/pt-sans-cyrillic-700-normal.woff2',
  '/poselenie/assets/fonts/pt-serif-cyrillic-400-normal.woff2',
  '/poselenie/assets/fonts/pt-serif-cyrillic-700-normal.woff2',
];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return; // POST-действия — только в сеть
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  if (!url.pathname.startsWith('/poselenie/')) return; // вне scope

  // Навигация: сеть-первым, при офлайне — кэш страницы, иначе офлайн-страница.
  if (req.mode === 'navigate') {
    e.respondWith(
      fetch(req).then((res) => {
        const copy = res.clone();
        caches.open(CACHE).then((c) => c.put(req, copy));
        return res;
      }).catch(() => caches.match(req).then((hit) => hit || caches.match('/poselenie/offline')))
    );
    return;
  }

  // Статика (css/шрифты/иконки): кэш-первым.
  if (/\\.(css|woff2|png|jpg|svg)$/.test(url.pathname)) {
    e.respondWith(
      caches.match(req).then((hit) => hit || fetch(req).then((res) => {
        const copy = res.clone();
        caches.open(CACHE).then((c) => c.put(req, copy));
        return res;
      }))
    );
  }
});
JS;
    }
}
```

- [ ] **Step 2: Маршруты в `residents/public/index.php`**

Импорт вверху:

```php
use SkazResidents\Controller\PwaController;
```

Регистрация (рядом с маршрутами `$app`):

```php
$pwa = new PwaController();
$router->get('/poselenie/manifest.webmanifest', [$pwa, 'manifest']);
$router->get('/poselenie/sw.js', [$pwa, 'serviceWorker']);
```

- [ ] **Step 3: Проверка синтаксиса и тесты (сервер)**

Run:
```
php8.3 -l src/Controller/PwaController.php
php8.3 -l public/index.php
php8.3 vendor/bin/phpunit
```
Expected: `-l` чисто; весь набор зелёный.

- [ ] **Step 4: Коммит**

```bash
git add residents/src/Controller/PwaController.php residents/public/index.php
git commit -m "feat(pwa): manifest и service worker через PHP-контроллер"
```

---

## Task 6: Подключение PWA в layout + пункт «Приложение»

**Files:**
- Modify: `residents/src/templates/layout.php`

- [ ] **Step 1: Прочитать текущий `residents/src/templates/layout.php`** (обязательно перед правкой).

- [ ] **Step 2: В `<head>` добавить manifest/theme-color/иконку и регистрацию SW**

Внутри `<head>`, сразу после строки `<link rel="stylesheet" href="/poselenie/assets/residents.css">`, добавить:

```php
    <link rel="manifest" href="/poselenie/manifest.webmanifest">
    <meta name="theme-color" content="#008757">
    <link rel="apple-touch-icon" href="/poselenie/assets/icons/icon-192.png">
    <script>
      if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
          navigator.serviceWorker.register('/poselenie/sw.js').catch(function () {});
        });
      }
    </script>
```

- [ ] **Step 3: Добавить пункт «Приложение» в шапку жителей**

В блоке `<?php if (Auth::id() !== null): ?>` (где «Бюджет»/«Кабинет») добавить пункт первым:

```php
        <?php if (Auth::id() !== null): ?>
            <a href="/poselenie/app">Приложение</a>
            <a href="/poselenie/byudzhet">Бюджет</a>
            <a href="/poselenie/">Кабинет</a>
```

> Точный контекст замены смотри в файле: сейчас там идут `<a href="/poselenie/byudzhet">Бюджет</a>` затем `<a href="/poselenie/">Кабинет</a>` — вставить `Приложение` перед `Бюджет`.

- [ ] **Step 4: Проверка синтаксиса и полный прогон тестов (сервер)**

Run:
```
php8.3 -l src/templates/layout.php
php8.3 vendor/bin/phpunit
```
Expected: `-l` чисто; весь набор зелёный.

- [ ] **Step 5: Коммит**

```bash
git add residents/src/templates/layout.php
git commit -m "feat(pwa): подключение manifest/SW и пункт «Приложение» в шапке жителей"
```

---

## Task 7: Финальная проверка и деплой

**Files:** нет правок кода — проверка и выкладка.

- [ ] **Step 1: Полный прогон тестов + линт всех новых файлов (сервер)**

Run: `... php8.3 vendor/bin/phpunit`
Expected: 100% зелёный (включая `AppDashboardTest`).

- [ ] **Step 2: Чек-лист по коду (не автотест)**

- `/poselenie/app` и `/poselenie/offline` → `Auth::requireLogin()` (гость → редирект).
- `manifest.webmanifest`/`sw.js` отдаются с корректным MIME; sw.js — `Cache-Control: no-cache`.
- SW перехватывает только GET в `/poselenie/*`, POST уходят в сеть (действия работают онлайн как раньше).
- Плитки/действия совета видны только при council-сессии; иначе «Войти как член совета».
- Иконки лежат под `/poselenie/assets/icons/` (существующая nginx-локация — без правок конфига).

- [ ] **Step 3: Мерж ветки в main (после подтверждения) и деплой**

По образцу модуля бюджета:
```bash
git checkout main && git merge --no-ff feature/mobile-pwa -m "Merge: мобильный PWA (лаунчер + PWA-слой)" && git push origin main
# доставка кода на прод:
git archive HEAD:residents | ssh abconsult 'tar -x -C /var/www/skaz-residents'
ssh abconsult 'chown -R www-data:www-data /var/www/skaz-residents/src /var/www/skaz-residents/public && cd /var/www/skaz-residents && php8.3 /root/composer.phar dump-autoload --optimize --no-dev && systemctl reload php8.3-fpm'
```
Схема БД не менялась — миграций нет. nginx-правок нет.

- [ ] **Step 4: Smoke на проде (гарды/MIME)**

```bash
ssh abconsult 'for u in /poselenie/app /poselenie/offline; do printf "%s -> " "$u"; curl -sS -o /dev/null -w "%{http_code} %{redirect_url}\n" "https://skaz-kray.ru$u"; done; echo "-- manifest/sw MIME --"; curl -sSI "https://skaz-kray.ru/poselenie/manifest.webmanifest" | grep -i content-type; curl -sSI "https://skaz-kray.ru/poselenie/sw.js" | grep -iE "content-type|cache-control"; echo "-- иконка --"; curl -sS -o /dev/null -w "%{http_code}\n" "https://skaz-kray.ru/poselenie/assets/icons/icon-192.png"'
```
Expected: `/poselenie/app` и `/poselenie/offline` → 302 на `/poselenie/vhod` (гость); manifest → `application/manifest+json`; sw.js → `text/javascript` + `no-cache`; иконка → 200.

- [ ] **Step 5: Ручная проверка на телефоне (владелец)**

Под входом жителя: открыть `/poselenie/app`, установить PWA («на экран Домой»), проверить офлайн-режим (выключить сеть → лаунчер из кэша + баннер), переходы по плиткам, вход в совет из лаунчера.

- [ ] **Step 6: Обновить память проекта**

Дописать в память `skaz_kray_residents_section` факт о мобильном PWA: лаунчер `/poselenie/app`, `PwaController` (manifest/sw.js через PHP, scope `/poselenie/`), иконки под `assets/icons/`, офлайн только чтение, вход по факту сессий.

---

## Самопроверка плана (для автора)

**Покрытие спеки:**
- Лаунчер по макету 1a с живыми данными → Task 1 (AppDashboard) + Task 3 (шаблон).
- Гибрид C, действия через существующие страницы (ссылки на `/sovet`, `/poselenie/*`) → Task 3.
- Вход по факту сессий (council только при сессии) → Task 3 (ветвление `$hasCouncil`).
- PWA: manifest + SW (scope `/poselenie/`, офлайн-чтение, no-cache sw.js) → Task 5.
- Иконки из логотипа → Task 4.
- Подключение в layout + пункт «Приложение» → Task 6.
- Офлайн только чтение (POST в сеть, навигация network-first→cache→offline) → SW в Task 5.
- Тесты (агрегатор + гарды) + деплой без миграций/nginx → Task 1, Task 7.

**Согласованность типов:** `AppDashboard::build()` возвращает `meeting/agendaCount/diary{count,latestTitle,latestStatus}/counts{toolsFree,books,trips,councilActive,councilMine}` — шаблон `app/home.php` читает ровно эти ключи. Маршруты `/poselenie/{app,offline,manifest.webmanifest,sw.js}` регистрируются в `index.php` и совпадают с методами контроллеров. Иконки: путь `/poselenie/assets/icons/icon-{192,512,maskable-512}.png` одинаков в manifest, SW precache, layout и шаблоне.

**Замечания-риски для исполнителя:**
- `diary`/`tools`/`trips` счётчики — сверить с реальным поведением репозиториев (Task 1 Step 4 note). Реальное поведение — источник правды.
- Хелперы `plural_ru`/`diary_status_line` — вставить по фактическому паттерну `bootstrap.php` (Task 3 Step 3).
- `CouncilData::nextMeeting()` может не содержать ключ `title` — шаблон использует `?? 'Собрание совета'` (безопасно).
