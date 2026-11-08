/* Service worker портала жителей — порт residents/src/Controller/PwaController::serviceWorker.
 *
 * Путь обязан остаться /poselenie/sw.js: легаси-PHP регистрировал SW именно там,
 * и у вернувшихся жителей старый SW обновляется по своему scriptURL — только
 * ответ на этом же пути заставит браузер снять с него контроль.
 *
 * Версия v6: при активации стираются ВСЕ прежние кеши (в skazapp-v4..v5 легаси
 * мог успеть сохранить страницы с ПДн — соседи, бюджет совета, лица, номера
 * машин). Меняйте версию при любом изменении состава кеша.
 *
 * Страницы и загруженные жителями фото в кеш НЕ кладём: сессия живёт 30 дней
 * и из неё почти не выходят — сохранённое лежало бы на устройстве бессрочно.
 */
const CACHE = 'skazapp-v6';

// Только то, что реально отдаётся как статика. Шрифты и CSS Astro бандлит с
// хешем в имени — их пути сюда нельзя писать руками: addAll на отсутствующем
// файле отменил бы всю установку SW.
const PRECACHE = [
  '/poselenie/offline/',
  '/images/Logo_SK_204x204.png',
];

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE)
      // add по одному: отсутствие одного файла не должно срывать установку.
      .then((c) => Promise.all(PRECACHE.map((u) => c.add(u).catch(() => undefined))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return; // POST-действия — только в сеть
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  if (!(url.pathname.startsWith('/poselenie') || url.pathname.startsWith('/sovet'))) return; // вне scope

  // API не перехватываем: /api/* ретрайнится клиентом, а не кешем, и ответы
  // содержат ПДн.
  if (url.pathname.startsWith('/api/')) return;

  // Навигация: только сеть. Страницу не сохраняем (ПДн), без сети — заглушка.
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match('/poselenie/offline/')));
    return;
  }

  // Оформление (картинки/стили/шрифты собранного сайта): кэш-первым. Загруженные
  // жителями фото (/images/posts/, /tg-media/) сюда не попадают — это ПДн.
  if (
    (url.pathname.startsWith('/_astro/') || url.pathname.startsWith('/images/')) &&
    !/^\/images\/(posts|plan)\//.test(url.pathname) &&
    /\.(css|woff2|png|jpg|jpeg|svg)$/.test(url.pathname)
  ) {
    e.respondWith(
      caches.match(req).then((hit) => hit || fetch(req).then((res) => {
        if (res.ok) {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy));
        }
        return res;
      }))
    );
  }
});
