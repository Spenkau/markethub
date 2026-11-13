# MarketHub — финальный аудит готовности портфолио

> Аудит фиксирует состояние TASK14 до завершающих изменений. Актуальное состояние — в [финальном релизе](markethub-final-release.md).

Дата: 13 ноября 2026. Задание: `D:/Projects/TASK14.md`. Разработчик проекта: **JAROVIT**.

## 1. Executive summary

**Продуктового ядра достаточно для убедительного портфолио. Новые бизнес-модули не нужны. Публичное размещение текущей рабочей копии без подготовки пока преждевременно.**

Проверен действующий код Laravel/Vue, а не только прежние отчёты. Реализованы cookie-авторизация, организации, единый каталог local/external, карточки и варианты, mapping, реальный read-only Shopify-импорт, общая история и recovery синхронизаций, внешние остатки, физический склад, закупки/приёмки, Excel и обучение. WB/Ozon — исключительно demo. Заказов покупателей нет; закупки у поставщиков не являются их заменой.

Актуальные backend-тесты: **169 passed, 1328 assertions**. TypeScript, ESLint, 10 frontend unit-тестов и production build прошли. Проверка зависимостей Composer и npm не нашла известных уязвимостей на дату аудита. Детали браузерных прогонов и их ограничения приведены в разделе 15.

Основные препятствия завершению: нет защищённого публичного demo-режима, production bootstrap и Linux deployment runbook; лендинг/часть документации устарели; есть небольшие воспроизводимые UX-дефекты. Уже сделанный dashboard не требует повторной реализации TASK12.

**Минимум до законченной портфолио-версии — три завершающих этапа:** безопасное и честное demo с небольшим исправлением UX; подготовка production-окружения; оформление GitHub и итоговая проверка. Деплой и release commit в этом аудите не выполняются.

## 2. Готовность проекта в целом и границы проверки

### Состояние репозитория

- Ветка: `refactor/api-first-postgresql`; HEAD на начало аудита: `44f707c` — `Unify catalog, product details and marketplace connection UI`.
- Remote: `https://github.com/Spenkau/markethub.git`. История доступной локальной копии: 9 коммитов. Состояние GitHub после возможных внешних изменений не утверждается: fetch/push не выполнялись.
- Предыдущие этапы: `fa420be` — внешние остатки, `96d9d1b` — общая синхронизация, `43fef7c` — склад/закупки/экспорт/onboarding.
- На старте уже изменены 13 tracked-файлов: `CatalogQuery`, `MarketplaceInventoryQuery`, `SyncDispatcher`, четыре frontend-направления (каталог/остатки/закупки/dashboard), tours, package scripts, три E2E-файла и routes. Также есть 9 untracked-файлов dashboard: controller/request/query, API client, тесты, два Playwright config и отчёт TASK12. Они входят в проверенную рабочую копию, но **не входят в HEAD**.
- Отдельный untracked `tg-bots/` — посторонний клонированный репозиторий из предыдущей задачи. Не добавлять его в release MarketHub. Удаления в рамках TASK14 нет.
- `AGENTS.md` и `CURRENT_STATE.md` в проверенных корне/родительских каталогах не найдены. Изучены предыдущий unification audit и отчёты TASK8–TASK11; существующий документ dashboard сверялся с кодом.

### Метод и ограничения

Изменения бизнес-кода, credentials, рабочей БД, настроек магазина и расписаний не выполнялись. Backend и изменяющие браузерные fixtures использовали PostgreSQL `markethub_api_test`. До запуска прочитаны `Tests/TestCase.php` и E2E guards: необходимы pgsql и имя БД с `_test`; браузерные fixtures дополнительно требуют `APP_ENV=testing`. Backend и E2E не запускались одновременно на общей тестовой схеме.

Внешние Shopify/WB/Ozon запросы ради аудита не выполнялись. Поэтому существующий реальный Shopify-adapter подтверждён кодом и тестами с HTTP fakes, но актуальная валидность рабочего Shopify-доступа сегодня **не перепроверялась**. Исторические live smoke предыдущих этапов не выдаются за результаты этого аудита.

Сборка обновила игнорируемый `frontend/dist`; диагностические логи/браузерные артефакты находятся в игнорируемых каталогах. Итоговый tracked-кандидат данного этапа — этот отчёт. Оценки ниже относятся к демонстрационному приложению, не к коммерческой SaaS-системе.

## 3. Инвентаризация всех функций

Обозначения: **готов** — проверенная функция пригодна для контролируемой демонстрации; **условно** — есть оговорка/подготовка; **нет** — функция отсутствует и не должна рекламироваться. Тесты backend в этой таблице выполнены в текущем аудите; браузерные результаты — разделы 4 и 15.

