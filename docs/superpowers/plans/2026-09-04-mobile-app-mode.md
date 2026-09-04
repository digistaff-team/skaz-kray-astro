# «Мобильное = приложение»: единый PWA на оба раздела — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Единый устанавливаемый PWA на оба раздела (`/poselenie/*` + `/sovet/*`), app-стиль на мобильном; мобильный вход жителя ведёт на лаунчер (без карточки собрания); десктоп — как раньше.

**Architecture:** Правки существующего PHP-приложения `residents/`. SW получает `Service-Worker-Allowed: /` из PHP (без nginx), scope `/`, кэширует оба раздела; регистрируется из обеих шапок. Лаунчер упрощается (убрать совет-специфику), `AppDashboard` теряет зависимость от совета. Редирект после входа жителя — на лаунчер только при мобильном UA.

**Tech Stack:** PHP 8.3 + PDO, PHPUnit 11 (SQLite), существующие `Auth`/`CouncilAuth`/`View`, service worker/manifest через `PwaController`.

---

## Ключевые соглашения

- **Локально PHP нет** — тесты/линт на сервере: `git archive HEAD:residents | ssh abconsult 'tar -x -C /root/ledger-test'`, затем `ssh abconsult 'cd /root/ledger-test && ([ -f vendor/bin/phpunit ] || php8.3 /root/composer.phar install --no-interaction -q) && php8.3 vendor/bin/phpunit'`.
- Каждый новый/изменённый PHP-файл — `php8.3 -l`. SW проверять `node --check` по выводу контроллера. Частые коммиты. Ветка: `feat/mobile-app-mode`.
- Десктоп не трогаем: мобильные правки — через UA (редирект) и медиазапрос `≤600px` (стили).

## Файлы

**Изменить:**
- `residents/src/Service/AppDashboard.php` + `residents/tests/AppDashboardTest.php` (упрощение).
- `residents/src/Controller/AppController.php` (убрать council-логику).
- `residents/src/templates/app/home.php` (убрать карточку собрания; плитки).
- `residents/src/bootstrap.php` (хелпер `is_mobile_ua()`).
- `residents/src/Controller/AuthController.php` (редирект после входа — mobile→лаунчер).
- `residents/src/Controller/PwaController.php` (manifest scope `/`; `Service-Worker-Allowed`; SW оба префикса; версия v3).
- `residents/src/templates/layout.php` (viewport-fit + apple-meta; SW scope `/`).
- `residents/src/templates/council/layout.php` (manifest/meta/SW-регистрация; viewport-fit).
- `residents/public/assets/residents.css` (мобильные гарантии `≤600px`).

---

## Task 1: Упростить AppDashboard (TDD)

**Files:**
- Modify: `residents/src/Service/AppDashboard.php`
- Modify: `residents/tests/AppDashboardTest.php`

- [ ] **Step 1: Обновить тест под упрощённую модель**

Заменить содержимое `residents/tests/AppDashboardTest.php` на:

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Database;
use SkazResidents\Service\AppDashboard;
use SkazResidents\Repository\{ToolRepository, BookRepository, TripRepository, DiaryRepository};

final class AppDashboardTest extends TestCase
{
    protected function setUp(): void { make_test_db(); }

    private function makeFamily(int $id): void
    {
        $st = Database::pdo()->prepare(
            "INSERT INTO families (id, email, password_hash, name, status, role)
             VALUES (?, ?, 'x', 'Семья Шубиных', 'active', 'resident')"
        );
        $st->execute([$id, 'family' . $id . '@example.com']);
    }

