# MarketHub — FINAL 1: безопасное портфолио-demo

> Исторический отчёт TASK15. Актуальные интерфейс, результаты проверок и публикация описаны в [финальном релизе](markethub-final-release.md).

Дата: 13 ноября 2026. Задание: TASK15. Авторство продукта: **JAROVIT**.

Реализован первый завершающий этап: серверный read-only demo, отдельный bootstrap синтетической БД, безопасный вход с landing и исправления каталога/галереи/экспорта. FINAL 2/3 не начинались. Production deployment, push, tag и commit не выполнялись.

## 1. Сохранённый функционал и данные

Существующие универсальные подключения, read-only Shopify, WB/Ozon demo, каталог/mapping, история/очереди, внешние и физические остатки, закупки/приёмка, dashboard и обучение сохранены. Незакоммиченные изменения dashboard включены в проверку; они не откатывались. Рабочая БД, Shopify account №15, его manual schedule и credentials не изменялись; live API-запросов не выполнялось.

Тесты используют только PostgreSQL `markethub_api_test`, с проверкой APP_ENV=testing/pgsql/суффикса `_test` перед очисткой. Backend и E2E выполняются последовательно, поскольку разделяют тестовую схему. Ни одна команда миграции/bootstrap не запускалась на рабочей БД. Текущий DemoDataSeeder сохранил guard local/testing.

## 2. Серверная граница

- Новая обратимая schema migration добавляет `users.is_demo_access`, boolean, default=false. Признак не входит в fillable и не назначается публичным API. Bootstrap выставляет его доверенно в конце транзакции. `is_admin` остаётся false.
- `DemoReadOnly` после Sanctum и до SubstituteBindings запрещает любые бизнес POST/PATCH/PUT/DELETE с 403, включая probe/check/sync/refresh/disconnect, mapping/unmapping, создание local SKU, организаций, закупок/приёмок, suppliers, warehouses и stock operations. Запрет применяется и к ещё несуществующим ID до обработчика. Новые unsafe маршруты внутри этой группы автоматически защищены.
- Gate::before дополнительно запрещает demo-пользователю способности, кроме view/viewAny. Обычному пользователю он возвращает null, оставляя прежние policies и CRUD.
- Разрешены tenant-scoped GET/HEAD и session login/logout/CSRF. Эти исключения не запускают бизнес-операций. Выбор активной организации через PATCH также закрыт, у публичного demo одна организация.
- Частотные ограничения: API 300/мин user+IP; Excel 10/мин user+IP; demo status/login 10/мин IP. Обычный login сохраняет 5/мин email+IP и 30/мин IP. Для реального размещения необходим общий persistent cache, а не тестовый array driver.
- Никакие credentials не создаются fixtures. `shopify_demo` — display-only demo provider, без checker/importer/normalizer/compatibility writer, без sync и refresh capabilities. Есть только products/inventory/locations/history для просмотра. Второго importer нет: fixtures используют существующий MarketplaceImportService.

## 3. Как подготовить отдельное demo

Эта процедура подготовлена для оператора; в текущем рабочем окружении она **не выполнялась**. Используется новая отдельная PostgreSQL БД, например `markethub_public_demo`, отдельный deployment/env и отдельный APP_KEY. Не копировать рабочую БД/Shopify credentials.

1. Подключить новую пустую БД с именем, оканчивающимся на `_demo`. Выполнить обычные schema migrations на ней; command bootstrap сам не мигрирует и ничего не удаляет.
2. В отдельном окружении задать `PUBLIC_DEMO_ENABLED=true`, `PUBLIC_DEMO_DATABASE=markethub_public_demo`, обычный `DB_DATABASE=markethub_public_demo`, production APP_ENV и APP_DEBUG=false. Имена должны совпадать буквально. Не менять env рабочего магазина.
3. После проверки подключения выполнить:

   ```sh
   php artisan markethub:demo-bootstrap --confirm-environment=production --confirm-database=markethub_public_demo --email=demo@example.invalid
   ```