| Модуль | Реализовано и реально работает | Частично / отсутствует / не заявлять | Покрытие | Демонстрация |
|---|---|---|---|---|
| Вход/выход и сессия | Sanctum cookie, CSRF, regenerate/invalidate, me, 401/419, login throttling | Нет публичной регистрации, reset password, email verification | ApiTest, app E2E | Готов после выдачи demo-доступа |
| Организации | Создание с идемпотентным request_id, членство, переключение, tenant scope, составные FK | Нет управления приглашениями/участниками в UI | ApiTest, OnboardingTest, DashboardTest, onboarding E2E | Готов |
| Роли | Проверяется членство; is_admin существует для CLI | **RBAC/viewer-роли нет**: участник может менять данные организации | Policies и positive/negative API tests | Условно; публичный общий аккаунт опасен |
| Dashboard | PostgreSQL aggregates, 4 KPI, demo toggle, provider/account filters, attention links, runs/activity, physical/procurement | Не аналитика продаж/выручки; local SKU и physical/procurement — вся организация | 10 DashboardTest, 2 dashboard E2E | Готов; код ещё незакоммичен |
| Подключения | Общая форма по provider schema, validation, draft probe, settings, disable/disconnect, capability-aware tabs | Нет универсального OAuth подключения любых независимых Shopify-продавцов | MarketplaceFoundationTest, UnifiedCatalogTest, shopify E2E | Готов |
| Shopify | Одностороннее чтение товаров, изображений, вариантов, locations/inventory; initial confirmation, manual/hourly/daily | Нет записи в магазин, webhooks, orders; live credentials в этом аудите не проверены | ShopifyTest, MarketplaceSyncTest, concurrency; UI с mock API | Условно: отдельный безопасный магазин или fixture |
| Wildberries demo | 50 synthetic-размещений из сидера, список/карточка/остатки | Реального API/check/sync нет; capabilities пустые, кнопки не имитируют успех | Demo seed, Foundation, inventory/catalog tests | Готов как **demo**, не интеграция |
| Ozon demo | Те же локальные SKU с 50 отдельными synthetic-размещениями | Реального API, цен/заказов/Ozon sync нет | Те же тесты | Готов как **demo** |
| Общий каталог | UNION local + grouped unmapped external, поиск по source/local, фильтры, pagination, bounded previews | `/products` — отдельный API local SKU; его нельзя называть полным external export | UnifiedCatalogTest, app/shopify E2E | Готов |
| Локальный товар | Создать/изменить/открыть/удалить при отсутствии зависимостей; SKU уникален в org | Нет произвольного редактирования импортированных snapshots | ApiTest, warehouse FK tests, app E2E | Готов |
| Внешняя карточка | Parent/variant/local typed ID, описания, ссылки между сущностями, source price/status/date/Admin URL | Не редактирует товар Shopify; пагинация источников по 20, gallery до 12 | UnifiedCatalogTest, shopify E2E | Готов |
| Фото | Превью, выбор, увеличение, Назад/Далее, placeholder при пустом массиве | Нет загрузки локальных фото; **нет fallback при HTTP-ошибке картинки**, Escape/focus дефект | Catalog tests, shopify E2E + отдельный browser probe | Условно: P2 |
| Варианты | Импортированные listings, parent identity, individual mapping, цены/остатки, point refresh | Нет редактора локальных option/variant-матриц | Shopify/UnifiedCatalogTest, UI mock | Готов в текущем объёме |
| Mapping | Map/unmap, явное создание local SKU, same-org checks, SKU collision, блокировки против import race | Никакого auto-match по одинаковому SKU; это намеренно | Foundation/Concurrency/UnifiedCatalogTest, shopify E2E | Готов |
| Ручной sync | UUID run, initial confirmation, account/variant scope, дедупликация | Нет cancellation; refresh всей parent-группы не обещается | Sync/Shopify tests, UI mock | Готов при worker |
| История и recovery | Статусы, попытки, safe errors, checkpoint/ledger, lease/advisory lock, retries, polling/reload | Очень большие каталоги ограничены бюджетом попытки; не bulk pipeline | SyncTest, SyncConcurrencyTest, history UI tests | Готов |
| Scheduler | Каждую минуту dispatch-due + recovery; соблюдает manual/hourly/daily | Нужен реально запущенный cron/scheduler; запись Schedule не запускает процесс | Sync tests + schedule:list/runtime inspection | Условно: инфраструктура |
| Остатки маркетплейсов | Общие locations/levels, фильтры, known/zero/negative/null/tracked/stale, XLSX | Это snapshots, не физический учёт и не отправка запасов в магазин | MarketplaceInventoryTest, concurrency, ExportTest, shopify E2E | Готов |
| Mapping внешней локации | Выбор local warehouse/unmap, tenant checks | Информационная связь, не движение товара | Inventory API tests, shopify E2E | Готов |
| Локальные склады | Создание/изменение, типы, active state, список физических остатков | Нет удаления используемого склада и логистики доставки | WarehouseInventoryTest, inventory/onboarding E2E | Готов |
| Складские операции | Приход/списание/перемещение/инвентаризация; транзакции, idempotency, no negative, lock order | Это ручной учёт; не авто-списание по заказам площадок | WarehouseInventory/ConcurrencyTest, inventory E2E | Готов |
| Журнал движений | Неизменяемые quantity before/after, автор, время, комментарий, фильтры/документ | Не полная система аудита каждого изменения всех моделей | Warehouse tests, inventory/onboarding E2E | Готов |
| Поставщики | Создать/редактировать/поиск/активность/удаление неиспользуемого | Нет supplier portal, договоров/оплат | ProcurementTest, procurement E2E | Готов |
| Закупки | Черновик, строки/точные суммы, заказ/отмена, warehouse/supplier context | Это purchase orders поставщикам, не customer orders | Procurement/ConcurrencyTest, procurement/onboarding E2E | Готов |
| Приёмка | Partial/full, confirm/cancel, неизменяемость проведённого документа, атомарное движение | Нет invoice/accounting модуля | Procurement/ConcurrencyTest, E2E | Готов |
| Onboarding | Реальный чек-лист организации/склада/товара/поставщика/закупки/приёмки | Чек-лист не заменяет работу пользователя; некоторые steps показывают org-wide прогресс | OnboardingTest, 5 onboarding E2E | Готов |
| Product Tours | Help, offer/skip/snooze/resume/restart/versioning, маршруты/anchors, mobile | Не доказана полная WCAG/keyboard accessibility всех модальных окон | OnboardingTest, geometry unit, onboarding/dashboard E2E | Готов с UX-оговорками |
| Настройки | Connection settings, sync toggles/frequency, персональные предпочтения обучения | Отдельного общего settings/profile/password/roles UI нет | Foundation/Sync/Onboarding tests, UI | Не рекламировать глобальный settings-модуль |
| Excel export | Local products API + внешние stocks API/UI; literal strings против formula injection; null/0/negative | В новом общем каталоге кнопка products export потеряна; unmapped external не входят в local export | ExportTest, inventory export test | Частично в UI |
| Импорт файлов | Local-only CLI import legacy SQLite; защита от непустой цели | Нет CSV/XLSX upload/API массового импорта | Код ImportLegacyDatabase; отдельный текущий запуск не выполнялся | Техническая миграция, не пользовательская функция |
| Health/system checks | `/up`, технический `/api/system-checks`, CLI sync-runtime | Не мониторинг Shopify и не гарантия работы worker | Example/SystemChecksTest, runtime inspection | Готов как technical health |
| Заказы покупателей/финансы | Нет | Нет orders API/UI, payment/refund/fulfillment/revenue | Не применимо | Не заявлять |

