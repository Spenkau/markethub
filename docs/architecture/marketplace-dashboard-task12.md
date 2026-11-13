# Единый бизнес-обзор MarketHub

> Исторический отчёт этапа dashboard. Финальные интерфейс, проверки и публикация описаны в [отчёте релиза](markethub-final-release.md).

Реализация 12 ноября 2026 года. Задание получено из `D:/Projects/TASK13.md`; внутри исходного файла оно названо TASK12 и требует именно это имя итогового документа.

## Назначение и границы

«Обзор» читает существующие бизнес-данные, не создавая второго каталога, аналитических таблиц, materialized views, кеша, event bus или финансового модуля. Нет новых миграций, изменений credentials или расписания подключений. Нет обращений к внешним API при чтении dashboard. Кнопка синхронизации использует прежний общий API TASK9.

Локальные SKU, внешние родительские товары, варианты, снимки по локациям и подключения имеют разные единицы подсчёта. Их нельзя складывать в число уникальных товаров. Физический склад и закупки всегда относятся к организации; provider/account фильтрует только внешний контур.

## Изменённые backend-классы

* `DashboardController::overview` — тонкая HTTP-точка: Sanctum на маршруте, Gate `viewAny` для WarehouseStock через InventoryPolicy, вызов query, JSON.
* `DashboardRequest` — provider через ProviderRegistry, account ID, include_demo, origin; organization_id запрещён. Активный tenant определяется по членству пользователя, не по входному ID.
* `DashboardQuery` — агрегаты PostgreSQL, scope, безопасные DTO подключений, предупреждения, последние операции и ссылки. Вся бизнес-статистика ограничена organization_id. Account ID дополнительно проверяется на принадлежность текущей организации и выбранному provider; чужой/несовместимый ID даёт 404. Отсутствие организации — 403.
* `MarketplaceInventoryQuery::snapshotRows` — из прежнего decorate выделена общая сборка canonical levels/location join. `decorate` использует её и сохраняет прежние фильтры; dashboard использует тот же join без искусственного HTTP Request. Для imported listing берётся inventory.available; listing.stock_quantity cache не прибавляется.
* `CatalogQuery::listingQuery` — применяет существующий is_demo к подключению, чтобы ссылки из dashboard не теряли исключение demo в разделе внешних остатков и экспорте.
* `routes/api.php` — новый маршрут внутри существующей auth:sanctum группы.

ConnectionReadService пакетно прикрепляет статистику и последний run подключений. ProviderRegistry предоставляет названия, demo-тип и capabilities; Shopify-specific JSON/GID/protocol не интерпретируются контроллером dashboard. В ответе перечислены разрешённые поля, а не сериализованные аккаунты/credentials/raw provider payload. Общий dispatcher и importer не переписывались. Уже имевшееся изменение форматирования SyncDispatcher не относится к этой задаче.

## Dashboard API contract

`GET /api/v1/dashboard/overview`

Параметры:

| Поле | Значение |
| --- | --- |
| provider | Код из ProviderRegistry: shopify, wildberries, ozon |
| marketplace_account_id | Положительный ID подключения текущей организации |
| include_demo | Boolean: 0 по умолчанию, 1 для явного включения demo |
| origin | Необязательное ограничение внешних размещений: local/imported/demo |

Provider и account при одновременном указании должны соответствовать друг другу. Выбор demo-провайдера в UI явно включает include_demo=1 и показывает заметное сообщение. При ручном выключении demo выбранная область может стать пустой; сервер не включает demo скрытно.

Ответ — объект `data`:

```text
{
  generated_at: ISO timestamp,
  scope: { organization_id, provider, marketplace_account_id, include_demo,
           origin, organization_wide, low_threshold },
  products: {...}, connections: {...}, physical: {...},
  external_inventory: {...}, procurement: {...},
  connection_rows: [...], connections_truncated: boolean,
  provider_distribution: [...], attention: [...], activity: [...],
  filters: { providers: [...], accounts: [...] },
  links: { catalog, inventory }, availability: {...}
}
```

`organization_wide` явно перечисляет local_skus/physical/procurement. `low_threshold=5`. `availability` сообщает наличие локальных товаров, складских записей, внешних снимков и состояние пустой организации. Ошибки input — 422, отсутствие авторизации — 401. Нет HTTP-кеша, который выдавал бы старое состояние run за живое.

## Определения показателей

### Товары и подключения

