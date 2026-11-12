# MarketHub API v1

Базовый URL: `http://markethub.test/api/v1`. Формат: JSON. Указывайте `Accept: application/json` и для JSON-тел `Content-Type: application/json`.

## Сессия Sanctum

```js
const base = 'http://markethub.test';
await fetch(`${base}/sanctum/csrf-cookie`, { credentials: 'include' });
const xsrf = () => decodeURIComponent(document.cookie.split('; ').find(x => x.startsWith('XSRF-TOKEN=')).split('=').slice(1).join('='));
const response = await fetch(`${base}/api/v1/auth/login`, {
  method: 'POST', credentials: 'include',
  headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() },
  body: JSON.stringify({ email: 'your-account@example.com', password: '<ваш пароль>' }),
});
```

Этот пример предназначен для доверенного same-site SPA; настройте CORS и домены сессий для его адреса. На каждый изменяющий запрос передавайте актуальный XSRF-TOKEN. Постоянный bearer-токен не возвращается. В curl сначала сохраните cookie jar через `/sanctum/csrf-cookie`, затем отправляйте cookies и URL-decoded XSRF-TOKEN в заголовке, а также `Origin: http://markethub.test`.

| Метод | Маршрут | Ответ |
|---|---|---|
| POST | /auth/login | 200, data: id/name/email/is_admin/active_organization_id |
| GET | /auth/me | 200, те же поля текущего пользователя |
| POST | /auth/logout | 204, сессия завершается |
| GET | /organizations | 200, только членства текущего пользователя, пагинация |
| PATCH | /organizations/active | 200, организация; тело `{"organization_id":1}` |

Пароль/хеш и remember_token не сериализуются. Нет публичной регистрации, API пользователей или endpoint выдачи токенов.

## CRUD

| Ресурс | Список / создание | Одна запись / изменение / удаление |
|---|---|---|
| Товары | GET/POST /products | GET/PATCH/DELETE /products/{id} |
| Подключения | GET/POST /marketplace-accounts | GET/PATCH/DELETE /marketplace-accounts/{id} |
| Размещения | GET/POST /marketplace-listings | GET/PATCH/DELETE /marketplace-listings/{id} |
| Остатки | GET /stocks | Изменение через размещение |

Создание — 201 с обёрткой data. Чтение/изменение — 200 с data. Удаление — 204. PATCH частичный, PUT не используется. organization_id присваивает сервер; передавать его при создании/изменении бизнес-ресурсов запрещено. Нельзя передавать id, даты или административные признаки.

### Товар

POST /products:

```json
{"sku":"MOUSE-X100","name":"Беспроводная мышь","description":"Описание","price_minor":199000,"currency":"RUB","is_active":true}
```

Обязательны sku, name, price_minor, currency, is_active. description допускает null. SKU уникален внутри организации. price_minor — целое число 0–999999999999, валюта — RUB/USD/EUR/BYN/KZT/CNY/GBP. `199000` означает `1990,00 RUB`; дробный JSON-номер или отрицательное значение не допускаются. Поле БД base_price_minor напрямую API не принимает.

Пример ответа (даты приведены для формы ответа):

```json
{"data":{"id":1,"organization_id":1,"sku":"MOUSE-X100","name":"Беспроводная мышь","description":"Описание","price_minor":199000,"currency":"RUB","is_active":true,"created_at":"2026-11-10T10:00:00.000000Z","updated_at":"2026-11-10T10:00:00.000000Z"}}
```

GET /products/{id} добавляет listings_count. listings_url ведёт к пагинированным размещениям этого товара. Список товаров также содержит listings_count; неограниченные вложенные списки не возвращаются.

### Подключение

```json
{"marketplace":"ozon","name":"Учебное подключение","status":"demo","is_demo":true}
```

Все поля обязательны при POST. marketplace: wildberries/ozon, status: demo/active/disabled. Можно иметь несколько подключений одного типа. Реальные ключи не принимаются и не хранятся. Статус active — локальный признак, запросов к внешним API нет. Ответ содержит те же поля, id/organization_id/даты; GET карточки также содержит listings_count и listings_url для пагинированного просмотра размещений подключения.

### Размещение

```json
{"product_id":1,"marketplace_account_id":2,"external_id":"SYNTHETIC-OZON-1","external_sku":"DEMO-1","price_minor":199000,"currency":"RUB","stock_quantity":12,"status":"active"}
```

