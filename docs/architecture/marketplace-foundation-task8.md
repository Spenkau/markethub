# TASK8 — единый фундамент подключений и внешних товаров

Реализовано 12 ноября 2026 на основе [аудита](marketplace-unification-audit.md). Это backend-фундамент; общий каталог UI и унификация Scheduler/истории остаются следующими этапами.

## Схема и сохранность данных

Новая миграция: `database/migrations/2026_11_12_000004_add_marketplace_foundation.php`.

- `marketplace_products`: нормализованный внешний родитель, org/account/external ID, title, description, status, images, raw source, даты источника и получения. Unique `(marketplace_account_id, external_id)`.
- `marketplace_credentials`: org/account, auth_type, `payload` с cast `encrypted:array`, version, timestamps. Одна запись на подключение, payload скрыта из serialization.
- `marketplace_accounts.configuration`: публичная валидируемая конфигурация; secrets передаются отдельным write-only объектом credentials.
- `marketplace_listings`: сохранены старые ID и product_id; добавлены marketplace_product_id, origin, variant_title, barcode, source_created_at/source_updated_at. Parent FK включает organization/account; внешняя идентичность остаётся `(account, external_id)`.
- Ограничение provider code в PostgreSQL допускает валидный код нового зарегистрированного адаптера; пользовательский ввод дополнительно ограничен ProviderRegistry.

`products`, склады, складские движения, закупки, Shopify Locations/Levels и старая история не переименованы и не пересозданы. Нового локального каталога или таблицы внешних вариантов нет.

До изменения схемы создан backup вне репозитория: `D:/ai/markethub-task8-backup-20261112`. Каталог защищён ACL текущего Windows-пользователя и SYSTEM. `before.dump` содержит PostgreSQL custom dump, `app-key.txt` — необходимый ключ восстановления; содержимое ключа не выводилось и не включается в Git. Архив реально восстановлен в отдельный PostgreSQL-кластер на loopback, сверены SHA-256 всех прежних колонок 14 контрольных таблиц и возможность расшифровать Shopify credentials сохранённым ключом. На восстановленной копии дополнительно выполнены новая миграция и два backfill. Временный сервер восстановления после проверки остановлен.

Команда backfill:

```powershell
php artisan marketplaces:backfill
```

Схема и backfill разделены, поэтому backfill можно безопасно повторить. Он читает строки по страницам, использует provider normalizer, группирует родителей по account/Product GID, выбирает более свежие parent metadata по source_updated_at/source_synced_at; при равенстве действует стабильный порядок listing ID. Malformed/missing source сохраняется в исходной строке, ошибка выводится только как ID и безопасная причина, команда возвращает ненулевой exit code. Raw source_data не удаляется. Повторный запуск не увеличивает credential version и не меняет timestamps идентичных записей.

Результат на рабочей базе:

| Показатель | До | После |
| --- | --- | --- |
| Локальные products | 76 | 76 |
| Подключения | 3 | 3 |
| Все marketplace_listings | 126 | 126 |
| WB/Ozon demo размещения | 100 | 100 |
| Shopify-размещения | 26 | 26 |
| Нормализованные внешние родители | 0 | 17 |
| Canonical encrypted credentials | 0 | 1 |
| Shopify Locations / Levels / Runs | 3 / 28 / 5 | 3 / 28 / 5 |
| Физические stocks / movements | 0 / 0 | 0 / 0 |

Все 26 Shopify listings получили parent FK; пропусков нет. Первый backfill: `credentials_created=1, variants_processed=26, skipped=0`. Повторный: `0, 26, 0`. WB/Ozon получили только новое значение origin=demo; их synthetic external ID не интерпретируются как настоящие ID площадок, внешние родители для demo не выдумывались.

**Проверена не только численность:** SHA-256 прежних колонок всех 14 таблиц совпал с backup после backfill. Это подтверждает сохранность всех существующих product/listing ID, product_id mapping, пользовательских полей, encrypted legacy credentials, цен, timestamps, складских/закупочных связей и истории. Shopify account 15 сохранил `manual`, `sync_products=true`, `sync_inventory=true`. Миграция не включает автоматическое расписание и не запускает импорт.

