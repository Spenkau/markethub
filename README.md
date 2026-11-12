# MarketHub REST API

Laravel 13.40 / PHP 8.3, PostgreSQL 17, Sanctum, Predis/Memurai. Ветка: `refactor/api-first-postgresql`.

Приложение предоставляет JSON REST API `/api/v1`. Собственный Vue 3 SPA находится в `frontend/`: [запуск, страницы, API и Nginx](frontend/README.md). Серверной административной панели нет. С исходным backend-конфигом Nginx корень `/` возвращает статус и версию API; отдельный `infra/nginx-spa.conf` предназначен для собранного SPA. `GET /up` — стандартная проверка Laravel. Совместимый технический `GET /api/system-checks` сохранён; он не предоставляет бизнес-данные.

## Архитектура

- Контроллеры: `app/Http/Controllers/Api/V1`, авторизация — Policies.
- Входные данные: Form Requests, общие инварианты — `CatalogRules`, применяемые также моделями.
- Ответы: Laravel API Resources, стандартная пагинация и JSON ошибок.
- Eloquent-модели: Organization, User, Product, MarketplaceAccount, MarketplaceListing.
- `OrganizationContext` выбирает только подтверждённое членство, глобальная область ограничивает все бизнес-запросы активной организацией.
- `CatalogQuery` переиспользует запрос размещений для read-only остатков; таблица stocks не создаётся.
- Модели запрещают удаление при наличии размещений; доменная ошибка сериализуется как 409. Ограничения БД защищают от гонок и некорректных прямых записей.
- Денежные значения — целые минимальные единицы. API товара использует `price_minor`, в модели/БД сохранено `base_price_minor`.

## Окружение и конфигурация

Windows, без Docker/WSL. PHP: `C:\tools\php83`, Nginx: `C:\tools\nginx-1.30.6`, PostgreSQL: `C:\Program Files\PostgreSQL\17`, Memurai: `C:\Program Files\Memurai`.

PHP-расширения: PDO, pdo_pgsql, pgsql, ctype, curl, dom, fileinfo, filter, hash, intl, mbstring, openssl, session, tokenizer, xml, zip. pdo_sqlite нужен только для разового чтения старой резервной копии при переносе, приложение и тесты его не используют. Для API Node.js не требуется; для разработки и сборки SPA нужен Node.js 22.12+ и npm.

```dotenv
APP_URL=http://markethub.test
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=markethub_api_local
DB_USERNAME=markethub_api
DB_PASSWORD=<локальный пароль>
SESSION_DRIVER=file
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=predis
SANCTUM_STATEFUL_DOMAINS=markethub.test,app.markethub.test:5173,localhost:5173
FRONTEND_URL=http://markethub.test
```

Для текущей машины рабочая БД и отдельная тестовая БД уже подготовлены. Пароли находятся в `.env` / `.env.testing`, не в Git. Службы: `markethub-postgresql` и `Memurai`. Их настройки на этапе 4 не менялись, новое ПО не устанавливалось.

Для другой машины создайте через psql отдельные роли `markethub_api` и `markethub_api_test` с LOGIN, без SUPERUSER/CREATEDB/CREATEROLE; задайте пароли интерактивной командой `\password`. Создайте базы `markethub_api_local OWNER markethub_api` и `markethub_api_test OWNER markethub_api_test`, выполните `REVOKE ALL ON DATABASE <имя> FROM PUBLIC` для обеих. Владельцы могут мигрировать только свою БД; тестовая роль не имеет доступа к рабочей. Пароли вводите только в локальные env-файлы.

```powershell
composer install
Copy-Item .env.example .env
# Задайте DB_PASSWORD и APP_KEY локально.
php artisan key:generate
php artisan migrate
php artisan db:seed
```

## Запуск

Основной адрес сайта — **http://markethub.test**. Обычный `scripts/start.ps1` использует `infra/nginx-spa.conf` и отдаёт собранный Vue SPA вместе с Laravel API. Vite для этого не нужен. Перед первым запуском выполните `npm.cmd ci` и `npm.cmd run build` в `frontend/`; повторяйте сборку после изменений frontend. Флаг `-BackendOnly` запускает исходный API-конфиг.

```powershell
cd D:\Projects\markethub
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\start.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\stop.ps1
```

Nginx слушает 127.0.0.1:80 и передаёт запросы в `php-cgi.exe` на 127.0.0.1:9000. В hosts должна быть существующая запись `127.0.0.1 markethub.test`. Для управления службами используйте `scripts/services.ps1 -Action Start` / `Stop` в административном PowerShell. Обычные start/stop не требуют повышения прав. PostgreSQL и Memurai должны быть запущены.

