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
    private const CACHE_VERSION = 'skazapp-v3';

    public function manifest(): void
    {
        header('Content-Type: application/manifest+json; charset=utf-8');
        echo json_encode([
            'name'             => 'Сказочный Край',
            'short_name'       => 'Сказочный Край',
            'lang'             => 'ru',
            'start_url'        => '/poselenie/app',
            'scope'            => '/',
            'display'          => 'standalone',
            'orientation'      => 'portrait',
            'background_color' => '#fbfaf6',
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