Манифесты проверки лежат рядом с backup: `before-snapshot.json`, `before-columns.json`, `restore-verified.json`, `upgrade-rehearsal.json`, `after-verification.json`. Секретных значений в манифестах нет. Guard миграции не разрешает обычный down при наличии новых данных; восстановление/обратный переход выполняются отдельной безопасной процедурой, а не удалением таблиц.

## Провайдеры и подключение

Реестр: `app/Services/Marketplaces/ProviderRegistry.php`, регистрация — `config/marketplaces.php`. Один `MarketplaceProvider` contract содержит definition, правила credentials/config и nullable callbacks проверки, импорта, нормализации и временной записи compatibility fields. Неподдерживаемые внешние возможности отсутствуют, вместо фиктивного успешного вызова возвращается понятная ошибка.

- `ShopifyProvider`: реальный check/import через сохранённые ShopifyClient/ShopifyImporter, преобразование Shopify payload в нормализованный parent/variant; compatibility hook хранит legacy encrypted поля и инвалидирует token cache.
- `DemoProvider`: Wildberries и Ozon, auth_type=none, внешних capabilities/callbacks нет. Проверка API отклоняется без HTTP-запроса; API credentials для demo не принимаются.
- `ConnectionService`: единственный процесс создания, валидации, изменения settings, credentials и отключения. Проверка проходит через выбранный adapter. Credential update выполняется под account lock, повышает version только при изменении payload; старая encrypted копия Shopify обновляется в той же транзакции.

Чтобы добавить провайдера, создать адаптер с определением auth/config/capabilities и поддерживаемыми callbacks и зарегистрировать его в config. Общие controllers и таблицу подключения менять не требуется. Это проверено тестовым API-key провайдером с новым кодом. Его тестовый адаптер находится только в тестах и не регистрируется в рабочей конфигурации. Подключение будущего провайдера к полному плановому sync-процессу потребует следующего этапа общей очереди; данный этап не обещает готовые реальные WB/Ozon API.

Client credentials Shopify относятся к установленному приложению и магазину, для которого разрешён этот grant. Это не готовый OAuth onboarding произвольных независимых продавцов; OAuth не имитируется.

Canonical reader использует marketplace_credentials, а при отсутствии записи безопасно читает legacy encrypted поля. ShopifyClient сохраняет свой HTTP transport, TLS, token refresh и throttling; изменён только источник credentials/token cache identity. Значения credentials и access token не возвращаются API. Ошибки проверки нормализуются без raw provider response и без записи секретов в логи.

## Внешние данные и mapping

`MarketplaceImportService` сохраняет только нормализованные внешние поля. При существующем размещении **product_id не входит в UPDATE вообще**, даже если устаревший вызывающий код передал его в DTO. Новое размещение всегда несопоставленное. Импорт не создаёт local products, не объединяет по SKU/названию и не меняет physical stock.

`CatalogMappingService` отдельно выполняет map/unmap и createLocalProduct. Создание локальной SKU требует явного POST с выбранным SKU. Account и listing блокируются в одинаковом порядке, проверяются org/account/product; повторное создание при существующем mapping возвращает 409. Существующие пользовательские сопоставления и local fields сохраняются при full/incremental/point Shopify import.

`GET /products` остаётся API локальных SKU и используется документами/ResourcePicker. Новые несопоставленные источники доступны в общем API размещений; весь frontend на новый каталог пока не переключён. В старом Shopify UI сохранены wrappers и поля-алиасы. Исправлена только устаревшая формулировка подтверждения, обещавшая автоматическое создание local SKU.

## Общий API

Все бизнес-endpoints требуют Sanctum и активную организацию.