Логи: `infra/runtime/logs`, `infra/runtime/php-error.log`, `storage/logs/laravel.log`.

## Авторизация и организации

Sanctum использует cookie-сессию Laravel. Браузерному клиенту не выдаются API-токены; таблица Sanctum предусмотрена стандартной миграцией, но endpoint выдачи токенов отсутствует. Публичной регистрации нет.

1. `GET /sanctum/csrf-cookie` с `credentials: include`.
2. `POST /api/v1/auth/login` с email/password и `X-XSRF-TOKEN` из URL-decoded cookie `XSRF-TOKEN`.
3. Передавайте cookies и заголовок Origin/Referer доверенного SPA. Для изменяющих запросов передавайте X-XSRF-TOKEN.
4. `GET /api/v1/auth/me`, `POST /api/v1/auth/logout`.

Лимит входа: 5 попыток в минуту для email/IP и 30 для IP. В production нужны HTTPS, secure cookies и точный список доверенных доменов/CORS. Будущий SPA должен находиться на том же основном домене; настройте FRONTEND_URL, SANCTUM_STATEFUL_DOMAINS и SESSION_DOMAIN для его поддомена. CORS разрешает credentials только FRONTEND_URL, не wildcard.

Локальная команда `php artisan markethub:admin` сохранена, включая пользовательские изменения ввода пароля. Она создаёт пользователя, не предоставляет публичную регистрацию и не изменяет существующие учётные записи. Пользователи получают доступ к API по членству в организации; признак is_admin не заменяет проверку членства. CLI-привязка демо разрешена локальному администратору:

```powershell
php artisan db:seed --class=DemoDataSeeder
php artisan markethub:demo-attach your-admin@example.com
```

`GET /api/v1/organizations` показывает только доступные организации. `PATCH /api/v1/organizations/active` с organization_id выбирает активную, проверяя членство через связь и Policy. Активный контекст сохраняется для пользователя; без него используется первое действующее членство. Чужие ID дают 404, отсутствие членства запрещает создание (403).

## API

CRUD: `/api/v1/products`, `/api/v1/marketplace-accounts`, `/api/v1/marketplace-listings` — GET/POST списка, GET/PATCH/DELETE записи. Остатки: `GET /api/v1/stocks`, без отдельной таблицы и без изменяющих маршрутов. Заказы не реализованы.

Списки: page, per_page (1–100, по умолчанию 20), search, sort, direction. Дополнительные фильтры, тела запросов и ответы: [docs/API.md](docs/API.md). Машиночитаемая спецификация: [docs/openapi.json](docs/openapi.json), OpenAPI 3.0.3, без дополнительных runtime-зависимостей.

## PostgreSQL, история миграций и сохранение данных

Принято решение создать **новую пустую** PostgreSQL-базу для API, а не изменять применённую схему SQLite или старую PostgreSQL-базу `markethub`. На этой ветке миграция `2026_11_10_000002_create_organization_catalog` является новым PostgreSQL-baseline: поля, ключи, индексы через Schema Builder, ограничения значений через ALTER TABLE CHECK PostgreSQL. Не применяйте её к старой SQLite-базе. Добавлена стандартная миграция таблицы Sanctum.

SQLite `database/markethub.sqlite` сохранён. Согласованная резервная копия и копии пользовательских правок/старого env находятся в `C:\tools\markethub-task4-backup`. Старое состояние схемы остаётся в истории Git (`92217d7`). Рабочая/тестовая PostgreSQL-базы разделены ролями и ACL.

Неразрушающий импорт:

```powershell
php artisan migrate
php artisan markethub:import-sqlite C:\path\to\backup.sqlite
```

Команда разрешена только в local/PostgreSQL и отказывается работать при наличии пользователей/бизнес-данных в целевой БД. Источник открывается только для чтения. Перенос транзакционный, сохраняет ID, даты, парольные хеши, членства и активные организации; последовательности PostgreSQL приводятся в соответствие с ID. Подключение к SQLite не входит в runtime-конфигурацию.

На этой машине перенесены: 1 пользователь, 1 организация, 1 действующее членство, 50 товаров, 2 подключения, 100 размещений и 2 технические записи. В исходнике дополнительно была связь user_id=1 с отсутствующим пользователем; она не импортирована, отмечена в отчёте, полностью сохранена в старом файле/резервной копии. Пароль существующего пользователя не изменялся. Рабочие базы не очищались; migrate:fresh/db:wipe на них не выполнялись.