4. Ввести пароль через скрытый prompt (минимум 16 символов). Для автоматизации можно передать `DEMO_BOOTSTRAP_PASSWORD` только в окружение процесса и сразу убрать. Пароль не является аргументом CLI, не попадает в Git/JavaScript, не печатается; сохраняется только password hash. Не помещать его в shell history.
5. Проверить GET `/api/v1/auth/demo`: available=true. После сборки frontend landing предложит «Посмотреть демоверсию».

Command требует явного подтверждения точных environment/database, включённого demo, pgsql и соответствующего суффикса. Исключение `_test` возможно только при APP_ENV=testing. В production/test-БД оно не действует.

Bootstrap отказывается от **любых** существующих прикладных данных: users, organizations, marketplace accounts/credentials, stocks, procurement, jobs/sessions и т. п.; допустимы только migration metadata, cache/cache_locks и технические system_checks. Это сильнее поиска известных Shopify secrets. Он не дополняет существующую организацию и не удаляет данные. Повторный запуск безопасно завершается кодом 1, не меняя пароль/fixtures.

Одна транзакция и advisory transaction lock сериализуют параллельные bootstrap. Пустота проверяется повторно внутри lock. Ошибка fixtures откатывает весь набор; наружу выводится фиксированное сообщение без SQL bindings, пароля и внутреннего контекста. Предназначено для ещё не открытого deployment без параллельных writers.

## 4. Demo login и интерфейс

GET `/auth/demo` сообщает только available. POST `/auth/demo` требует обычный Sanctum CSRF, имеет throttle, выбирает только заранее подготовленного demo-пользователя и регенерирует сессию. Клиент не может указать user ID, email или credentials. Пароль для публичной кнопки не нужен и в браузер не передаётся.

PublicDemo проверяет explicit enabled/database/pgsql/suffix, ровно одного пользователя без admin и с demo flag, ровно одну организацию с bootstrap slug и членство. Отказывает при marketplace_credentials, любых не-demo аккаунтах, настоящем Shopify provider, shop_domain, legacy Shopify secrets и непустой account configuration. Неподготовленное окружение, отсутствие миграции или ошибка проверки → available=false/POST 503, без обходного входа.

В кабинете виден banner «Демонстрационный режим», объясняющий synthetic/read-only/API ограничения. Edit/create/delete/stock/sync/map controls скрыты через явные `data-demo-write` markers; серверная защита от них не зависит. Ручные URL new/edit и query create/connect перенаправляются в просмотр. Один organization selector не даёт менять контекст. Фильтры, карточки, pagination, gallery, Excel, read-only history и навигация доступны.

Dashboard в demo включает synthetic sources по умолчанию, с явным checkbox и метками; можно исключить их фильтром. У обычных пользователей сохраняется прежний default. Физический учёт/закупки по всей организации по-прежнему отделены от внешних snapshots.

Product Tours имеют отдельные безопасные demo steps без попыток открывать формы записи. Настройки и прогресс хранятся в sessionStorage текущей вкладки, с отдельными версиями; PATCH в общую user/tour БД не отправляются. При недоступном browser storage обучение работает в текущем состоянии приложения, долговременное сохранение не обещается.

Landing описывает реальный объём: JAROVIT, единый каталог, Shopify read-only в обычной версии, WB/Ozon demo, physical/external inventory, закупки, queues/history и адаптивность. Прямо указано отсутствие customer orders и финансов. При неподготовленном demo показано честное сообщение доступности.

## 5. Синтетические fixtures