| Поле | Формула / единица |
| --- | --- |
| products.local_skus | COUNT(products) всей активной организации, включая неактивные SKU; не зависит от provider/demo фильтра |
| products.external_products | COUNT(DISTINCT marketplace_product_id), NULL исключается; внешние карточки с хотя бы одним размещением в scope |
| products.external_variants | COUNT(listings) в scope, независимо от mapping; один вариант считается однажды независимо от числа locations |
| products.real_variants / demo_variants | Часть external_variants по account.is_demo; их сумма равна external_variants |
| products.represented_local_skus | COUNT(DISTINCT listing.product_id), NULL исключается; один local SKU на трёх площадках остаётся одним SKU |
| products.unmapped_variants | COUNT(listings WHERE product_id IS NULL), не количество родителей |
| connections.total / real / demo | Количество выбранных подключений и разбиение по is_demo |
| connections.excluded_demo | Demo-подключения provider/account области, исключённые текущим include_demo=0 |
| connections.active_syncs | COUNT(runs WHERE status IN queued/running) выбранных подключений |
| connections.problems | Реальные active accounts: API status не connected либо самый новый run failed/partial; connection считается однажды |
| connections.overdue_syncs | Реальные active accounts с hourly/daily, включённым типом данных и initial_import_at; последняя успешная дата (или initial_import_at) старше двух интервалов — 2 часов / 2 суток |

Товары/варианты включают сохранённые неактивные источники: это объём данных системы, а не число доступных к продаже позиций. Родитель без размещений не увеличивает показатель. Если внешний родитель не нормализован, его варианты остаются вариантами, не искусственными родителями.

local_skus — инвентарь локальных записей организации. В products нет надёжного отдельного origin для каждой SKU, поэтому этот показатель не выдаётся за число коммерческих real-only товаров: даже при выключенном demo он остаётся по всей организации. Реальный внешний контур считается отдельно.

Последний run выбирается оконной функцией row_number по created_at DESC / id DESC. Старый completed не скрывает более новый failed/partial. Manual — настройка, не неисправность; overdue для него не создаётся. Disabled и demo не считаются проблемой реального API.

### Физические остатки

Сначала SQL группирует warehouse_stocks по product_id и складывает quantity на всех складах организации, включая неактивные склады с сохранённым фактическим наличием. Затем агрегируется число SKU:

| Поле | Формула |
| --- | --- |
| physical.tracked_skus | SKU, имеющие хотя бы одну warehouse_stocks запись |
| physical.zero_skus | Учтённая суммарная quantity = 0 |
| physical.low_skus | Учтённая суммарная quantity BETWEEN 1 AND 5 |
| physical.no_data_skus | local_skus - tracked_skus |
| physical.units | Сумма только физических quantity; внешние значения не прибавляются |
| physical.warehouses | Число активных физических складов организации |

Отсутствие warehouse_stocks не даёт подтверждённого нуля. Низкий итог SKU не считается как количество строк по складам: две строки 3+3 означают 6, а не два низких товара. Переход ведёт в общий складской раздел без ложного фильтра по отдельным строкам.

### Внешние остатки

Используется canonical inventory join TASK10. Для imported — одна строка на inventory level; для local/demo — недетализированная listing quantity. Кеш imported stock_quantity не участвует.

SQL группирует строки по listing.id. Полное известное количество — только если listing active и все его строки пригодны: для imported каждый level current, tracked, location active, available NOT NULL. Для local/demo требуется известная listing quantity. SUM не превращается в подтверждённый итог, если хотя бы одна часть неизвестна. Вариант с отключённой локацией консервативно не считается полностью актуальным.

| Поле | Формула |
| --- | --- |
| external_inventory.variants | Число вариантов в scope, не число levels |
| low_variants | Полный известный итог 1–5, без отрицательных составляющих |
| zero_variants | Полный известный итог 0, без отрицательных составляющих |
| unknown_variants | Нет полного известного актуального количества: отсутствующие/NULL/stale/untracked/inactive данные |
| no_snapshot_variants | Imported-варианты вообще без canonical level; отдельный подвид отсутствия данных |
| negative_variants | В любой строке есть отрицательное количество, даже если сумма с другими строками неотрицательна |
| untracked_variants | Есть level с tracked=false |
| stale_variants | Есть level current=false или отключённая location |
| snapshots | Число canonical inventory levels выбранных listings |
| stale_snapshots | Число таких levels с current=false или отключённой location |

Диагностические категории могут пересекаться: например, untracked/stale одновременно входят в unknown. Их нельзя складывать в общее количество проблемных вариантов. no_snapshot отличается от наличия снимка с неизвестным available. Отрицательные значения сохраняются и не превращаются в ноль. Freshness соответствует признакам TASK10, а не новому произвольному TTL: выключенный manual sync сам по себе не делает снимок ошибочным.

### Закупки