    private function seed(): int
    {
        $familyId = 7;
        $this->makeFamily($familyId);
        $d = new DiaryRepository();
        $d->create($familyId, 'Старая запись', 'тело', false, '2026-08-01 10:00:00');
        $d->create($familyId, 'Как мы копали пруд', 'тело', false, '2026-09-02 10:00:00');
        $t = new ToolRepository();
        $t->create($familyId, 'Дрель', 'Электро', null, null, null, '2026-09-01 10:00:00');
        $t->create($familyId, 'Лопата', 'Сад', null, null, null, '2026-09-01 10:00:00');
        $busy = $t->create($familyId, 'Пила', 'Сад', null, null, null, '2026-09-01 10:00:00');
        $t->setStatus($busy, 'on_loan');
        $b = new BookRepository();
        $b->create($familyId, 'Книга А', 'Автор', 'Жанр', null, null, '2026-09-01 10:00:00');
        $b->create($familyId, 'Книга Б', 'Автор', 'Жанр', null, null, '2026-09-01 10:00:00');
        $b->create($familyId, 'Книга В', 'Автор', 'Жанр', null, null, '2026-09-01 10:00:00');
        $tr = new TripRepository();
        $tr->create($familyId, 'Терем', 'Северская', '2026-09-10', '09:00', 3, null, '2026-09-01 10:00:00');
        $tr->create($familyId, 'Терем', 'Краснодар', '2026-08-01', '09:00', 3, null, '2026-08-01 10:00:00');
        return $familyId;
    }

    public function test_counts_and_diary_status(): void
    {
        $familyId = $this->seed();
        $r = (new AppDashboard())->build($familyId, '2026-09-04');

        $this->assertSame(2, $r['counts']['toolsFree']);
        $this->assertSame(3, $r['counts']['books']);
        $this->assertSame(1, $r['counts']['trips']);

        $this->assertSame(2, $r['diary']['count']);
        $this->assertSame('Как мы копали пруд', $r['diary']['latestTitle']);
        $this->assertSame('pending', $r['diary']['latestStatus']);

        // Совет-специфики в модели больше нет.
        $this->assertArrayNotHasKey('meeting', $r);
        $this->assertArrayNotHasKey('councilActive', $r['counts']);
    }

    public function test_empty_family_does_not_crash(): void
    {
        $r = (new AppDashboard())->build(999, '2026-09-04');
        $this->assertSame(0, $r['diary']['count']);
        $this->assertNull($r['diary']['latestTitle']);
        $this->assertSame(0, $r['counts']['toolsFree']);
    }
}
```

- [ ] **Step 2: Прогнать — упадёт (сигнатура/ключи изменились)**

Run: `... php8.3 vendor/bin/phpunit --filter AppDashboardTest`
Expected: FAIL (старый `build()` требует 3 арг / возвращает лишние ключи).

- [ ] **Step 3: Упростить сервис**

Заменить `residents/src/Service/AppDashboard.php` на:

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Service;

use SkazResidents\Repository\{ToolRepository, BookRepository, TripRepository, DiaryRepository};

/**
 * Данные мобильного лаунчера /poselenie/app: статус дневника семьи и счётчики
 * разделов жителей. Только чтение. $today — параметром (тестируемо).
 * Совет-специфики (собрание, задачи совета) в лаунчере больше нет — она живёт
 * в разделе совета (/sovet).
 */
final class AppDashboard
{
    public function __construct(
        private ToolRepository $tools = new ToolRepository(),
        private BookRepository $books = new BookRepository(),
        private TripRepository $trips = new TripRepository(),
        private DiaryRepository $diary = new DiaryRepository()
    ) {}

    /** @return array<string,mixed> */
    public function build(int $familyId, string $today): array
    {
        $entries = $this->diary->listByFamily($familyId);
        usort($entries, static fn($a, $b) => (int) $b['id'] <=> (int) $a['id']);
        $latest = $entries[0] ?? null;

        return [
            'diary' => [
                'count'        => count($entries),
                'latestTitle'  => $latest['title'] ?? null,
                'latestStatus' => $latest['status'] ?? null,
            ],
            'counts' => [
                'toolsFree' => count($this->tools->listCatalog('', '', 'available')),
                'books'     => count($this->books->listCatalog('', '', '')),
                'trips'     => count($this->trips->listUpcoming($today)),
            ],
        ];
    }
}
```

- [ ] **Step 4: Прогнать — зелёный**

Run: `... php8.3 vendor/bin/phpunit --filter AppDashboardTest` → PASS (2 теста). Затем `php8.3 -l src/Service/AppDashboard.php`.

- [ ] **Step 5: Коммит**

```bash
git add residents/src/Service/AppDashboard.php residents/tests/AppDashboardTest.php
git commit -m "refactor(pwa): AppDashboard без совет-специфики (только дневник и счётчики)"
```

---

## Task 2: Упростить лаунчер (контроллер + шаблон)