DemoDataSeeder повторяемый: 50 товаров, два демоподключения, 100 размещений; firstOrCreate не перезаписывает изменения пользователя. Внешние ID SYNTHETIC- не выдаются за реальные ID WB/Ozon.

## Тесты

Создайте `.env.testing` из `.env.example`, задайте `APP_ENV=testing`, `DB_DATABASE=markethub_api_test`, `DB_USERNAME=markethub_api_test` и отдельный DB_PASSWORD. Тестовая роль не должна иметь CONNECT к рабочей БД.

```powershell
php artisan test
php vendor/bin/pint --test
composer validate --strict
composer check-platform-reqs
```

Тесты работают **только на PostgreSQL**. Защитная проверка в `Tests\TestCase` прекращает запуск до миграций, если драйвер не pgsql или имя базы не заканчивается `_test`. RefreshDatabase пересоздаёт исключительно изолированную тестовую схему. Проверяются auth, CSRF, rate limit, CRUD, фильтры/поиск/пагинация, ограничения цены/остатков, IDOR, членство, составные внешние ключи, отсутствие N+1 и повторяемость демосидера.

В HTTP-проверке через Nginx проверены реальные cookies/CSRF, login/me/logout, полный CRUD трёх ресурсов и остатки. Созданные проверкой временные записи и пользователь удалены. Настройки системного Memurai не менялись, запись/чтение кэша проверены.

## Склады и физические остатки

Добавлен отдельный модуль [«Склады и остатки»](docs/WAREHOUSES.md): три новые таблицы, транзакционные операции, неизменяемый журнал и защита от повторных запросов. Существующие `/api/v1/stocks` и `marketplace_listings.stock_quantity` относятся к размещениям на площадках. `/api/v1/warehouse-stocks` содержит физический складской учёт. Во Vue это `/app/marketplace-stocks` и `/app/stocks` соответственно; показатели не суммируются. Новые таблицы рабочей БД не заполняются демонстрационными остатками.

Описание обновлённого кабинета: [frontend CRM](docs/FRONTEND-CRM.md). Команды отдельного браузерного теста складского модуля и правила тестовой БД описаны в [WAREHOUSES.md](docs/WAREHOUSES.md).

## Закупки и поставки

Модуль [«Закупки и поставки»](docs/PROCUREMENT.md) добавляет поставщиков, заказы и частичные/полные приёмки. Подтверждение использует существующий StockOperationService и одну транзакцию с документом. Цены закупок в API представлены точными десятичными строками, а API каталога/размещений сохраняет price_minor. Новый раздел Vue: `/app/purchases`. Миграция создаёт пять таблиц и не заполняет рабочую базу примерами.

## Пользовательский путь и обучение

На обзоре добавлен реальный чек-лист начала работы, а разделы связаны переходами с выбранным товаром/поставщиком/складом. «Помощь и обучение» — `/app/help`. Семь экскурсий задаются в `frontend/src/features/onboarding/config/tours.ts`, прогресс и настройки сохраняются персонально через Sanctum. Установка: `php artisan migrate`, затем сборка frontend. Подробная инструкция добавления сценария, поведение и endpoints: [ONBOARDING.md](docs/ONBOARDING.md).

## Shopify

Реальная односторонняя интеграция Shopify → MarketHub: товары, варианты, локации и внешние остатки, ручной и автоматический импорт, история и обучение. После подтверждённого первого импорта по умолчанию обновляется каждый час при работающих Redis, worker и Scheduler. [Устройство, API, запуск на Windows и результаты проверки](docs/SHOPIFY.md).

Единый backend-фундамент подключений, внешний parent/variant, encrypted credentials и безопасный backfill реализованы в TASK8: [описание и проверка сохранности](docs/architecture/marketplace-foundation-task8.md).


Общая синхронизация, recovery и проверка миграции: [TASK9](docs/architecture/marketplace-sync-task9.md). Windows worker: `scripts/marketplace-workers.ps1`; проверка окружения: `php artisan marketplaces:sync-runtime`.

Общие внешние локации, остатки, фильтры и информационный mapping склада: [TASK10](docs/architecture/marketplace-inventory-task10.md).

Общий каталог, карточки local/external и capability-aware подключения: [TASK11](docs/architecture/marketplace-ui-task11.md).