| Поле | Реальные статусы |
| --- | --- |
| procurement.open_orders | draft, ordered, partially_received |
| draft_orders | draft |
| awaiting_receipt | ordered |
| partially_received | partially_received |
| draft_receipts | goods_receipts.status=draft |

Не суммируются денежные значения разных валют. Вообще нет KPI выручки, прибыли или стоимости остатков. Подтверждение приёмки меняет реальный purchase status и физический склад через существующий сервис; dashboard только читает результат.

## Demo/real и фильтры

По умолчанию внешние данные ограничены account.is_demo=false и listing.origin!=demo. При include_demo=1 дополнительные данные включаются явно. Карточка вариантов показывает real/demo-разбиение; распределение группируется по provider + is_demo + origin, карточки подключений и события помечены demo. Демонстрационный account не показывается как успешная проверка реального API и не имеет кнопки sync.

Provider/account/origin фильтрует внешние товары и inventory. Origin не меняет сами подключения и историю их запусков: это свойство размещения. Организационные local_skus, physical и procurement не фильтруются по площадке. Последние складские движения и приёмки остаются по всей организации, что подписано в UI.

Фильтр сохраняется в URL: provider, marketplace_account_id, include_demo; origin поддерживается API и ссылками. Переключение организации перемонтирует страницу существующим AppLayout. Если сохранённый account URL недоступен в новой организации, сервер возвращает 404, frontend снимает account-фильтр и загружает новую область. Старые данные не используются как ответ новой организации.

## Подключения, внимание и события

В карточке подключения: provider, name, demo/real, API status и дата проверки, последний run, последняя успешная синхронизация, manual/следующая дата, количество вариантов и unmapped. Ошибки представлены безопасными общими фразами. Кнопка sync разрешена capability sync и только active real account; активный queued/running блокирует повторную кнопку. Первоначальный импорт требует существующего подтверждения. После запуска обзор обновляется; при активных runs выполняется polling раз в 10 секунд только в видимой вкладке.

Предупреждения формируются из ненулевых агрегатов: problems, overdue, unmapped, stale snapshots, low external, zero/low physical, ordered purchases и partially_received. Приёмка помечена info, а не API error. Строка содержит count, описание и URL. Нет постоянной ошибки для manual. Если данных недостаточно, интерфейс не утверждает, что запасы в порядке.

История — UNION ALL реальных lifecycle timestamps sync runs, stock_movements.occurred_at и goods_receipts.confirmed_at. По каждому источнику берутся последние 12, затем общие последние 12. Для sync используется finished_at / started_at / created_at по доступности. Это одна текущая запись запуска, не журнал каждой смены его статуса. Created/updated_at произвольного товара не выдаются за историю. Отдельная история смены purchase status не создаётся, поскольку она не хранится как аудит.

## Frontend и сквозные переходы

* `DashboardPage.vue` — URL scope, четыре главных KPI, внимание, подключения, простое распределение, внешние/физические остатки, закупки и события. Scoped CSS, сетка desktop/tablet/mobile, без chart-библиотеки. KPI и внимание стоят выше существующего чек-листа, чтобы показатели были на первом экране. Пустые диаграммы не рисуются.
* `api/dashboard.ts` — типизированный общий API и DTO.
* `MarketplaceProductList.vue` — сохраняет origin из контекстного URL при запросе.
* `MarketplaceStocksPage.vue` — читает is_current, is_demo и origin из URL и передаёт общему API/экспорту.
* `ProcurementPage.vue` — применяет status из URL, в том числе при навигации внутри текущей страницы.
* `features/onboarding/config/tours.ts` — welcome version 2, шаг фильтра, уточнённая семантика KPI и шаг внимания. Существующие GettingStarted/TourHost/Next/Back/Skip сохранены, новой системы обучения нет.

| Действие | Куда ведёт |
| --- | --- |
| Локальные SKU | /app/products?kind=local |
| Внешние товары/варианты | Общий каталог с marketplace/account/is_demo/origin |
| Несопоставленные | Тот же каталог + mapping=unmapped |
| Устаревшие снимки | Общие внешние остатки + is_current=0 и scope |
| Внешние низкие остатки | Общий список выбранного scope; не ложный фильтр по отдельной локации вместо суммы варианта |
| Физические ноль/низкий итог SKU | Общий физический склад; строковый low не подменяет агрегат по SKU |
| Проблема подключения | Общая страница выбранного account либо список подключений с provider scope |
| История account | /app/marketplaces/{id}?tab=history |
| Ordered / partially_received | /app/purchases?status=ordered / partially_received |
| Draft receipts | /app/purchases?tab=receipts&status=draft |
| Подтверждённая приёмка | Существующая /app/purchases/receipts/{id} |

## Производительность и безопасность

