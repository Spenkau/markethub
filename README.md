# MarketHub

Local foundation for a CRM for Wildberries and Ozon sellers. Windows native environment, without Docker or WSL.

## Stack and paths

- PHP 8.3.36 NTS: `C:\tools\php83` (including `php-cgi.exe`).
- Laravel 13.40, Composer 2.11; dependencies pinned in `composer.lock`.
- PostgreSQL 17: `C:\Program Files\PostgreSQL\17`, service `markethub-postgresql`, port 5432.
- Memurai Developer 4.1.2 (Redis-compatible): `C:\Program Files\Memurai`, service `Memurai`, port 6379. Development use only.
- Nginx 1.30.6: `C:\tools\nginx-1.30.6`, listening on 127.0.0.1:80.
- Project: `D:\Projects\markethub`.
- FastCGI: `127.0.0.1:9000`, using PHP 8.3 explicitly.

Required PHP extensions: ctype, curl, dom, fileinfo, filter, hash, mbstring, openssl, pcre, PDO, pdo_pgsql, session, tokenizer, xml. Also enabled: pgsql, intl, zip, pdo_sqlite (isolated tests). Redis uses the Composer package `predis/predis`; a PHP Redis DLL is not needed.

## Start / stop

Open PowerShell:

```powershell
cd D:\Projects\markethub
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\start.ps1
```

Open **http://markethub.test**. Requests go through Nginx and PHP FastCGI. The start script performs an HTTP health check and refuses occupied ports.

Stop the project web processes:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\stop.ps1
```

PostgreSQL and Memurai are Windows services that start automatically. To stop the entire environment, stop the web processes first, then run this in an **administrator** PowerShell:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File D:\Projects\markethub\scripts\services.ps1 -Action Stop
```

To restart these services, use `-Action Start`, then run `scripts/start.ps1` normally. No administrator privileges are needed for the web start/stop scripts.

## Local configuration

`.env` contains local credentials and is excluded from Git. `.env.example` contains the configuration template without passwords. The application uses the PostgreSQL database `markethub` and a dedicated non-superuser role `markethub`. Cache and queues use Redis; sessions use local files. Queue workers can be started separately with `php artisan queue:work` when jobs are introduced.

The hosts file (`C:\Windows\System32\drivers\etc\hosts`) must contain:

```text
127.0.0.1 markethub.test
```

Editing hosts and installing/managing Windows services require administrator privileges. Nginx configuration is in `infra/nginx.conf`; adjust its absolute paths if relocating the project or Nginx.

For a fresh checkout with the native services already installed:

```powershell
composer install
Copy-Item .env.example .env
# Set DB_PASSWORD in .env; provision the database and role using PostgreSQL tools.
php artisan key:generate
php artisan migrate --seed
npm.cmd ci
npm.cmd run build
```

A PostgreSQL administrator can provision the role/database using psql: `CREATE ROLE markethub LOGIN;`, `\password markethub`, and `CREATE DATABASE markethub OWNER markethub;`. Use the password entered here as `DB_PASSWORD`. Do not use the postgres superuser for the application.

Use the Nginx scripts for local serving. Vite assets are built with `npm.cmd run build`; `npm.cmd run dev` can be used separately for asset development.

## API and verification

`GET http://markethub.test/api/system-checks` reads two technical records from PostgreSQL:

```json
{"data":[{"key":"application","value":"MarketHub"},{"key":"environment","value":"local"}]}
```

The idempotent `SystemCheckSeeder` populates these records. No demo users, products, orders, or marketplace records are created.

```powershell
php artisan test
composer check-platform-reqs
Invoke-RestMethod http://markethub.test/api/system-checks
```

The standard tests and API feature test use SQLite in memory, isolated from the development database. The API test verifies actual database reads and repeatable seeding. Live HTTP checks verify the PostgreSQL-backed environment separately.

Logs: `infra/runtime/logs/error.log`, `infra/runtime/logs/access.log`, `infra/runtime/php-error.log`, `storage/logs/laravel.log`. Runtime files, passwords, `.env`, dependencies, and build output are excluded from Git. PostgreSQL administrator credentials from initial provisioning are stored locally in `C:\tools\markethub-setup\credentials.json`; this file is outside the repository.
## Административная панель (этап 2)

Адрес: **http://markethub.test/admin**. Установлен Filament 5.11.2, интерфейс на русском языке. Бренд — MarketHub. Боковое меню адаптируется к мобильным экранам; на ноутбуке его можно свернуть. Светлая, тёмная и системная темы доступны в стандартном меню пользователя.

Разделы: Дашборд, Заказы, Товары, Остатки, Маркетплейсы, Аналитика, Настройки. Карточки показывают нулевую выручку, количество заказов, товаров и подключений. Разделы динамики продаж, последних заказов и интеграций показывают пустые состояния. Бизнес-модели, CRUD и API маркетплейсов пока не реализованы.

### Создание администратора

В обычном интерактивном PowerShell, из папки проекта:

```powershell
php artisan markethub:admin
```

Команда запросит имя, email, пароль и подтверждение пароля. Пароль вводится скрыто, не передаётся через аргументы и не выводится в терминал. Требуется не менее 12 символов, строчные и заглавные буквы, цифра и специальный символ. В БД сохраняется хеш пароля. Если терминал не поддерживает скрытый ввод, команда завершится ошибкой; используйте интерактивный PowerShell или Windows Terminal.

Можно заранее указать только несекретные поля:

```powershell
php artisan markethub:admin --name="Администратор" --email="you@example.com"
```

Команда работает только при `APP_ENV=local`. Она создаёт нового пользователя с `is_admin=true`, отклоняет занятый email и не изменяет права существующих пользователей. Используйте эту команду вместо стандартной `make:filament-user`: обычный пользователь не получает административный доступ автоматически. Пароли администратора не засеваются и не хранятся в репозитории. Постоянный администратор не создаётся автоматически: задайте собственные учётные данные командой выше.

Публичной регистрации нет. Гость перенаправляется на `/admin/login`. Доступ к каждой странице панели проверяет `User::canAccessPanel()`; обычный пользователь получает 403 даже в локальном окружении. Признак `is_admin` исключён из массового заполнения. Полноценная система ролей не добавлена. Вход и ограничение частоты попыток предоставляет Filament.

### Локальные хранилища

```dotenv
APP_LOCALE=ru
SESSION_DRIVER=file
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=predis
```

Сессии находятся в `storage/framework/sessions`. Memurai используется через Predis. Системная конфигурация службы Memurai на этом этапе не менялась. Проверка записи/чтения кэша и `PING` выполнялась на работающем локальном сервере.

### Проверки

```powershell
php artisan test
php vendor/bin/pint --test
```

Feature-тесты используют SQLite в памяти и не затрагивают PostgreSQL разработки. Проверяются все страницы для гостя, администратора и обычного пользователя, успешный и неуспешный вход, отсутствие регистрации, пустой дашборд и безопасное создание администратора. Опубликованные ресурсы Filament исключены из Git и восстанавливаются при `composer install` через `filament:upgrade`.