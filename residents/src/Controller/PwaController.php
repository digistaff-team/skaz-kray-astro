<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

/**
 * Отдаёт PWA-манифест и service worker как статические ответы с корректным MIME.
 * Через PHP front-controller — чтобы не править nginx. sw.js — no-cache (браузер
 * должен получать свежую версию). Публичны (без ПДн). SW scope — / (единый PWA
 * на оба раздела: /poselenie и /sovet; заголовок Service-Worker-Allowed: /).
 */
final class PwaController
{
    private const CACHE_VERSION = 'skazapp-v4';

    /** Общие поля манифеста; иконки берём текущие. */
    private function baseManifest(): array
    {
        return [
            'id'               => '/poselenie/app',
            'name'             => 'Сказочный Край',
            'short_name'       => 'Сказочный Край',
            'lang'             => 'ru',
            'start_url'        => '/poselenie/app',
            // scope жителей ограничен /poselenie, чтобы НЕ перекрывать /sovet
            // (иначе установленное приложение жителей перехватывает ярлык Совета).
            'scope'            => '/poselenie',
            'display'          => 'standalone',
            'orientation'      => 'portrait',
            'background_color' => '#fbfaf6',
            'theme_color'      => '#008757',
            'icons'            => [
                ['src' => '/poselenie/assets/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/poselenie/assets/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/poselenie/assets/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];
    }

    private function emitManifest(array $data): void
    {
        header('Content-Type: application/manifest+json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public function manifest(): void
    {
        $this->emitManifest($this->baseManifest());
    }

    /**
     * Отдельный манифест для раздела «Совет»: свой id, scope=/sovet и
     * start_url=/sovet, чтобы это было отдельное установленное приложение,
     * а ярлык открывал именно портал Совета (а не общий /poselenie/app).
     * Свой id + узкий scope обязательны: иначе браузер объединяет оба
     * манифеста (один scope '/') в одно приложение и запускает главное.
     */
    public function manifestSovet(): void
    {
        $this->emitManifest(array_merge($this->baseManifest(), [
            'id'         => '/sovet',
            'name'       => 'Попечительский совет',
            'short_name' => 'Совет',
            'start_url'  => '/sovet',
            'scope'      => '/sovet',
        ]));
    }

    public function serviceWorker(): void
    {
        header('Content-Type: text/javascript; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Service-Worker-Allowed: /');
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
  if (!(url.pathname.startsWith('/poselenie/') || url.pathname.startsWith('/sovet/'))) return; // вне scope

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
  if (/\\.(css|woff2|png|jpg|svg)\$/.test(url.pathname)) {
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
