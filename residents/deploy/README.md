# Установка раздела жителей на сервере

Сервер: `ssh abconsult` (root@31.128.43.151), тот же, что и статика skaz-kray.ru.

## 1. База данных
```sql
CREATE DATABASE skazkray_residents CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'skaz_residents'@'127.0.0.1' IDENTIFIED BY '<СИЛЬНЫЙ_ПАРОЛЬ>';
GRANT SELECT, INSERT, UPDATE, DELETE ON skazkray_residents.* TO 'skaz_residents'@'127.0.0.1';
FLUSH PRIVILEGES;
```
Накатить схему:
```
mysql skazkray_residents < /var/www/skaz-residents/config/schema.sql
```

## 2. Конфиг (секреты, вне git)
```
cp /var/www/skaz-residents/config/config.example.php /var/www/skaz-residents/config/config.php
chmod 640 /var/www/skaz-residents/config/config.php
chown root:www-data /var/www/skaz-residents/config/config.php
# заполнить: db.pass, smtp.* (ящик на skaz-kray.ru), при необходимости uploads_dir
```

## 3. Редактор-модератор
Завести аккаунт редактора (через регистрацию на сайте, затем в БД):
```sql
UPDATE families SET status='active', role='editor' WHERE email='<email редактора>';
```

## 4. nginx
Вставить блоки из `deploy/nginx-residents.conf.example` в vhost `skaz-kray_ru_astro`
(ориентир — рабочие блоки /oauth/ и /editor-auth/), затем:
```
nginx -t && systemctl reload nginx
```

## 5. Автодеплой статики не конфликтует
Приложение живёт в `/var/www/skaz-residents/`, вне докрута статики
(`/var/www/new.skaz-kray.ru/html`). `skaz-kray-autodeploy.sh` его не трогает —
дополнительных `--exclude` не требуется.

## 6. Обновление кода
Локально: `bash residents/deploy/deploy.sh`

## 7. Раздел «Попечительский совет» (/sovet/)
Живёт в том же приложении и БД, но с отдельной таблицей аккаунтов
`council_members` (email независим от `families`).

1. Накатить схему совета (один раз):
   ```
   mysql skazkray_residents < /var/www/skaz-residents/config/council-schema.sql
   ```
2. Завести первого администратора совета (дальше он добавляет остальных через
   веб-интерфейс `/sovet/upravlenie`):
   ```
   cd /var/www/skaz-residents
   php bin/council-admin.php <email> "<Имя>" "<пароль>"
   ```
3. nginx: блоки `/sovet` уже включены в `deploy/nginx-residents.conf.example`
   (location `= /sovet` и `^~ /sovet/` → тот же фронт-контроллер). После
   вставки — `nginx -t && systemctl reload nginx`.
4. Контент (состав совета, документы, протоколы, ближайшее собрание, направления)
   правится в коде — `src/CouncilData.php` (ссылки на Google Docs — заменить
   плейсхолдеры `PLACEHOLDER-*` на реальные). Доска задач `/sovet/zadachi`
   редактируется членами совета прямо в интерфейсе.

## 8. Шеринг инструментов (/poselenie/instrumenty)
Часть раздела жителей: жители делятся своими инструментами (P2P), заявку
одобряет владелец. Модерации нет. Фото — в существующей таблице `images`
(owner_type='tool'), каталог виден только вошедшим жителям.

1. Накатить схему инструментов (один раз, в ту же БД):
   ```
   mysql skazkray_residents < /var/www/skaz-residents/config/tools-schema.sql
   ```
2. Отдельных env/nginx-правок не нужно — всё под уже настроенным `/poselenie/`.
   Пункт меню «Инструменты» ведёт на `/poselenie/instrumenty`.
