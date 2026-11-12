import type { TourDefinition } from '../types';
const target = (id: string) => `[data-tour="${id}"]`;
const purchaseForm = { target: target('create-purchase'), close: target('close-purchase-form') };
const supplierForm = { target: target('create-supplier'), close: target('close-supplier-form') };
export const tours: TourDefinition[] = [
    {
        id: 'welcome',
        version: 1,
        title: 'Первое знакомство с MarketHub',
        description: 'Организация, навигация и общий путь от каталога до поставки.',
        trigger: { type: 'first-login', snoozeHours: 24 },
        steps: [
            {
                id: 'welcome',
                route: '/app',
                title: 'Добро пожаловать в MarketHub',
                description:
                    'Здесь связаны товары, физические склады, поставщики и закупки. Экскурсия ничего не создаёт и не меняет в учёте.',
            },
            {
                id: 'navigation',
                target: target('main-navigation'),
                title: 'Разделы рабочего кабинета',
                description:
                    'Каталог описывает товары, склады учитывают наличие, закупки связывают поставщиков и приёмку.',
                placement: 'right',
                reveal: { target: target('open-navigation'), close: target('close-navigation') },
            },
            {
                id: 'organization',
                target: target('organization-switcher'),
                title: 'Вы работаете внутри организации',
                description:
                    'Выбранная организация определяет видимые товары, склады и документы. Прогресс обучения принадлежит лично вам и сохраняется при переключении организации.',
            },
            {
                id: 'checklist',
                target: target('getting-started'),
                title: 'Ваш следующий шаг',
                description:
                    'Чек-лист отмечает реальные записи текущей организации. Можно начать с любого доступного этапа. Пройденная экскурсия не заменяет бизнес-действие.',
            },
            {
                id: 'overview',
                target: target('dashboard-kpis'),
                title: 'Показатели без выдуманных цифр',
                description:
                    'Здесь текущие данные каталога и размещений. Физические складские остатки показаны отдельно. Источников выручки и внешней синхронизации пока нет.',
            },
        ],
    },
    {
        id: 'products',
        version: 1,
        title: 'Товары и единый каталог',
        description: 'Карточка, SKU и переход к закупке или складским остаткам.',
        trigger: { type: 'first-visit', route: '/app/products' },
        steps: [
            {
                id: 'catalog',
                route: '/app/products',
                target: target('product-list'),
                title: 'Единый каталог',
                description:
                    'Один товар можно учитывать на нескольких складах и связывать с размещениями площадок. Карточка хранит название, SKU и цену продажи.',
            },
            {
                id: 'create',
                target: target('create-product'),
                title: 'Создать товар',
                description:
                    'Эта кнопка открывает форму. Сохранение карточки само по себе не создаёт складского остатка.',
            },
            {
                id: 'sku',
                route: '/app/products/new',
                target: target('product-sku'),
                title: 'SKU — ваш артикул',
                description:
                    'Укажите уникальный артикул внутри организации. По нему удобно искать товар в остатках и выбирать строки закупки.',
            },
            {
                id: 'details',
                route: '/app/products',
                target: target('product-list'),
                title: 'От товара к следующему действию',
                description:
                    'Название в таблице открывает карточку. Из неё можно создать закупку с этим товаром или открыть его физические остатки. В пустом каталоге сначала сохраните первую карточку.',
            },
        ],
    },
    {
        id: 'warehouses',
        version: 1,
        title: 'Склады и остатки',
        description: 'Где хранить товары, как проводить операции и читать историю.',
        trigger: { type: 'first-visit', route: '/app/stocks' },
        steps: [
            {
                id: 'warehouse-list',
                route: '/app/stocks?tab=warehouses',
                target: target('warehouse-list'),
                title: 'Склады вашей организации',
                description:
                    'Склад — место физического учёта товара. Есть собственные склады и склады маркетплейсов с ручным учётом; реального обмена с API площадок нет.',
            },
            {
                id: 'create',
                target: target('create-warehouse'),
                title: 'Добавить склад',
                description:
                    'Задайте название, уникальный код и тип. После сохранения можно добавить товар или оформить закупку.',
            },
            {
                id: 'stocks',
                route: '/app/stocks',
                target: target('warehouse-list'),
                title: 'Наличие по товару и складу',
                description:
                    'Строка появляется после складской операции. Товары без операций не создают фиктивных нулевых остатков. Фильтры помогут выбрать склад и позиции без наличия.',
            },
            {
                id: 'operations',
                target: target('warehouse-operations'),
                title: 'Поступление, списание и перемещение',
                description:
                    'Поступление добавляет количество, списание уменьшает его, перемещение атомарно переносит между складами. Инвентаризация устанавливает пересчитанный остаток. Отрицательные остатки запрещены.',
            },
            {
                id: 'history',
                route: '/app/stocks?tab=movements',
                target: target('warehouse-list'),
                title: 'История движений',
                description:
                    'Каждое изменение хранит количество до и после, автора и дату. У движений от приёмки есть ссылка на документ; у перемещения обе части связаны общим идентификатором.',
            },
        ],
    },
    {
        id: 'suppliers',
        version: 1,
        title: 'Поставщики',
        description: 'Контакты, создание поставщика и закупка с выбранным партнёром.',
        trigger: { type: 'first-visit', route: '/app/purchases', query: { tab: 'suppliers' } },
        steps: [
            {
                id: 'list',
                route: '/app/purchases?tab=suppliers',
                target: target('procurement-list'),
                title: 'Ваши поставщики',
                description:
                    'Здесь контакты и активность поставщиков. Поиск работает по названию, контактному лицу, телефону, email и ИНН.',
            },
            {
                id: 'create',
                target: target('create-supplier'),
                title: 'Добавить поставщика',
                description:
                    'Название обязательно. Контактные данные и ИНН можно заполнить позже. Экскурсия лишь покажет пустую форму.',
            },
            {
                id: 'fields',
                target: target('supplier-fields'),
                reveal: supplierForm,
                title: 'Контакты для работы',
                description:
                    'Сохраните сведения партнёра. Поставщика с закупками можно отключить, сохранив связанные документы.',
            },
            {
                id: 'purchase',
                route: '/app/purchases?tab=suppliers',
                target: target('procurement-list'),
                title: 'Следующий шаг — закупка',
                description:
                    'После создания поставщика появится переход к новой закупке. В строке таблицы также есть действие «Создать закупку» с уже выбранным поставщиком.',
            },
        ],
    },
    {
        id: 'purchases',
        version: 1,
        title: 'Закупки и поставки',
        description: 'От черновика заказа до частичной приёмки и складских движений.',
        trigger: { type: 'first-visit', route: '/app/purchases', query: { tab: null } },
        steps: [
            {
                id: 'orders',
                route: '/app/purchases',
                target: target('procurement-list'),
                title: 'Заказы поставщикам',
                description:
                    'Закупка связывает поставщика, склад назначения и товары. Статусы показывают путь от черновика до полного получения.',
            },
            {
                id: 'create',
                target: target('create-purchase'),
                title: 'Создать закупку',
                description:
                    'Сначала сохраните черновик. Проверив состав, в карточке отметьте закупку заказанной. Это не отправляет заказ поставщику автоматически.',
            },
            {
                id: 'parties',
                target: target('purchase-parties'),
                reveal: purchaseForm,
                title: 'Поставщик и склад назначения',
                description:
                    'Выберите активного поставщика и склад своей организации. Товары при подтверждении приёмки поступят именно на этот склад.',
            },
            {
                id: 'items',
                target: target('purchase-items'),
                reveal: purchaseForm,
                title: 'Товары, количество и закупочная цена',
                description:
                    'Добавьте позиции из каталога, укажите количество и цену. Закупочная цена не меняет цену продажи, суммы рассчитываются точно.',
            },
            {
                id: 'receive',
                route: '/app/purchases',
                target: target('procurement-list'),
                title: 'Принять всю поставку или часть',
                description:
                    'Откройте заказанную закупку и нажмите «Создать приёмку». Укажите фактически прибывшее количество; нулевые строки не включаются. Документ сначала сохраняется как черновик.',
            },
            {
                id: 'confirmation',
                route: '/app/purchases?tab=receipts',
                target: target('procurement-list'),
                title: 'Подтвердить документ и увидеть остатки',
                description:
                    'В документе нажмите «Подтвердить приёмку». Только тогда увеличатся физические остатки и появятся движения. Повтор не удвоит товар. Из проведённой приёмки можно перейти к остаткам склада.',
            },
        ],
    },
    {
        id: 'marketplaces',
        version: 1,
        title: 'Маркетплейсы и размещения',
        description: 'Связь площадки с каталогом и ограничения текущих подключений.',
        trigger: { type: 'first-visit', route: '/app/marketplaces' },
        steps: [
            {
                id: 'connections',
                route: '/app/marketplaces',
                target: target('marketplace-heading'),
                title: 'Подключения организации',
                description:
                    'Карточки описывают аккаунты Ozon и Wildberries. Сохранённый статус «Активно» — локальный признак, а не результат внешней синхронизации.',
            },
            {
                id: 'create',
                target: target('create-marketplace'),
                title: 'Добавить подключение',
                description:
                    'Укажите площадку и имя аккаунта. API Ozon и Wildberries пока не интегрированы. Для Shopify доступен отдельный импорт в MarketHub.',
            },
            {
                id: 'catalog',
                route: '/app/marketplace-stocks',
                target: target('marketplace-stock-list'),
                title: 'Размещения и единый каталог',
                description:
                    'Размещение связывает товар каталога с аккаунтом площадки. Его остаток показан отдельно от физического наличия на складах и не суммируется с ним.',
            },
        ],
    },
    {
        id: 'shopify',
        version: 1,
        title: 'Shopify: подключение и импорт',
        description: 'Первый импорт, варианты каталога, внешние остатки и расписание.',
        trigger: {
            type: 'first-visit',
            route: '/app/marketplaces',
            query: { marketplace: 'shopify' },
        },
        steps: [
            {
                id: 'connection',
                route: '/app/marketplaces',
                target: target('shopify-connect'),
                title: 'Подключение Shopify',
                description:
                    'Каждый магазин имеет независимые реквизиты и историю. Откройте управление магазином после экскурсии.',
            },
            {
                id: 'import',
                title: 'Первый импорт требует подтверждения',
                description:
                    'В обзоре подключения проверьте API и нажмите «Выполнить первый импорт». Данные обрабатываются в очереди; статус и результат видны в обзоре.',
            },
            {
                id: 'products',
                title: 'Товары и варианты',
                description:
                    'Вкладка «Товары» показывает цены, статусы, SKU и варианты Shopify. Новые однозначные варианты получают карточки каталога; локальные правки повторный импорт не перезаписывает.',
            },
            {
                id: 'mapping',
                title: 'Сопоставление с каталогом',
                description:
                    'Совпадение SKU не связывает товар автоматически. Для несопоставленного варианта нажмите «Сопоставить» и выберите товар MarketHub. Данные Shopify не меняются.',
            },
            {
                id: 'inventory',
                title: 'Внешние остатки',
                description:
                    'Вкладка «Остатки» показывает Shopify Locations и время снимка. Эти количества не суммируются с физическими запасами MarketHub. «—» означает неизвестное количество.',
            },
            {
                id: 'schedule',
                title: 'Автоматическое обновление',
                description:
                    'Во вкладке «Настройки» выберите каждый час, раз в сутки или вручную. Товары и остатки включаются независимо. Расписание начинает работать после успешного первого импорта, при работающем компьютере, Scheduler и worker.',
            },
            {
                id: 'history',
                title: 'История и диагностика',
                description:
                    'Во вкладке «История» доступны все запуски, длительность и безопасные причины ошибок. Частичное завершение не означает полный успех. Интеграция работает только Shopify → MarketHub.',
            },
        ],
    },
];
export const tourStatusNames = {
    not_started: 'Не начата',
    in_progress: 'В процессе',
    completed: 'Завершена',
    skipped: 'Пропущена',
    snoozed: 'Отложена',
};