| Данные | Созданный пример |
|---|---|
| Пользователь/организация | Один read-only посетитель, одна вымышленная JAROVIT-компания |
| Local SKU | 5: лампа, наушники, термобутылка, органайзер, рюкзак; SYNTHETIC-* |
| Физический склад | 2 активных own склада, итоговые quantities 8/10/0/null/null по SKU |
| Suppliers | Один вымышленный поставщик с адресом example.invalid |
| Procurement | 2 закупки, 2 подтверждённые приёмки; partial 8 из 20 и full 10 из 10 |
| Movements | Приёмки, приход и списание до нуля, перенос 3 единиц между складами через StockOperationService |
| WB/Ozon | По 3 demo listings, mapped к local SKU; всего 6 |
| Shopify-like | Отдельный `shopify_demo`, 2 parent и 4 variants; 2 mapped, 2 unmapped |
| Фото | 3 локальные SVG иллюстрации в public/demo-assets, synthetic маркировка, без сторонних CDN |
| Внешний inventory | 2 вымышленные locations, 8 snapshots: positive, confirmed zero, unknown/untracked |
| History | Один completed fixture run с provider=shopify_demo и details.synthetic_demo=true; не выдаётся за реальный API sync |

Procurement/physical fixtures используют действующие сервисы с реальным журналом и инвариантами. До установки demo flag command действует как доверенный CLI actor внутри незавершённой транзакции, после — writable пользователя не остаётся. Неизвестные физические SKU намеренно не имеют warehouse_stocks. Импортированные synthetic данные явно отмечены account.is_demo; origin=imported отражает существующую модель parent/variant, а не факт сетевого вызова.

## 6. Исправленные UX/контракты

1. `UnifiedCatalogPresenter` возвращает physical_quantity=null при отсутствии aggregate записи, а не искусственный 0. Vue показывает «Нет данных», сохраняет подтверждённый 0 и положительное число. Dashboard уже различал эти состояния; тест теперь сверяет оба API и неизменность warehouse_stocks.
2. ProductGallery запоминает failed URL в ограниченном наборе, убирает сломанный img из main/thumbnail/zoom и показывает placeholder. Повторных бесконечных загрузок нет, остальные изображения переключаются.
3. Zoom: Teleport в body, начальный фокус на close, document key listener только на время открытия, Escape, ArrowLeft/Right, Tab/Shift+Tab trap, явная кнопка и backdrop close, возврат фокуса opener, cleanup при unmount.
4. «Экспорт локальных SKU» вызывает существующий products/export, выгружая все local товары организации без фильтров общего каталога. Scope явно подписан рядом; external variants не обещаются. Busy/error states работают.
5. Gallery whitelist дополнен только строгими локальными `/demo-assets/[a-z0-9-]+.svg` путями, HTTP(S) совместимость сохранена.
6. User JSON дополнен is_demo_access. Добавлены public GET/POST auth/demo, provider shopify_demo и display capability history. Прежние API обычных пользователей не менялись. Контракты отмечены в docs/API.md, docs/EXCEL.md и OpenAPI.

## 7. Файлы этого этапа

Новые backend: `app/Console/Commands/DemoBootstrap.php`, `app/Http/Controllers/Api/V1/Auth/DemoSessionController.php`, `app/Http/Middleware/DemoReadOnly.php`, `app/Services/PublicDemo.php`, `app/Services/SyntheticDemoFixtures.php`, `app/Services/Marketplaces/SyntheticShopifyProvider.php`, `config/demo.php`, migration `2026_11_13_000001_add_demo_access_to_users.php`, `tests/Feature/PublicDemoTest.php`.

Изменённые backend/config: `.env.example`, User, UserResource, AppServiceProvider, bootstrap/app.php, routes/api.php и web.php, config/marketplaces.php, UnifiedCatalogPresenter; test MarketplaceFoundationTest учитывает четвёртый demo provider.

Новые frontend: `public/demo-assets/product-{1,2,3}.svg`, `src/features/onboarding/config/demoTours.ts`, `e2e/demo.spec.ts`, `playwright.demo.config.ts`, `scripts/run-e2e.mjs`.