## 4. Результаты сквозных проверок

### Авторизация и начало работы

API-набор подтвердил login/me/logout, неверный пароль 422, ограничение частоты, CSRF 419 и отказ чужим организациям. Onboarding E2E прошёл путь от пользователя без организации: создание org → warehouse/product → supplier → purchase → receipt → движение. Dashboard E2E подтвердил смену организации и сброс недоступного account-фильтра. Help/restart/resume, Назад/Далее и версия экскурсии проверены отдельными сценариями.

### Товары и подключения

Backend проверил local CRUD, SKU uniqueness, search/sort/page limits, зависимые удаления и IDOR. Shopify UI-сценарии проверили общую форму, draft probe, settings manual/hourly/daily, initial confirmation, gallery, typed routes/legacy redirects, variant refresh/reload, map/unmap/create local SKU и обе страницы ResourcePicker. Здесь remote API замокан: этот результат не доказывает доступность магазина и сетевой очереди.

### Склад и закупки

Реальные Laravel/PostgreSQL браузерные сценарии проверили складской цикл, повтор после потери ответа, журнал, supplier/purchase/partial/full receipt, повтор confirm и переходы между документами. Backend многопроцессные проверки доказали отсутствие double posting, overreceipt и lost update, сохранение общего количества при встречных перемещениях. Отдельные тесты подтвердили, что импорт и informational warehouse mapping не изменяют physical stocks/movements.

### UI и mobile

Проверены mobile 390 px, внутренняя прокрутка таблиц, меню, validation errors, загрузка/retry, пустая организация, URL-фильтры, сохранение после reload и tours. Dashboard/mobile screenshots просмотрены; содержание и единицы счёта понятны, но тестовый снимок сразу после resize ловит переход sidebar, поэтому его нельзя использовать как готовый скриншот портфолио.

Для app/inventory/procurement в существующих тестах собираются `pageerror`; полного универсального перехвата console.error/requestfailed для каждого маршрута нет. Нельзя утверждать «вся консоль во всех состояниях чиста». Преднамеренные 401/419/422 и mocked provider failures не считаются самопроизвольными Laravel-ошибками.

Отдельный browser probe реального Vue `ProductGallery` с URL 404 дал `{brokenImages:1,fallbackVisible:false}` и после открытия/нажатия Escape `{galleryOpenAfterEscape:1}`. Это подтверждённые P2, а не предположения из кода.

## 5. Критические ошибки (P0)

**Подтверждённых P0 вида обхода tenant isolation, раскрытия credentials через проверенные API или порчи физических остатков не найдено.** Это заключение в пределах тестов/ревью, не сертификат безопасности и не полный pentest.