COUNT/DISTINCT/FILTER/SUM/BOOL_AND/BOOL_OR/GROUP BY выполняются PostgreSQL. Все товары и listings не загружаются в PHP для подсчёта. ConnectionReadService читает stats/latest-run пакетно. SQL-запросов постоянное количество при добавлении магазинов; тест сравнивает один и 16 accounts и требует менее 40 запросов. На рабочем наборе получено 22–23 запроса, включая проверки tenant context. Индексы существующей схемы по organization/account/listing/run используются без новых миграций. Небольшой production-набор не доказывает производительность на миллионах строк.

Preview подключений ограничен 50 с connections_truncated и ссылкой на полный раздел. Options account-фильтра ограничены 100. События ограничены 12 после bounded source queries. Агрегаты считаются для полного scope, а не только preview. Кеш не вводился.

## Проверка рабочих данных

12 ноября 2026, 20:09 UTC. Запросы выполнены внутри `REPEATABLE READ, READ ONLY`, завершены ROLLBACK. Без внешнего API, импорта и изменения настроек.

| Показатель | Только реальные | С явным demo |
| --- | --- | --- |
| Локальные SKU всей организации | 76 | 76 |
| Внешние родители | 17 | 17 |
| Внешние варианты | 26 | 126 = 26 real + 100 demo |
| Представленные local SKU | 26 | 76 |
| Подключения | 1 real | 1 real + 2 demo |
| Физические tracked/zero | 0 / 0 | 0 / 0 |
| Нет физического учёта SKU | 76 | 76 |
| Canonical external levels | 28 | 28 |
| Известный внешний ноль | 5 | 21 |
| Неизвестный полный внешний итог | 3 | 13 |
| Untracked variants | 1 | 1 |
| Stale snapshots | 0 | 0 |

Первое чтение заняло около 118 ms, последующие 26–27 ms в локальном процессе; это наблюдение, не SLA/нагрузочный benchmark. Account 15 остаётся manual, sync_products=true, sync_inventory=true. Рабочая база не очищалась, миграции на ней не запускались.

## Тестирование

Backend: полный набор **169 тестов / 1328 assertions**, включая 10 новых Dashboard Feature Tests. Покрыты разные единицы подсчёта, несколько providers/locations, NULL/zero/negative/stale/untracked/no-level, физический sum по SKU, manual/latest failed/partial, account/provider и ссылки, tenant switch и чужие IDs, валидация, bounded queries, отсутствие raw/secrets/API-запросов, реальные приёмки и движения, demo inventory links.

Frontend: TypeScript typecheck, ESLint, production build и Pint проходят; Vitest — **10 тестов / 3 файла**. Итоговый production build включает новую страницу и исправленные контекстные переходы.

Для браузера добавлены два Dashboard E2E: real/demo и URL-фильтры, точные KPI, переходы unmapped/stale/purchase, экскурсия Next/Back/Skip, реальный POST sync с ответом 202, mobile 390px без горизонтального overflow, смена организации со снятием чужого account-фильтра и empty state. Подготовка PHP-фикстуры проверяет явный маркер готовности, чтобы исключение при stdin-запуске не было принято за успешный setup только из-за кода завершения.

Добавлены npm-команды `test:e2e:dashboard` и `test:e2e:regression`. Общая regression-конфигурация запускает 16 сценариев последовательно, без параллельного сброса общей изолированной test DB. Старые app/inventory/onboarding E2E обновлены под новые элементы, API ошибок и welcome version 2 (7 шагов); бизнес-проверки сохранены.

**Итоговая браузерная регрессия: 16 из 16 сценариев прошли (5,2 минуты)** — app/Sanctum, dashboard, inventory, onboarding, procurement и Shopify/TASK11. Скриншоты desktop/mobile/empty state получены в Playwright artifacts; горизонтальный overflow на 390px отсутствует. Все DB fixtures выполнялись только в PostgreSQL `_test` с предварительной защитой; рабочая база не очищалась.

## Ограничения и следующий этап

Нет метрики «уникальные коммерческие товары всех источников»: mapping может быть неполным, поэтому неоднозначный total не придуман. Нет отдельного origin у локальной SKU. Классификации внешнего наличия консервативны и могут пересекаться. Старый current=true снимок не становится stale только по возрасту: отображается существующая TASK10-семантика. История sync — текущий lifecycle run, не полный журнал переходов.

Для более 50 подключений карточки открываются в общем разделе; account options ограничены 100, остальные account IDs поддерживаются через URL и существующий список подключений. Следующий обоснованный этап — пагинация большого списка подключений/фильтра и проверка EXPLAIN на действительно большом наборе. Заказы, финансовая аналитика, webhooks и новые реальные провайдеры в этот этап не входят.
