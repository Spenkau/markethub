# MarketHub — финальный релиз JAROVIT

Дата проверки: 13 ноября 2026. Задание: TASK16. Проект: [Spenkau/markethub](https://github.com/Spenkau/markethub).

## Visual design

Референс — [живой shadcn-admin](https://shadcn-admin.netlify.app/) и [исходники Sat Naing](https://github.com/satnaing/shadcn-admin), изученный commit `e16c87f213a5ba5e45964e9b67c792105ec74d26`. Изучены Dashboard, Tasks, Users, Settings, layout/header/sidebar и theme.css. MIT notice сохранён в `docs/licenses/shadcn-admin-MIT.txt`.

Vue 3/TypeScript/Vite сохранены. Вместо переноса React-компонентов адаптированы геометрия, нейтральная палитра, типографика и состояния существующих элементов. `main.css` содержит общие tokens/primitives/shell, `workspace.css` — раскладки предметных страниц. Старые конфликтующие темы заменены; dashboard больше не переопределяет общую систему локальным scoped CSS. Product Tours используют ту же палитру.

| Параметр | Референс / результат адаптации |
| --- | --- |
| Sidebar | desktop rail 256 px; внутренний блок reference 240 px, замеренный DOM. MarketHub 256 px; свернутый 72 px; мобильный drawer 288 px |
| Header | 64 px, проверяется в браузере |
| Шрифт | Inter, локальный variable TTF с кириллицей; SIL OFL в docs/licenses |
| Background / foreground | oklch(1 0 0) / oklch(.129 .042 264.695) |
| Primary / muted | oklch(.208 .042 265.755) / oklch(.968 .007 247.896) |
| Border | oklch(.929 .013 255.508) |
| Controls | default 36 px, sm 32 px, lg 44 px; focus-visible, hover, disabled, aria-busy |
| Radius / spacing | controls 6 px, cards 10 px; шаги 4/8/12/16/24/32 px |

Sidebar содержит только реальные разделы: обзор, товары, склад, внешние остатки, подключения, поставщики, закупки, помощь. Название страницы находится в header; выбор организации доступен в header на широком экране и sidebar на узком. Sidebar сворачивается, мобильное меню поддерживает Escape, Tab и возврат фокуса; закрытое меню inert. Demo помечено постоянно, выход сохранён.

Общий стиль применён к landing, dashboard, каталогу, local/external карточкам и галерее, списку и созданию подключений, пяти вкладкам подключения, внешним остаткам, складам/остаткам/движениям, поставщикам, закупкам/деталям/приёмкам, product create/edit, вспомогательным диалогам, onboarding и Product Tours. Бизнес-логика и tour anchors сохранены.

### Screenshot comparisons

- [Reference Dashboard 1440](../assets/reference/1440-dashboard.png) ↔ [MarketHub Dashboard 1440](../assets/visual/1440/dashboard.png).
- [Reference Tasks 1440](../assets/reference/1440-tasks.png) ↔ [каталог MarketHub](../assets/visual/1440/catalog.png): границы, таблица, фильтры, типографика.
- [Reference Settings 1440](../assets/reference/1440-settings.png) ↔ [форма подключения](../assets/visual/1440/connection-form.png): контролы, поля, отступы.
- Reference и MarketHub дополнительно сняты на 1280, 1024, 768 и 390 px; полные каталоги — [reference](../assets/reference) и [visual](../assets/visual).
- Парные контактные листы: [1440](../assets/comparison/1440.png), [1280](../assets/comparison/1280.png), [1024](../assets/comparison/1024.png), [768](../assets/comparison/768.png), [390](../assets/comparison/390.png). Компактные кадры для README хранятся отдельно в screenshots; полная матрица не обрезана ради оформления документации.
- MarketHub снят также на 375 px. Во время съёмки reference на 375 px Netlify вернул страницу проверки соединения. Этот кадр не используется как дизайн-эталон; прямое визуальное сравнение reference на 375 px не подтверждено.

Фактически сравнивались видимые screenshots и числовая геометрия shell/контролов. Исправлены переполнение header на планшете, двухколоночная карточка на 768 px, конфликт размеров миниатюр и слишком узкие dashboard-карточки. Числовые замеры сохранены в geometry.json. Автоматического утверждения о пиксельной идентичности разных бизнес-страниц нет: данные, фильтры, readonly banner и состав модулей MarketHub отличаются от шаблона. Не добавлялись фиктивные графики продаж/выручки ради сходства.

## Demo

На landing нет публичных ссылок обычного входа. CTA: **«Войти в демонстрационный аккаунт»**. GET `/api/v1/auth/demo` проверяет доступность; POST с обычным Sanctum CSRF выбирает подготовленного пользователя и регенерирует серверную сессию. В браузер не передаются demo-пароль и credentials. Есть loading/error/unavailable состояния; кнопка блокирует повторное нажатие.

Подготовка: новая пустая PostgreSQL `_demo` база, отдельные роль/env/APP_KEY; миграции, затем `markethub:demo-bootstrap` с точным подтверждением production и имени базы. Полный порядок — [DEPLOYMENT](../DEPLOYMENT.md). Команда не очищает и не мигрирует базу; повторный bootstrap отказывается менять существующие данные. В `_test` разрешена только в testing.

Синтетические fixtures включают локальные товары, WB/Ozon demo, Shopify-like display-only данные, варианты, локации, положительные/нулевые/неизвестные остатки, сопоставленные и несопоставленные варианты, склады, движения, поставщика, частичную и полную приёмку, историю синхронизации. Нет реального домена магазина, токенов и внешних запросов. Landing preview — настоящий screenshot этого кабинета.

Demo middleware запрещает небезопасные бизнес-методы до model binding, Policies дают дополнительную защиту. Импорт, sync, refresh, mapping, изменения организации/каталога/склада/закупок недоступны. Разрешены просмотр, поиск, фильтры, read-only XLSX и обучение. Demo tours пишут только в sessionStorage. CSRF, throttling, tenant isolation и logout не отключались.

## Functional verification

Backend: **176 passed, 1424 assertions, 92.31 s**, exit 0. Frontend: TypeScript и ESLint — exit 0; Vitest — **10 passed в 3 файлах**; production build — **1748 modules**, exit 0. `composer validate --strict`, `composer audit`, полный `npm audit` и проверка production npm-зависимостей — успешно, известных уязвимостей не найдено на дату проверки.

Backend и E2E используют выделенную PostgreSQL `markethub_api_test`; рабочая база и реальное подключение Shopify не мигрировались и не синхронизировались. Браузерные проверки запускаются последовательно, не одновременно с backend suite.

`npm run test:e2e:regression`: **20 passed, 4.4 min, exit 0**. Проверены Sanctum/восстановление сессии/logout, CRUD/фильтры/пагинация, dashboard/смена организации, demo loading/error/403/readonly/export, галерея/fallback/focus, складские операции и idempotency, onboarding/tours/reload/предложения, закупки/частичная и полная приёмка/повтор подтверждения, общий Shopify UI/mapping/настройки/внешние остатки/совместимые redirects. Shopify API в тестах подменён, рабочая интеграция не вызывалась.

В промежуточных прогонах обнаружены и устранены: отсутствующий favicon (единственный console 404) и устаревший locator desktop-select в мобильном тесте смены организации. Повторная полная регрессия после исправлений завершилась без падений. Demo CTA дополнительно проверяется на неизменность bounding box при загрузке и честную обработку HTTP 503 на 1280 и 375 px.

Матрица: 1440×900, 1280×800, 1024×768, 768×1024, 390×844, 375×812. Проверяются реальные маршруты, отсутствие document overflow и broken images, загрузка данных, browser errors, галерея, tour, sidebar и диалоги. Формы снимаются под обычным тестовым пользователем на тех же синтетических fixtures, не через обход demo-защиты production.

Финальный прогон полной матрицы: **2 passed, 8.3 min, exit 0**. Первый сценарий проверил 20 маршрутов/вкладок, landing, галерею, tour и sidebar на каждом viewport; ошибок JavaScript/console, broken images и document overflow не обнаружено. Второй снял create/edit товара, подключение, склад, поставщика, закупку, складскую операцию, приёмку, mapping и подтверждение удаления на всех шести размерах. Проверены фокус первого поля, Tab/Shift+Tab, Escape и возврат фокуса. Таблицы прокручиваются внутри собственного контейнера, не расширяя документ.

## Deployment readiness

Подготовлены `infra/production/nginx.conf`, `php-fpm.conf`, `markethub-worker.service`, `scheduler.cron`, `env.example` и [runbook](../DEPLOYMENT.md). SPA/API работают на одном HTTPS origin. Nginx отдаёт dist с deep-link fallback, PHP доступен только через internal front controller; env/source files наружу не обслуживаются.

Worker согласован с SyncMarketplace: timeout 1800 s, Redis retry_after 1900 s, systemd TimeoutStopSec 1900 s. Scheduler — один cron каждые 60 s. Описаны установка, migration/cache, сохранение APP_KEY, bootstrap demo, smoke checks, ротация логов, offsite backup, восстановление в отдельную БД, обновление releases/current и откат с учётом совместимости схемы.

В отдельном clean checkout выполнены `composer install --no-dev --prefer-dist --no-interaction`, `composer check-platform-reqs --no-dev`, `npm ci`, production build. Без копирования рабочего env прошли `config:cache`, `route:cache`, `view:cache` и help demo bootstrap. Проверка обнаружила отсутствующий в Git каталог resources/views: добавлен служебный .gitignore, сохраняющий стандартный view path Laravel даже у API/SPA-приложения. После исправления все три cache-команды прошли повторно. Зависимости только из lock-файлов; рабочие credentials в checkout не переносились.

Реального Linux VPS, домена и сертификата в этом задании нет. Production templates не заявляются как проверенные на работающем Linux Nginx/FPM/systemd; фактическая установка и end-to-end HTTPS smoke требуют сервера. Не выполнялись deployment, DNS, выпуск сертификата, восстановление production backup и миграции рабочей базы. Пример `example.invalid` в конфигурации нужно заменить.

## GitHub

Подготовлены к включению незакоммиченные dashboard TASK12, безопасное demo FINAL1, визуальный рефакторинг TASK16, fixtures, тесты, шрифты/лицензии, screenshots, deployment и документация. Исключены `tg-bots`, TASKS, env, APP_KEY, credentials, DB backups, node_modules/vendor, reference clone и runtime logs. Чужие каталоги не удалялись.

Исходный `origin/main` после fetch: `92217d7a5c96a65e6f925bb8195318f99fe5b145`. Он является предком текущей `refactor/api-first-postgresql`; dry-run подтвердил возможность обычного fast-forward обеих веток. План публикации — atomic push в `main` и существующую рабочую ветку, без force push, принудительного merge, переписывания истории и удаления remote branches. Настройка default branch остаётся `main`.

Коммиты реализации:

| SHA | Состав |
| --- | --- |
| `33ba24ec645d3b413e4a62b4fd9e3acc1fda264a` | Dashboard API, изолированное readonly demo, fixtures, guards, API tests и документация контрактов |
| `9ff836a3fe9ea428e6d76905f574e5a570c491ef` | Единый Vue-интерфейс, instant demo, fonts/licenses, галерея, tours и browser tooling |
| `3f6d5046959f371e5d15bee8c5921ce5ae1e5d0e` | Наличие Laravel view path в clean checkout для production cache warmup |
| `f8b05faac8563d6662bcec9063b89098c69be4fe` | Стабильная загрузка demo CTA, мобильная смена организации, расширенные browser checks |

Аудит перед публикацией: поиск известных секретных значений из локальных env без их вывода, сигнатур private keys/GitHub/AWS/Shopify tokens в кандидатах и всех доступных history blobs. На предварительной проверке: 538 файлов и 513 history blobs, совпадений нет. Это проверка заданных сигнатур/известных значений, а не математическое доказательство отсутствия любого возможного секрета. Дополнительно проверяются staged paths, отсутствие вложенных репозиториев и diff --check.

Релизный пакет включает 232 PNG: матрицу приложения, восемь кадров README, reference и сравнительные листы. Отдельный сценарий portfolio assets прошёл: **1 passed, 21.4 s, exit 0**; preview снят после загрузки организации и данных. Модальные окна снимаются в пределах viewport, страницы — целиком.

Повторный прогон форм после изменения способа съёмки: **1 passed, 2.2 min, exit 0**. Мобильный mapping проверен визуально. Production build, lint, typecheck и проверка 36 относительных ссылок документации завершились успешно.

Обнаруженный параллельный пользовательский `scripts/test.php` исключён из релизных коммитов; его staged и рабочая версии сохранены. Коммиты выполняются с явным списком путей.

## Итог

Внешние действия: приобрести/подготовить VPS, выбрать домен, настроить DNS, секреты, PostgreSQL/Redis, HTTPS и выполнить фактический deployment по runbook. Публичный URL до этого не заявляется. Новых архитектурных этапов для портфолио-ядра не требуется; решение о завершении релиза подтверждается итоговыми тестами и GitHub verification ниже.