Публиковать существующий рабочий tenant/его общий аккаунт с реальным Shopify как публичное demo нельзя. Такая схема создаст P0-условие эксплуатации: посетители получат разрешённые бизнес-политиками операции над рабочими данными/подключением. Это предупреждение о конкретной отсутствующей границе demo, а не утверждение, что действующее локальное приложение уже публично скомпрометировано.

## 6. Важные ошибки и препятствия релизу (P1)

| ID / приоритет | Доказательство и воспроизведение | Почему важно / предлагаемое исправление | Сложность | Можно закончить без этого? |
|---|---|---|---|---|
| P1-01 — публичный demo не ограничен | Обычный member получает create/update/delete в `OrganizationBusinessPolicy`; org create разрешён. ApiTest выполняет CRUD обычным пользователем. Нет viewer/demo middleware или outbound demo boundary | Не выдавать гостям рабочий tenant. Отдельная synthetic БД, demo-пользователь и server-side запрет изменения integrations/credentials, real probe/sync и org creation. Самый короткий вариант — read-only demo; интерактивное demo требует disposable/reset данных | Средняя | Без публичного аккаунта — да; с публичным — нет |
| P1-02 — bootstrap production отсутствует | `DemoDataSeeder` отказывает вне local/testing, `markethub:admin` разрешён только local. Свежая production БД + штатные инструкции не дают demo-пользователя/данных | Нужен явный безопасный release bootstrap с синтетическими данными и вводом пароля вне Git, без переключения публичного приложения в local. Не ослаблять guard существующего сидера глобально | Небольшая/средняя | Для свежего публичного размещения — нет |
| P1-03 — production hosting не подготовлен | `infra/nginx-spa.conf` содержит Windows absolute paths, loopback FastCGI:9000; `.env` local/debug=true. Runtime Redis корректен, но tracked worker/scheduler сейчас остановлены | Linux Nginx/PHP-FPM, production env/HTTPS/cookies, persistent worker и cron, permissions, backups APP_KEY+DB, smoke после развёртывания. Не копировать local config как production | Средняя | Для GitHub-only — да; для хостинга — нет |
| P1-04 — нечестная/устаревшая презентация | `/` заявляет «Без реальных интеграций» и только WB/Ozon, хотя Shopify уже реализован; headline обещает продажи при отсутствии orders | Коротко описать каталог/остатки/закупки, Shopify read-only и WB/Ozon demo; убрать обещание аналитики/будущего бесконечного roadmap. Добавить авторство JAROVIT | Небольшая | Для убедительной публичной демонстрации — нет |
| P1-05 — проверенная версия не воспроизводится из HEAD | Dashboard controller/query/API/tests/config ещё untracked, а routes/page уже modified; `git status` не чистый | После согласованного завершения включить связанные файлы в один осмысленный commit, проверить fresh checkout. Исключить посторонний tg-bots. В этом аудите commit/push запрещены и не выполнялись | Небольшая | До публикации итогового состояния — нет |
| P1-06 — неучтённый physical stock выдан за ноль в каталоге | Создать local SKU без stock operation; `/catalog` через `UnifiedCatalogPresenter::list` возвращает physical_quantity=0 из `?? 0`, Vue пишет `MarketHub: 0 шт.`. Dashboard правильно считает no_data_skus | В каталоге различать «нет учётных записей» и подтверждённый 0: nullable quantity + has_physical_records/count. Согласовать с dashboard, не изменять реальные остатки | Небольшая | Для корректной демонстрации склада желательно исправить до финиша |

Общий API throttle отсутствует: ограничены login и draft connection probe; сохранённый account/check и остальные authenticated actions не имеют общего лимита в routes. Для закрытого личного портфолио это ограничение; перед общим demo P1-01 дополнить ограничением частоты/размера дорогих запросов. Одной скрытой кнопки недостаточно.

## 7. Мелкие проблемы и UX (P2)

