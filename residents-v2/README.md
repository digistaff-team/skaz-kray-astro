# residents-v2 — README

## Запуск локально

```bash
cd residents-v2
cp .env.example .env
# Заполнить .env (SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY, JWT_SECRET и т.д.)

npm install
npx prisma generate
npx prisma db push  # создать схему в Supabase
npm run dev
```

## Деплой на сервер

```bash
# На сервере abconsult (или новом):
MIGRATE=1 bash residents-v2/deploy-v2.sh
```

## systemd-сервис

```ini
# /etc/systemd/system/skaz-residents-v2.service
[Unit]
Description=Skaz Kray Residents V2
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/skaz-residents-v2
ExecStart=/usr/bin/node dist/server.js
Restart=on-failure
RestartSec=5
Environment=NODE_ENV=production

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable skaz-residents-v2
sudo systemctl start skaz-residents-v2
sudo systemctl status skaz-residents-v2
```

## Переменные окружения

См. `.env.example` — полный список:
- `SUPABASE_URL` / `SUPABASE_SERVICE_ROLE_KEY` — подключение к Supabase
- `JWT_SECRET` — секрет для JWT-токенов (генерировать случайный)
- `SMTP_*` — настройки почты
- `SKAZKRAY_BOT_TOKEN` — токен Telegram бота
- `SKAZKRAY_MAX_BOT_TOKEN` — токен MAX бота
- `BASE_URL` — https://skaz-kray.ru

## Структура

```
residents-v2/
├── prisma/
│   └── schema.prisma          # Схема БД (Supabase/PostgreSQL)
├── src/
│   ├── server.ts              # Точка входа (Express)
│   ├── lib/                   # Supabase, JWT, crypto, mailer, CSRF
│   ├── middleware/            # requireAuth, requireAdmin, requireEditor
│   └── routes/                # Контроллеры (1:1 с оригиналом)
├── deploy-v2.sh               # Скрипт деплоя
├── deploy/nginx-v2.conf.example
└── .env.example
```

## Миграция

1. Создать проект в Supabase
2. Заполнить `.env`
3. `npx prisma db push` — применить схему
4. Перенести данные из MySQL в Supabase (см. скрипт миграции)
5. Деплой через `deploy-v2.sh`
6. Настроить nginx и systemd