**Files:**
- Modify: `residents/src/Controller/AppController.php`
- Modify: `residents/src/templates/app/home.php`

- [ ] **Step 1: Убрать council-логику из контроллера**

В `residents/src/Controller/AppController.php` заменить метод `home()` на:

```php
    public function home(): void
    {
        Auth::requireLogin();
        View::render('app/home', [
            'dash'    => $this->dashboard->build(Auth::id(), date('Y-m-d')),
            'me'      => Auth::name(),
            'savedAt' => date('H:i'),
        ], 'Приложение');
    }
```

И убрать неиспользуемый импорт `CouncilAuth` из `use SkazResidents\{Auth, CouncilAuth, View};` → `use SkazResidents\{Auth, View};`.

- [ ] **Step 2: Переписать шаблон лаунчера (без карточки собрания)**

Заменить `residents/src/templates/app/home.php` на:

```php
<?php use SkazResidents\View; /** @var array $dash */ ?>
<div class="app-wrap">
  <div class="app-head">
    <img class="app-logo" src="/poselenie/assets/icons/icon-192.png" alt="" width="52" height="52">
    <div class="app-hello">
      <b>Сказочный Край</b>
      <span>Здравствуйте, <?= View::e($me) ?></span>
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
    <a class="app-tile" href="/poselenie"><b>Мой<br>кабинет</b><span>дневник и мои вещи</span></a>
    <a class="app-tile" href="/poselenie/dnevniki"><b>Дневники<br>поместий</b><span>лента поселения</span></a>
    <a class="app-tile" href="/sovet"><b>Попечительский совет</b><span>внутренний портал</span></a>
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

> Плитка «Попечительский совет» ведёт на `/sovet` (раздельный вход совет сам попросит при отсутствии сессии). Карточки «Ближайшее собрание» в лаунчере больше нет — она в `/sovet`.

- [ ] **Step 3: Синтаксис + полный прогон тестов (сервер)**

Run:
```
php8.3 -l src/Controller/AppController.php
php8.3 -l src/templates/app/home.php
php8.3 vendor/bin/phpunit
```
Expected: `-l` чисто; весь набор зелёный.

- [ ] **Step 4: Коммит**

```bash
git add residents/src/Controller/AppController.php residents/src/templates/app/home.php
git commit -m "feat(pwa): лаунчер без карточки собрания; плитки «Мой кабинет»/«Совет»/«Бюджет»"
```

---

## Task 3: Мобильный редирект после входа жителя

**Files:**
- Modify: `residents/src/bootstrap.php`
- Modify: `residents/src/Controller/AuthController.php:88`

- [ ] **Step 1: Хелпер определения мобильного UA в `bootstrap.php`**

Рядом с `asset_ver()` (после неё) добавить:

```php
/** Грубое определение мобильного браузера по User-Agent (для выбора посадочной). */
function is_mobile_ua(): bool
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return (bool) preg_match('/Android|iPhone|iPad|iPod|Mobile|Opera Mini|IEMobile/i', $ua);
}
```

- [ ] **Step 2: Редирект после входа — mobile→лаунчер**

В `residents/src/Controller/AuthController.php` заменить строку 88:

```php
        Auth::login($family);
        header('Location: /poselenie/');
```

на:

```php
        Auth::login($family);
        header('Location: ' . (is_mobile_ua() ? '/poselenie/app' : '/poselenie/'));
```

- [ ] **Step 3: Синтаксис**

Run: `php8.3 -l src/bootstrap.php && php8.3 -l src/Controller/AuthController.php`
Expected: чисто.

- [ ] **Step 4: Коммит**

```bash
git add residents/src/bootstrap.php residents/src/Controller/AuthController.php
git commit -m "feat(pwa): после входа жителя на мобильном — лаунчер (десктоп — кабинет как было)"
```

---

## Task 4: Единый PWA на оба раздела (PwaController)

**Files:**
- Modify: `residents/src/Controller/PwaController.php`

- [ ] **Step 1: manifest scope `/`, SW `Service-Worker-Allowed`, оба префикса, версия v3**

В `residents/src/Controller/PwaController.php`:

(4a) Версия кэша:
```php
    private const CACHE_VERSION = 'skazapp-v3';