| ID | Воспроизведение / факт | Конкретное исправление и польза | Сложность | Можно закончить без этого? |
|---|---|---|---|---|
| P2-01 — broken image | ProductGallery с непустым URL 404: broken img, placeholder отсутствует | Обработчик error, fallback для main/thumb/zoom, недоступные фото пропускать без бесконечных повторов | Небольшая | Да, но заметно ухудшает портфолио |
| P2-02 — gallery Escape/focus | Открыть zoom мышью, сразу Escape: окно остаётся; фокус не переводится гарантированно внутрь | После mount фокусировать кнопку/контейнер, Escape обрабатывать на активном modal, вернуть фокус trigger; проверить keyboard trap | Небольшая | Да; включить в короткую UX-доводку |
| P2-03 — products export исчез из UI | `/app/products` и MarketplaceProductList не импортируют downloadExcel; `/api/v1/products/export` работает. docs/EXCEL утверждает наличие кнопки | Вернуть кнопку «Экспорт локальных SKU» с честным scope либо убрать UI-обещание. Не называть её экспортом всех external parents/variants | Небольшая | Да, API остаётся; документацию исправить обязательно |
| P2-04 — документация/контракт отстают | frontend README описывает старые API, legacy UI и E2E local fixtures; в API.md/OpenAPI нет dashboard | Обновить current capabilities/routes/testing и добавить dashboard schema; исторические TASK-отчёты не переписывать как текущую инструкцию | Небольшая | Частично; основной README нужен до финиша |
| P2-05 — переносимость E2E | Исходный cwd `D:\projects\...` вызвал unexpected test.beforeAll/No tests found; canonical `D:\Projects\...` перечисляет и исполняет тесты. Fixtures содержат `C:/tools/php83/php.exe`, browser channel chrome | Единый canonical cwd; PHP path из env/PATH, документировать browser install/channel. На Linux провести один smoke; не переносить Windows path в VPS | Небольшая | До Linux CI — да; честно описать ограничение |
| P2-06 — нестабильные screenshot/teardown | Первый app E2E упал на `Page.captureScreenshot`, до CRUD; Windows runner задерживает завершение webServer teardown | Отделить screenshot capture от функционального assert, дать settled viewport/animations, проверить корректный stop дочерних серверов | Небольшая | Да, но не выдавать сбой за зелёный прогон |
| P2-07 — большие списки/границы | Locations endpoint без pagination, filter accounts до 100, connection preview до 50, gallery до 12, inventory detail до 200 | Документировать ограничения; текущие bounded previews разумны для портфолио. Не строить новый bulk pipeline без реального кейса | Небольшая для docs | Да |

N+1 в проверенных основных списках не воспроизведён: Api/Inventory/UnifiedCatalog/Dashboard tests ограничивают число запросов и проверяют рост с добавлением записей. Это не нагрузочный benchmark. Локальное API `/products` загружает imported listings для совместимости; для огромного количества вариантов его payload стоит отдельно измерить, но это не блокер данного demo.

## 8. Оценка глазами WB/Ozon/Shopify продавцов

| Пользователь | Повседневная польза уже сейчас | Что показать на demo | Чего нельзя обещать |
|---|---|---|---|
| Продавец WB | Локальный SKU, physical warehouse, supplier purchase/receipt, ручные операции; demo размещений | Путь закупки → приёмки → движение, фильтры/экспорт synthetic остатков | Чтение настоящего WB кабинета, API stock push, цены/заказы/продажи WB |
| Продавец Ozon | Те же реальные локальные модули | Разные demo размещения одной SKU, данные и границы tenant | Реальную синхронизацию Ozon, документы/отгрузки/аналитику Ozon |
| Владелец Shopify | Read-only каталог/варианты/изображения/locations, snapshots, mapping к учётной SKU, история/повтор sync; отдельно склад/закупки | Один внешний parent → варианты → local SKU → отдельные physical/external остатки; failed/partial run с понятной ошибкой | Управление заказами, fulfillment/refund, запись цен/stock в магазин, универсальный OAuth installation |

Наибольшая демонстрационная ценность — законченный путь данных и транзакционная надёжность, а не число интеграций. Нужны подготовленные synthetic сценарии: unmapped variant, zero/unknown/stale inventory, частичная приёмка, безопасная ошибка sync и успешный retry. Не нужны реальные WB/Ozon API, billing, тарифы, финансы, OMS и расширение архитектуры ради списка технологий.

## 9. Отсутствующие функции и их реальная ценность

Отсутствуют customer orders/выручка, профили и recovery пароля, UI приглашений/ролей, local image upload, XLSX/CSV import, отправка stock в площадки, webhooks и реальные WB/Ozon. Их отсутствие не мешает портфолио при точном описании.

Публичный demo guard/bootstrap и актуальный README нужны для выпуска; их ценность выше нового orders-раздела. Полная RBAC не требуется: достаточно небольшой серверной границы demo. Отдельные screenshots и короткий сценарий просмотра полезнее нового welcome wizard — Help, checklist и landing уже существуют.

## 10. Решение по модулю заказов

**Рекомендация — A: завершить без customer orders.** Склад/закупки, общий каталог, tenant isolation, источник Shopify, queue/recovery и согласованный UI уже демонстрируют полноценную разработку.

| Вариант | Объём/сложность (оценка) | Риски | Польза и решение |
|---|---|---|---|
| A. Не добавлять | Низкая: исправить позиционирование/описание ограничений | Не подходит как обещание полноценного кабинета управления продажами | Наиболее короткий путь завершения; выбран |
| B. Минимальный честный demo orders | Средняя, ориентировочно 1–3 рабочих дня включая данные/тесты | Ещё один незаконченный модуль, synthetic числа могут выглядеть реальными | Допустимо позже только для конкретного показа; не входит в минимум |
| C. Read-only Shopify Orders | Средняя/повышенная, ориентировочно 3–5 дней после проверки scopes; оценка не обязательство | Новая схема/пагинация/связи, персональные данные, scopes/protected data, fake/live testing | Не обосновано как «простое чтение»: текущий importer orders не содержит, наличие прав не проверено. Не начинать |

