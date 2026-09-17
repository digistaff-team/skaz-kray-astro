# /tg-media — раздача фото из приватного Telegram-канала

`serve.php` живёт на сервере в docroot внешнего сайта:
`/var/www/new.skaz-kray.ru/html/tg-media/serve.php` (рядом — `config.php` с
токеном бота, вне git, и каталог `cache/`).

Здесь он держится **только как исходник под версионированием**: автодеплой
статики (`skaz-kray-autodeploy.sh`) синхронизирует `dist/` в docroot с
`--delete`, но `tg-media/` из синхронизации исключён — иначе сборка снесла бы
кеш фото. Поэтому файл в `public/` класть нельзя, а деплой — ручной:

```bash
scp server/tg-media/serve.php abconsult:/var/www/new.skaz-kray.ru/html/tg-media/serve.php
ssh abconsult 'chown www-data:www-data /var/www/new.skaz-kray.ru/html/tg-media/serve.php'
```

## Что отдаёт

| URL | Что |
|---|---|
| `/tg-media/<file_id>.jpg` | оригинал (как пришёл из Telegram, ~1280px) |
| `/tg-media/w<W>/<file_id>.jpg` | копия шириной `W` — только 120, 240 или 480 |

Первый запрос идёт в Bot API (`getFile` + скачивание) и кладёт файл в
`cache/` (миниатюру — в `cache/w<W>/`). Дальше nginx отдаёт его статикой мимо
PHP: `try_files` в `/etc/nginx/sites-enabled/skaz-kray_ru_astro`.

Белый список ширин обязателен: без него любой желающий заказал бы произвольные
размеры и забил диск вариантами одного фото.