```

(4b) В `manifest()` заменить `'scope' => '/poselenie/',` на:
```php
            'scope'            => '/',
```

(4c) В `serviceWorker()` — сразу после `header('Cache-Control: no-cache');` добавить:
```php
        header('Service-Worker-Allowed: /');
```

(4d) В теле SW заменить строку guard'а
```
  if (!url.pathname.startsWith('/poselenie/')) return; // вне scope
```
на:
```
  if (!(url.pathname.startsWith('/poselenie/') || url.pathname.startsWith('/sovet/'))) return; // вне scope
```

- [ ] **Step 2: Проверка SW валиден (сервер) + синтаксис**

Run:
```
php8.3 -l src/Controller/PwaController.php
php8.3 -r 'require "vendor/autoload.php"; $c=new SkazResidents\Controller\PwaController(); ob_start(); $c->serviceWorker(); file_put_contents("/tmp/sw.js", ob_get_clean());' && node --check /tmp/sw.js && echo "sw.js valid"
```
Expected: `-l` чисто; `sw.js valid`.

- [ ] **Step 3: Коммит**

```bash
git add residents/src/Controller/PwaController.php
git commit -m "feat(pwa): единый scope / на оба раздела (Service-Worker-Allowed, sovet в SW), v3"
```

---

## Task 5: Регистрация SW из обеих шапок + app-meta + мобильные стили

**Files:**
- Modify: `residents/src/templates/layout.php`
- Modify: `residents/src/templates/council/layout.php`
- Modify: `residents/public/assets/residents.css`

- [ ] **Step 1: `layout.php` — viewport-fit, apple-meta, SW scope `/`**

В `residents/src/templates/layout.php`:

(5a) Заменить строку viewport:
```php
    <meta name="viewport" content="width=device-width, initial-scale=1">
```
на:
```php
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
```

(5b) После `<link rel="apple-touch-icon" ...>` (перед `<script>` регистрации SW) добавить apple-meta:
```php
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Сказочный Край">
```

(5c) В скрипте регистрации SW добавить scope:
```php
          navigator.serviceWorker.register('/poselenie/sw.js', { scope: '/' }).catch(function () {});
```

- [ ] **Step 2: `council/layout.php` — добавить PWA-подключение в `<head>`**

В `residents/src/templates/council/layout.php` заменить строку viewport на вариант с `viewport-fit=cover`, а сразу после строки со стилем
```php
    <link rel="stylesheet" href="/poselenie/assets/residents.css?v=<?= asset_ver('assets/residents.css') ?>">
```
добавить:
```php
    <link rel="manifest" href="/poselenie/manifest.webmanifest">
    <meta name="theme-color" content="#008757">
    <link rel="apple-touch-icon" href="/poselenie/assets/icons/icon-192.png">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Сказочный Край">
    <script>
      if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
          navigator.serviceWorker.register('/poselenie/sw.js', { scope: '/' }).catch(function () {});
        });
      }
    </script>