Изменённые frontend: api/auth, types/index, stores/auth, router/index, AppLayout, ProductGallery и MarketplaceProductList, useProductTour/tourProgress и нейтральный loading-текст GettingStarted; страницы Landing, Products, CatalogDetail, Marketplaces, MarketplaceConnection, MarketplaceStocks, Stocks, Procurement, PurchaseOrder, GoodsReceipt, Dashboard. Markers скрывают только actions записи; безопасные controls сохранены. Обновлены package scripts, inventory/regression Playwright configs и актуальная landing assertion в app.spec.

Документация: этот отчёт, docs/API.md, docs/EXCEL.md, docs/openapi.json. Прежние TASK14/dashboard отчёты и остальные dirty dashboard файлы сохранены. Внешние изменения `.gitignore`/TASKS и посторонний `tg-bots/` не входят в выполненную реализацию, не добавлялись в Git.

## 8. Проверки

- Полный backend: **176 passed, 1424 assertions, 66.35 s, exit 0**. Log: `storage/logs/task15-backend-tests.log`. Включены dashboard, ordinary CRUD, tenant isolation, concurrency, Shopify fakes, demo write-route sweep, bootstrap guards/credentials/rerun/rollback и physical null/0/positive.
- Frontend: TypeScript, ESLint без warnings, Vitest **3 files / 10 tests**, production build **1746 modules**, exit 0.
- Полный Playwright regression: **19 passed, 6.0 min, runner exit 0**, без retries и зависания на teardown. Все 7 E2E-файлов: ordinary app CRUD, dashboard, demo, inventory, onboarding, procurement, Shopify/mock. Log: `frontend/test-results-task15-regression.log`.
- Новые Chrome demo E2E дополнительно усилены проверкой мобильного landing и Tab/Shift+Tab trap: повторно **3 passed, 54.8 s, runner exit 0**. Проверены landing login, 403 с корректным CSRF, readonly controls и routes, dashboard/demo, mobile overflow, broken photo/zoom/Escape/focus, local XLSX download, session-only tours, synthetic history и variants. Log: `frontend/test-results-task15-demo-final.log`.

Инфраструктура Windows: server processes запускаются напрямую (`php -S` и node Vite) без artisan/npm дополнительных дочерних процессов. Runner нормализует cwd через realpath, избегая двух экземпляров Playwright из-за регистра `projects/Projects`. Он объединяет native stdout/stderr и явно сообщает фактический exit code; PowerShell больше не должен выдавать предупреждения stderr за падение тестов. Реальные assertion failures остаются ненулевым exit.

Браузерные artifacts и logs находятся в игнорируемом frontend/test-results и test-results-task15-*.log. Это диагностические screenshots, не публикация реальных данных. Мобильные dashboard и landing просмотрены визуально; горизонтального overflow в demo test нет. Последний production build завершился за 5.25 s, exit 0; log `frontend/test-results-task15-build.log`. `git diff --check` прошёл.

## 9. Ограничения и готовность к FINAL 2

**Исходники готовы к FINAL 2 — подготовке отдельного hosting-окружения.** Общая регрессия успешна. Само публичное demo на рабочей БД не включено и не подготовлено — так и задумано. Нужны отдельные БД/env, применение schema migrations, bootstrap, frontend assets и проверка HTTP(S)/session/cache в целевой среде. Deployment/runbook/worker/cron/backup — следующий самостоятельный этап, здесь не выполнялись.

Не проводились live Shopify smoke, Linux smoke, публичный HTTPS или нагрузочный/pentest. Прежние ограничения больших previews/locations и отсутствие orders/финансов/real WB/Ozon остаются. Есть небольшое display-only расширение provider registry, полноценный RBAC/SaaS не добавлен. Предыдущие незафиксированные dashboard изменения остаются в рабочем дереве, selective commit/fresh-checkout проверка относятся к последующему оформлению.