| Метод / путь под `/api/v1` | Назначение |
| --- | --- |
| GET `/marketplace-providers` | Коды, название, connection/auth type, capabilities, публичные схемы write-only credentials/config |
| POST `/marketplace-accounts` | Общая операция создания |
| GET `/marketplace-accounts`, `/{account}` | Общие подключения, конфигурация, capabilities, integration summary |
| PATCH `/marketplace-accounts/{account}` | Имя, settings, валидируемая config и write-only credentials |
| POST `/marketplace-accounts/{account}/check` | Реальная проверка поддерживающего её adapter; demo → 422 без API-вызова |
| POST `/marketplace-accounts/{account}/disconnect` | Отключение без удаления истории/товаров |
| GET `/marketplace-listings`, `/{listing}` | Общий нормализованный read API, включая unmapped источники |
| PATCH `/marketplace-listings/{listing}/mapping` | `product_id: <local SKU ID>` или null |
| POST `/marketplace-listings/{listing}/local-product` | Явно создать local SKU и сопоставить: обязательный sku, необязательное name |

Пример POST подключения (значения условные):

```json
{
  "marketplace": "shopify",
  "name": "Мой магазин",
  "configuration": {"shop_domain": "store.myshopify.com"},
  "credentials": {"client_id": "APP_CLIENT_ID", "client_secret": "APP_CLIENT_SECRET"}
}
```

Credentials отсутствуют в ответе, включая зашифрованную payload. Demo создаётся с marketplace/name, без credentials. На PATCH provider code и domain существующего Shopify не меняются. Включение/отключение settings не сбрасывает несвязанные значения.

Списки размещений поддерживают прежние фильтры плюс `mapping=mapped|unmapped`, `origin=local|demo|imported`; поиск включает external title/SKU/barcode. Цена/валюта/nullable stock и legacy product/account fields сохранены, добавлены origin, parent ID, variant title/barcode/dates и `external_product` с normalized title/description/images/status. Общие resources, query и export больше не читают Shopify JSON paths.

Старые `/shopify/connections/...` сохранены. Их create/update/check/map вызывают общие сервисы; старые GET формы ответов и Shopify URL не удалены. Deprecated `shopify` и `shopify_listings` — compatibility aliases; новым клиентам предназначены `integration` и `external_listings`.

`ConnectLocalShopify` остался локальной CLI-оболочкой ConnectionService. `ShopifyClient` — transport, `ShopifyImporter` — legacy sync adapter, использующий общую запись нормализованного источника. `SyncShopify` выбирает import callback через registry. `ShopifySyncRun`/старые inventory tables/Scheduler пока сохранены, чтобы не ломать UUID и serialized queue payload; они не объявляются уже обобщёнными.

## Проверки и следующие этапы

- Полный backend: **108 passed, 888 assertions**.
- После финального выделения compatibility hook в адаптер: целевой набор marketplace/Shopify **36 passed, 273 assertions**.
- Реальная конкуренция PostgreSQL: два независимых процесса mapping/import одновременно ждут account lock; оба завершаются, новый mapping и source price сохраняются. Тест передаёт в import устаревший product_id=null, он не перезаписывает пользовательскую связь.
- Проверены backfill/repeat/malformed source, ID/mapping/demo/settings preservation, parent 1:N, одинаковый SKU разных магазинов, отсутствие auto-map, explicit create/unmap, cross-org/account isolation, encrypted payload/version, безопасные API/logs, legacy endpoints и новый API-key adapter без изменения controllers.
- TypeScript и ESLint прошли. Playwright Shopify — один расширенный сценарий прошёл; production build прошёл.
- Read smoke через настоящий Laravel HTTP kernel на рабочей БД: providers/accounts/listings/products/legacy Shopify list → 200; 3/3/26/76/26 записей соответственно. Credentials в ответах не обнаружены. Новый реальный импорт не запускался ради сохранения исходного снимка.

Тесты работают только на изолированной PostgreSQL `_test`; production destructive команды не запускались. DatabaseMigrations/fixtures тестов не направлялись на рабочую БД. После миграции worker/scheduler возвращены в работу; настройка существующего магазина остаётся manual.

Осталось для следующих этапов: общие sync runs/job/recovery/checkpoints вместо Shopify-only Scheduler; общие external locations/inventory и усиление старых FK; единый каталог UI с unmapped источниками и действием создания local SKU; общий detail/connection UI и обучение; устранение legacy aliases/columns только после завершения перехода. Текущие Shopify-specific history/summary queries ещё требуют общей модели и оптимизации агрегатов. Заказы, финансы и запись на площадки не добавлялись.