```

- [ ] **Step 3: Мобильные стили `≤600px` в конец `residents/public/assets/residents.css`**

```css
/* ==== Мобильное = приложение: уплотнение на телефоне (десктоп не трогаем) ==== */
@media (max-width: 600px) {
  .res-main, .sovet-main { padding: 1rem 0.9rem; }
  .res-header { padding: 0.8rem 1rem; }
  .res-card { border-radius: 12px; }
}
```

- [ ] **Step 4: Синтаксис + полный прогон тестов (сервер)**

Run:
```
php8.3 -l src/templates/layout.php
php8.3 -l src/templates/council/layout.php
php8.3 vendor/bin/phpunit
```
Expected: `-l` чисто; весь набор зелёный.

- [ ] **Step 5: Коммит**

```bash
git add residents/src/templates/layout.php residents/src/templates/council/layout.php residents/public/assets/residents.css
git commit -m "feat(pwa): регистрация SW scope / из обеих шапок, app-meta, мобильное уплотнение"
```

---

## Task 6: Финальная проверка и деплой

**Files:** нет правок кода.

- [ ] **Step 1: Полный прогон тестов + линт (сервер)**

Run: `... php8.3 vendor/bin/phpunit` → 100% зелёный (в т.ч. обновлённый `AppDashboardTest`).

- [ ] **Step 2: Чек-лист по коду**

- Лаунчер `/poselenie/app`: нет карточки собрания; плитки ведут на /poselenie, /poselenie/dnevniki, /sovet, инструменты/книги/поездки/бюджет/ярмарка.
- `AuthController` login: mobile UA → `/poselenie/app`, иначе `/poselenie/`.
- manifest `scope:"/"`; sw.js отдаёт `Service-Worker-Allowed: /`; SW guard пропускает `/poselenie/` и `/sovet/`; версия v3.
- Обе шапки регистрируют SW со `scope:'/'`; council/layout получил manifest/meta.
- Мобильные стили только в `@media (max-width:600px)` — десктоп не затронут.

- [ ] **Step 3: Мерж в main (после подтверждения) и деплой**

```bash
git checkout main && git merge --no-ff feat/mobile-app-mode -m "Merge: мобильное = приложение (единый PWA на оба раздела)" && git push origin main
git archive HEAD:residents | ssh abconsult 'tar -x -C /var/www/skaz-residents'
ssh abconsult 'chown -R www-data:www-data /var/www/skaz-residents/src /var/www/skaz-residents/public && cd /var/www/skaz-residents && php8.3 /root/composer.phar dump-autoload --optimize --no-dev && systemctl reload php8.3-fpm'
```
Схема БД не менялась. nginx-правок нет.

- [ ] **Step 4: Smoke на проде (GET, не HEAD)**

```bash
ssh abconsult 'echo "-- manifest scope --"; curl -sS "https://skaz-kray.ru/poselenie/manifest.webmanifest" | grep -E "\"scope\"|start_url"; echo "-- sw allowed/mime --"; curl -sS -D - -o /tmp/sw.js "https://skaz-kray.ru/poselenie/sw.js" | grep -iE "content-type|service-worker-allowed|cache-control"; node --check /tmp/sw.js && echo "sw valid"; grep -c "sovet" /tmp/sw.js; echo "-- гвард лаунчера --"; curl -sS -o /dev/null -w "%{http_code} %{redirect_url}\n" "https://skaz-kray.ru/poselenie/app"; echo "-- council head: SW/manifest --"; curl -sS "https://skaz-kray.ru/sovet/vhod" | grep -oE "manifest.webmanifest|sw.js|res-menu-btn" | sort -u'
```
Expected: manifest `"scope": "/"`; sw.js → `text/javascript` + `Service-Worker-Allowed: /`; sw valid + содержит `sovet`; `/poselenie/app` → 302 на вход; council-страница содержит manifest/sw/гамбургер.

- [ ] **Step 5: Ручная проверка (владелец, телефон)**

- Вход жителя на телефоне → попадание на лаунчер (без карточки собрания); на десктопе → кабинет как раньше.
- Установка единого PWA; из установленного приложения открываются и жители, и совет; офлайн-чтение обоих разделов.
- Карточка «Ближайшее собрание» видна в `/sovet`.

- [ ] **Step 6: Обновить память**

Дописать в память `skaz_kray_residents_section`: единый PWA scope `/` (Service-Worker-Allowed из PHP), SW кэширует и `/sovet/*`, мобильный вход жителя → лаунчер (UA), лаунчер без карточки собрания.

---

## Самопроверка плана

**Покрытие спеки:** упрощение лаунчера/AppDashboard (Task 1–2), карточка собрания убрана (Task 2), мобильный редирект по UA + десктоп как было (Task 3), единый PWA scope `/` + оба раздела в SW (Task 4), регистрация из обеих шапок + app-meta + мобильные стили (Task 5), деплой без миграций/nginx (Task 6).

**Согласованность:** `AppDashboard::build($familyId,$today)` → `diary{count,latestTitle,latestStatus}` + `counts{toolsFree,books,trips}`; `home.php` и `AppController` читают ровно эти ключи (council-ключей больше нет). SW guard и manifest scope согласованы (`/`, оба префикса). Хелперы `plural_ru`/`diary_status_line`/`asset_ver`/`is_mobile_ua` — глобальные в `bootstrap.php`.

**Риск:** UA-детект грубый — мисдетект лишь меняет посадочную (лаунчер↔кабинет), оба рабочие. Зафиксировано в спеке.