Обязательны product_id, marketplace_account_id, price_minor, currency, stock_quantity, status. Внешние ID/SKU допускают null. Статус active/inactive, остаток — целое число 0–2147483647. Товар и подключение должны принадлежать активной организации. Пара товар/подключение уникальна; непустой external_id уникален внутри подключения. Карточки/списки включают product (id/name/sku) и marketplace_account (id/name/marketplace/is_demo).

Удаление товара/подключения с размещениями возвращает 409. Сначала удалите размещения. Создание и изменение связей с чужой организацией возвращают 422; запрос чужого ID — 404.

## Списки

Все бизнес-списки пагинированы: data, links, meta. По умолчанию 20 записей, `per_page=1..100`, `page>=1`; превышение — 422. `direction=asc|desc`, по умолчанию asc. Поля сортировки разрешены явно:

| Список | sort | Фильтры / search |
|---|---|---|
| products | id, name, sku, price_minor, created_at | is_active=0/1; search по имени/SKU |
| marketplace-accounts | id, name, created_at | marketplace, status, is_demo=0/1; search по имени |
| marketplace-listings, stocks | id, price_minor, stock_quantity, created_at | product_id, marketplace_account_id, marketplace, status, stock=zero/low; search по товару/SKU/external_id |
| organizations | id, name, created_at | Только членства пользователя |

`stock=zero` — остаток 0, `stock=low` — 1–5. Поиск регистронезависимый, максимум 100 символов. Недопустимое поле сортировки — 422, SQL из клиента не используется. Все списки бизнес-данных ограничены активной организацией; список организаций ограничен членствами.

```http
GET /api/v1/products?search=мышь&is_active=1&sort=price_minor&direction=desc&per_page=10&page=1
GET /api/v1/stocks?marketplace=ozon&stock=zero
```

## Ошибки

Стандартные Laravel JSON-ошибки:

```json
{"message":"The price minor field must be at least 0.","errors":{"price_minor":["The price minor field must be at least 0."]}}
```

| HTTP | Значение |
|---|---|
| 401 | Нет авторизованной сессии |
| 403 | Нет активного членства для создания либо Policy запретила действие |
| 404 | Запись/членство отсутствует или скрыто изоляцией организации |
| 409 | Существуют зависимые записи либо конфликт уникальности при конкурентной записи |
| 419 | Не прошла CSRF-проверка; обновите cookies через /sanctum/csrf-cookie |
| 422 | Ошибка Form Request / бизнес-инварианта |
| 429 | Лимит входа (5/мин email+IP; 30/мин IP) |

Тексты ошибок зависят от локализации, клиент должен опираться на HTTP-код и ключи errors. SQL и пароли в обработчиках конфликтов не раскрываются.

OpenAPI: [openapi.json](openapi.json). Контракты описывают текущее поведение, полноценный frontend, организации/роли сверх членства, заказы и внешние интеграции отсутствуют.

## Физические склады и остатки

Новые endpoints: `GET/POST /api/v1/warehouses`, `GET/PATCH /api/v1/warehouses/{warehouse}`, `GET /api/v1/warehouse-stocks`, `GET /api/v1/warehouse-stocks/summary`, `GET /api/v1/stock-movements`, `POST /api/v1/stock-operations`. Существующий `/api/v1/stocks` продолжает возвращать остатки размещений на маркетплейсах.

Тела операций, фильтры, атомарность, UUID для повторных запросов и правила неизменяемой истории: [WAREHOUSES.md](WAREHOUSES.md). Спецификация новых маршрутов включена в [openapi.json](openapi.json).

## Закупки и поставки

Endpoints `/suppliers`, `/purchase-orders`, `/goods-receipts` под `/api/v1` и операции изменения статусов описаны в [PROCUREMENT.md](PROCUREMENT.md) и [openapi.json](openapi.json). Цены закупок — decimal-строки в основных денежных единицах; этот контракт отличается от price_minor каталога. Оприходование выполняется только POST `/goods-receipts/{id}/confirm` через существующий складской сервис.

## Обучение и реальный чек-лист

`GET /api/v1/onboarding`, `GET /api/v1/onboarding/checklist`, `PATCH /api/v1/onboarding/preferences`, `PATCH /api/v1/onboarding/tours/{tour}`. Прогресс относится к пользователю и версии, а чек-лист читает данные текущей организации. Добавлен `POST /api/v1/organizations` с name/request_id для выполнения первого этапа. Полный контракт и правила автоматических предложений: [ONBOARDING.md](ONBOARDING.md), схемы: [openapi.json](openapi.json).