По официальному [Shopify Order API](https://shopify.dev/docs/api/admin-graphql/latest/objects/Order) для обычного чтения заказов нужен `read_orders` (или соответствующий специализированный scope); по умолчанию доступны последние 60 дней. Наличие client credentials само по себе не доказывает наличие прав на orders. В аудите новые разрешения не запрашивались, данные покупателей не читались.

## 11. Решение по dashboard

Проверялся **уже находящийся в рабочем дереве dashboard**, а не автоматически реализовывался прежний TASK12. Его достаточно; полноценная переделка не нужна.

- KPI различают local SKU, external parent и variant. Размещения двух площадок не удваивают local SKU; несколько locations не удваивают variant.
- По умолчанию external demo исключены; toggle включает их явно. Local SKU/physical/procurement считаются по всей организации, это подписано.
- Нет выдуманной выручки/продаж. Показаны zero/low/unknown/negative/untracked/stale, last run/API status и очередь.
- Новый failed/partial не скрывается старым successful. Manual не выдаётся за просрочку Scheduler.
- Attention/deep links работают; tests проверяют unmapped и stale destination. Внешние snapshots не суммируются с physical.
- Важное ограничение: нет финансов/заказов и real API check при чтении; это корректно для текущей ответственности.
- Улучшить только P1-06 согласованность «нет данных» с каталогом, презентационные screenshot и описание контракта. Не добавлять charts ради декоративности.

## 12. Рекомендации по публичной демоверсии

Предпочтительный минимум: отдельная БД/окружение с synthetic seed, read-only demo user, понятный вход на landing и готовый сценарий Help. В такой версии не должно быть рабочего Shopify connection/credentials или raw store dump, даже если значения credentials скрыты API.

Сервер обязан ограничить demo-аккаунту операции записи, создания organizations и external connections/probe/check/sync; нельзя полагаться на disabled кнопки. Добавить rate limit дорогих чтений/экспорта и внешних действий. Если интерактивная приёмка критична для показа — отдельная disposable synthetic среда с периодическим reset и запретом внешних вызовов. Reset никогда не направлять на рабочую БД.

Текущий DemoDataSeeder создаёт 50 local SKU и 100 WB/Ozon listings, но не готовый публичный user, не сценарии закупок/warehouse и не synthetic Shopify gallery/history. Для демонстрации дополнить **синтетическими fixtures**, не новым сетевым провайдером и не копией реального магазина. Показать происхождение каждой группы.

Не создавать SaaS регистрацию/подписки. Публичную авторегистрацию сейчас route:list/ApiTest не обнаружили. Отдельный welcome-экран не обязателен: достаточно обновлённого landing, кнопки demo-входа и краткого сценария с ограничениями.

## 13. Hosting readiness

### Что уже есть / что подготовить

| Проверка | Результат аудита | Перед Linux-размещением |
|---|---|---|
| PHP/framework | PHP 8.3.36, Laravel 13.40.0; Composer platform requirements passed | PHP >=8.3, PHP-FPM + CLI, необходимые extensions; GD/zip/XML для Excel, pdo_pgsql, curl/mbstring и стандартные Laravel extensions |
| Production flags | Локально APP_ENV=local, APP_DEBUG=true; это не production env | Отдельный env: production, debug=false, корректный URL; SESSION_SECURE_COOKIE=true по HTTPS; не публиковать local env |
| Frontend | Typecheck/lint/unit/build проходят; SPA в frontend/dist | Собирать npm ci/build из lock, публиковать только dist; Node нужен для сборки, не обязательно на runtime VPS |
| Nginx / public root | Есть Windows-конфиги, root SPA + API internal Laravel public/index.php | Linux server block с реальными путями и PHP-FPM socket; наружу не корень Laravel, .env/vendor/storage/logs/.git закрыты; /assets 404 без SPA fallback |
| HTTPS/proxy | Локально HTTP; публичный TLS не проверялся | Сертификат, redirect HTTPS, secure cookies; согласовать trusted proxy при его наличии |
| CORS/Sanctum | CORS только FRONTEND_URL, credentials=true; cookies/CSRF проверены | Проще один origin SPA/API; точные trusted/stateful domains, cookie domain/same-site, проверить login/logout/reload на HTTPS |
| PostgreSQL | Все backend-тесты на pgsql; CHECK/composite FK/advisory locks PostgreSQL-specific | PostgreSQL 17 предпочтительно как проверенная версия; отдельные production/test роли и БД, backup/restore rehearsal |
| Redis/queue | sync-runtime: redis, marketplaces, timeout=1800, retry_after=1900, execution_budget=1500, pending=0 | Redis persistent process, worker под systemd/Supervisor; читать marketplaces,shopify; retry_after больше timeout; queue:restart при смене кода |
| Scheduler | schedule:list: dispatch-due каждую минуту; read-only status: tracked процессы остановлены | Cron `* * * * * php /path/artisan schedule:run`; не запускать второй параллельный scheduler без причины |
| Migrations | Тестовая схема разворачивается полным набором; рабочие миграции не менялись | Перед upgrade backup, `migrate --force`, проверить backfill/совместимость уже имеющейся БД; НЕ migrate:fresh на production |
| Demo/bootstrap | Существующие CLI/seeders guard local/testing | Явная production-safe процедура synthetic seed + account + demo restrictions; публичный APP_ENV=local недопустим |
| Permissions | Windows-файлы доступны локальным процессам; Linux ownership не проверялся | Запись FPM/worker в storage и bootstrap/cache, без chmod 777 на проект; общий доступ к private credentials закрыт |
| Upload/storage:link | Upload endpoints не реализованы, фото — внешние URL | Не считать storage:link исправлением импорта фото; создавать link только при публичных uploads; не делать storage/logs публичным |
| Logs/health | /up, technical checks и safe provider errors; local logs доступны | Log rotation, disk limits, failed jobs/worker monitoring, проверка queue и Scheduler; `/up` не заменяет их |
| APP_KEY/backup | В tracked env примере ключ пуст; credentials encrypted:array | Сохранить APP_KEY отдельно и восстанавливать вместе с БД; не делать key:generate поверх базы с зашифрованными credentials |
| Linux tooling | Windows .ps1, C: PHP paths в E2E | Linux runbook/service files + один smoke; Docker/WSL не нужны и не добавлялись |

Минимальная практическая оценка для небольшого демонстрационного VPS: **1–2 vCPU, 2 GB RAM, 15–20 GB SSD**, один worker, PostgreSQL, Redis, PHP-FPM и Nginx. Это инженерная стартовая оценка без нагрузочного теста, а не гарантированная производительность или рекомендация конкретного платного провайдера. Сборку frontend можно делать вне VPS.

Обычный shared hosting без PostgreSQL, Redis, cron каждую минуту и разрешённого долгоживущего worker **не подходит для текущей полной функциональности**. Специализированный managed hosting с этими возможностями подойдёт. Статическое/read-only demo без sync можно упростить отдельно, но нельзя обещать работающий background import на обычном PHP-хостинге.

Порядок production-подготовки соответствует официальным [Laravel deployment](https://laravel.com/docs/13.x/deployment) и [queue worker deployment](https://laravel.com/docs/13.x/queues#supervisor-configuration). На данном этапе config:cache/route:cache в рабочей среде не запускались, сервер не перенастраивался, деплой не проводился.

## 14. GitHub / README readiness и секреты

README уже содержит архитектуру API, PostgreSQL isolation, локальную установку, cookie auth, modules и ссылки на TASK8–TASK11. Есть API/OpenAPI и подробные тематические документы. Но текущий README ориентирован на историю Windows-разработки и не завершён как витрина JAROVIT.

До финиша нужны:

1. Короткое описание продукта и возможностей с границей Shopify real / WB/Ozon demo / отсутствием customer orders; имя JAROVIT.
2. 4–6 аккуратных screenshot: обзор, каталог+галерея, подключение/history, external stocks, purchase/receipt, mobile. Не публиковать рабочие домены магазина, пользовательские данные или credentials. Существующие test-results не являются готовыми curated screenshots.
3. Один актуальный quickstart: requirements → env → migrate → безопасные synthetic data/user → build → web/worker/scheduler → test. Отдельно Windows и Linux.
4. Production runbook без локальных абсолютных путей, ограничения demo/real capabilities, safe bootstrap, известные ограничения. Публичную ссылку добавить только после реального размещения.
5. Исправить frontend README/E2E описание, EXCEL UI-обещание, добавить dashboard в API/OpenAPI; screenshots в tracked docs/assets. Наличие legacy wrappers честно указать, не устраивать cleanup-рефакторинг перед финишем.
6. После согласования — выборочно включить существующие незакоммиченные файлы, пройти fresh-checkout regression и проверить целевую default branch. CI workflows в MarketHub сейчас не обнаружены; простой backend+frontend CI желателен, но не новый инфраструктурный проект.

### Проверка секретов

- `git ls-files` в MarketHub не показывает real `.env`, `.env.testing`, DB dump/sqlite/private key/credentials file; tracked `.env.example` содержит пустые secret values. `.gitignore` исключает .env, .env.testing, vendor, runtime, logs; frontend исключает node_modules/dist/test-results.
- Проверены доступные 9 ревизий на характерные APP_KEY/base64, Shopify access token, GitHub token, AWS access key и private-key patterns — совпадений не найдено. Проверка выводила только имена файлов, не значения.
- Это **ограниченный сигнатурный поиск**, а не доказательство отсутствия любого пароля/PII во всех возможных форматах или в неизвестных remote refs. Подготовленные synthetic seeders не содержат реальных email/password покупателей. Полное содержание внешних backup/credentials-файлов не читалось.
- `tg-bots/` — отдельный вложенный репозиторий, не tracked частью MarketHub; его credentials и история не входят в заключение о безопасности MarketHub. Не использовать общий `git add .` для выпуска.
- Credentials Shopify скрыты и зашифрованы; отсутствие сырых payload/secrets в основных JSON проверяется backend tests. Worker errors санитизируются. Политика secret rotation и серверные ACL вне текущего тестового окружения не проверялись.

## 15. Результаты тестов

| Проверка | Актуальный результат |
|---|---|
| `php artisan test --no-ansi` | **169 passed, 1328 assertions, 112.99 s**, exit 0; log: `storage/logs/task14-backend-tests.log` |
| `composer validate --strict` | Passed |
| `composer check-platform-reqs` | Passed, PHP 8.3.36 |
| `composer audit --format=summary` | No security vulnerability advisories found; после разрешённого сетевого доступа |
| `npm.cmd run typecheck` | Passed |
| `npm.cmd run lint` | Passed |
| `npm.cmd test` | **3 files, 10 tests passed** |
| `npm.cmd run build` | Passed, Vite 7.3.8, 1745 modules; dist обновлён |
| `npm.cmd audit --omit=dev` и полный `npm.cmd audit` | **0 vulnerabilities** на дату аудита; пакеты не обновлялись |
| `route:list --path=api --except-vendor` | 85 маршрутов; проверены существующие controllers и auth boundaries |
| `schedule:list` / `marketplaces:sync-runtime` | Schedule зарегистрирован; Redis/timeout/retry_after согласованы, pending=0; tracked worker/scheduler остановлены |
| Playwright regression | **15 сценариев прошли в общем прогоне; app-сценарий прошёл отдельно при повторе (43.6 s)**. Единого exit-0 прогона нет из-за Windows teardown; детали ниже |
| Отдельный Vue/Chrome probe | Подтверждены broken image fallback и Escape/focus дефекты галереи |

Первый запуск Playwright из lowercase `D:\projects` не собрал тесты: unexpected test.beforeAll/No tests found. Из canonical `D:\Projects` CLI корректно перечислил 16 сценариев и запустил их последовательно (`workers:1`, test DB). В canonical-прогоне **15 сценариев прошли**, app-сценарий остановился на `Page.captureScreenshot: Unable to capture screenshot` у landing, до CRUD. Остальные проверки включают два реальных dashboard, склад, пять onboarding, закупки и шесть Shopify/mock UI сценариев.

Windows runner задержался при webServer teardown после выполнения всех 16 тестов; диагностический процесс остановлен. Полный canonical run поэтому не объявляется зелёным exit-0 прогоном. App-сценарий запущен отдельно повторно с retries=1 и **прошёл с первой попытки за 43.6 s**: Sanctum/CSRF, session reload, product create/edit/detail/delete, validation, connection create/edit/filter, inventory, dashboard, mobile menu и logout. Его runner также задержался на teardown. Итого все 16 сценариев имеют успешное выполнение, но нужна отдельная стабилизация Windows завершения тестовых серверов.

Логи: `frontend/test-results-task14.log` (первый discovery failure), `frontend/test-results-task14-canonical.log` (regression), `frontend/test-results-task14-app-retry.log` (app retry). Скриншоты/контексты находятся в игнорируемом `frontend/test-results`; повторные запуски могут перезаписать их. Backend log сохранён отдельно. Playwright fakes и test DB не заменяют end-to-end live Shopify/Redis smoke на подготовленном VPS.

Не запускались: destructive проверки рабочей БД, свежий live Shopify import/check, публичный HTTPS, Linux runtime, полноценный нагрузочный тест, исчерпывающий accessibility/pentest. Прежние отчёты о live smoke не являются сегодняшним повторным прогоном.

## 16. Минимальный список задач до завершения

| Завершающий этап | Почему нужен и конкретный результат | Приоритет / сложность | Можно пропустить? |
|---|---|---|---|
| **1. Честное безопасное demo + короткая доводка** | Отдельное synthetic окружение, demo boundary/read-only, production-safe fixtures/user bootstrap; исправить no-data→0, broken image/галерею, landing и scope экспорта. Один сценарий демонстрации имеющихся функций | P1, средняя; UX P2 небольшая | Для публичного demo — нет; новое orders/API WB/Ozon не требуется |
| **2. Подготовка и проверка размещения** | Linux Nginx/PHP-FPM/env/HTTPS, PostgreSQL/Redis, supervised worker, cron, permissions/backup+APP_KEY, deploy/rollback runbook и изолированный smoke. Сам деплой — отдельное разрешённое действие после подготовки | P1, средняя | Для GitHub-only можно; для размещения — нет |
| **3. Упаковка портфолио и финальная регрессия** | README JAROVIT, curated screenshots, точный API/limitations, переносимые тестовые команды, выборочный commit всех связанных файлов и fresh-checkout checks; после согласования публикация версии/ссылки | P1/P2, небольшая/средняя | Для профессионального GitHub — нет |

**Вердикт: три небольших завершающих этапа, без нового цикла бизнес-разработки.** Заказы — вариант A; dashboard сохранить; реальные WB/Ozon, OMS/финансы/billing, bulk pipeline и архитектурную переделку не добавлять. При ограничении результата только GitHub без публичного demo инфраструктурные действия можно отложить, но ограничения следует явно описать.
