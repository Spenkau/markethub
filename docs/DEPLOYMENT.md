# Развёртывание MarketHub

Шаблоны находятся в `infra/production`. Они подготовлены для отдельного Linux VPS с Nginx, PHP-FPM, PostgreSQL и Redis. На реальном сервере этот релиз ещё не развёрнут. `example.invalid` — обязательный к замене пример, публичной демоверсии по этому адресу нет.

## Окружение

Нужны PHP 8.3+ с PDO PostgreSQL, mbstring, intl, curl, dom/XML, zip, fileinfo, openssl; Composer 2; Node.js 22.12+ и npm; PostgreSQL 17; Redis; Nginx; systemd и cron. Версии PHP должны удовлетворять `composer.lock`: проверьте `composer check-platform-reqs --no-dev`. Docker не нужен.

Создайте системного пользователя `markethub`, каталоги `/srv/markethub/releases` и `/srv/markethub/shared/storage/{app,framework/cache/data,framework/sessions,framework/views,logs}`. Приложение и worker работают от `markethub`; Nginx читает только статическую сборку. `.env` хранится в `/srv/markethub/shared/.env`, права 0600. Дайте пользователю приложения права записи только в shared/storage и bootstrap/cache; исходники не должны быть доступны для записи веб-процессам без необходимости. Настройте ротацию application/scheduler/FPM logs.

Создайте отдельную PostgreSQL LOGIN роль без SUPERUSER, CREATEDB и CREATEROLE, задайте пароль через интерактивную `\password`. Создайте базу `markethub_demo` с этой ролью как OWNER; отзовите PUBLIC CONNECT и права на schema public, выдайте права владельцу. Не давайте demo-роли доступ к рабочим базам. Redis слушает loopback, с паролем/ACL; выделите экземпляр или индексы и префикс для этого приложения. PostgreSQL и Redis не открывайте в Интернет.

## Первый релиз

1. В новый `/srv/markethub/releases/<commit>` получите проверенный commit из GitHub. Не копируйте локальные `.env`, vendor, node_modules или рабочую БД.
2. Скопируйте `infra/production/env.example` в shared/.env и заполните домен, пароли. `PUBLIC_DEMO_DATABASE` должен точно совпадать с `DB_DATABASE`, имя заканчивается `_demo`. Для обычного рабочего экземпляра установите `PUBLIC_DEMO_ENABLED=false` и используйте другую БД. Не смешивайте режимы.
3. В каталоге релиза выполните:

```sh
ln -s /srv/markethub/shared/.env .env
# Сначала сохраните исходный пустой storage вне релиза, затем замените его ссылкой.
mv storage storage.initial
ln -s /srv/markethub/shared/storage storage
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
php artisan key:generate --force
cd frontend
npm ci
npm run build
cd ..
php artisan migrate --force
php artisan markethub:demo-bootstrap --confirm-environment=production --confirm-database=markethub_demo
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Bootstrap запросит скрытый пароль (16+ символов); посетителю он не нужен. Команда не мигрирует и не очищает базу, откажется работать с непустой базой или неподтверждённым окружением. Создаёт одного demo-пользователя, одну организацию и синтетические товары, остатки, подключения, закупки и приёмки. Никаких реальных токенов или внешних запросов. Не запускайте `DemoDataSeeder` в production.

`key:generate` выполняется **только при первой установке**. Существующий APP_KEY сохраняется при всех обновлениях: иначе потеряется возможность расшифровывать credentials и сессии. Храните его резервную копию вне Git.

4. Создайте `/srv/markethub/current` как symlink на готовый релиз. В pool directory установленного PHP-FPM поместите `php-fpm.conf`, проверьте конфигурацию командой соответствующего `php-fpm -t`, запустите сервис. Путь сокета согласован с Nginx.
5. Получите HTTPS-сертификат для реального домена через ACME. Для первоначального HTTP-01 challenge временно используйте отдельный HTTP webroot/vhost; готовый HTTPS-конфиг требует уже существующие сертификаты. Замените домен и пути сертификатов в nginx.conf, установите vhost, выполните `nginx -t`, затем reload. Настройте автоматическое продление и reload Nginx после продления. Откройте только 80/443 и ограниченный SSH.
6. Установите `markethub-worker.service` в `/etc/systemd/system`, `scheduler.cron` в `/etc/cron.d/markethub`. Выполните `systemctl daemon-reload` и `systemctl enable --now markethub-worker`. Scheduler запускает диспетчер синхронизаций раз в минуту; demo-данные не вызывают внешние API. Не запускайте второй scheduler для того же экземпляра.

SPA и API работают на одном HTTPS origin: Nginx отправляет `/api/*`, `/sanctum/*`, `/up` в Laravel, остальные маршруты — в SPA. PHP-файлы, dotfiles и исходники напрямую недоступны. Для reverse proxy отдельно настройте trusted proxies и HTTPS forwarding в соответствии с инфраструктурой; текущий шаблон рассчитан на прямое TLS-завершение в Nginx.

## Проверка

- `curl -f https://<домен>/up`, `/` и deep link `/app/products` возвращают приложение, не 502/404.
- Landing показывает реальную картинку и кнопку «Перейти в демо версию». Вход работает без пароля, создаёт серверную сессию с CSRF; после logout возвращает на `/login`.
- Demo: каталог/карточки/галерея, dashboard, история, физические и внешние остатки, закупки, фильтры, XLSX и обучение доступны. POST/PATCH/DELETE бизнес-данных возвращают 403 даже при прямом запросе с валидным CSRF.
- HTTPS cookies Secure/HttpOnly, APP_DEBUG=false; проверить отсутствие stack traces. Проверить таймауты, ограничения входа, tenant isolation и session logout.
- `systemctl status markethub-worker`, `php artisan queue:failed`, `php artisan schedule:list`; проверить scheduler log. Настроить мониторинг /up, диска, очередей и резервных копий.

## Резервные копии и восстановление

Перед миграциями и регулярно делайте PostgreSQL custom-format backup: `pg_dump --format=custom --file=/secure-backups/markethub.dump markethub_demo`. Пароли передавайте через защищённый `.pgpass`, не аргументами shell. Шифруйте и храните offsite копии БД, `.env`/APP_KEY и shared/storage; ограничьте доступ, задайте срок хранения. `public` demo-assets и fonts воспроизводятся из Git.

Проверяйте восстановление в **новую отдельную базу**: создайте её и выполните `pg_restore --no-owner --no-acl --dbname=<новая_база> /secure-backups/markethub.dump` от её владельца. Согласуйте env и demo database guard, проверьте /up, вход и выборку данных до переключения. Не восстанавливайте поверх работающей базы без maintenance и отдельного подтверждённого плана. Bootstrap на восстановленной базе не нужен.

## Обновление и откат

Сохраните текущий SHA и backup. Подготовьте новый каталог релиза, установите зависимости и соберите frontend до переключения. Подключите существующие shared env/storage, **не генерируйте ключ**. Для несовместимых миграций включите `php artisan down`, остановите worker, выполните миграции с `--force`, кеши. Атомарно переключите current через временную ссылку и `mv -Tf`, перезагрузите FPM/opcache, перезапустите worker, выполните `php artisan up` и smoke проверки. Не оставляйте maintenance во время ошибки: сначала оцените совместимость схемы.

Для отката кода переключите current на предыдущий релиз и перезапустите FPM/worker. Это безопасно только при совместимой схеме. Если схема несовместима, восстановите проверенный backup в отдельную базу и переключите env; учтите записи после backup. Не запускайте автоматически `migrate:rollback` и не удаляйте старые релизы/backup до успешной проверки. Домен, DNS, VPS, сертификаты и фактический deployment остаются внешними действиями владельца.
