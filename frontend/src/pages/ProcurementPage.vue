<script setup lang="ts">
import TourLauncher from '../features/onboarding/components/TourLauncher.vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Plus, Search, ShoppingCart, Users, PackageCheck, Pencil, Trash2 } from 'lucide-vue-next';
import {
    procurementApi,
    purchaseStatusNames,
    receiptStatusNames,
    type PurchaseOrder,
    type Supplier,
    type GoodsReceipt,
} from '../api/procurement';
import { inventoryApi, type Warehouse } from '../api/inventory';
import { productsApi } from '../api/products';
import type { Product } from '../types';
import { message } from '../api/client';
import { amount } from '../utils/procurement';
import { shortDate } from '../utils/date';
import type { Page } from '../types';
import PageState from '../components/ui/PageState.vue';
import PaginationBar from '../components/ui/PaginationBar.vue';
import ResourcePicker from '../components/inventory/ResourcePicker.vue';
import PurchaseForm from '../components/procurement/PurchaseForm.vue';
import SupplierForm from '../components/procurement/SupplierForm.vue';
const router = useRouter(),
    route = useRoute();
type Tab = 'orders' | 'suppliers' | 'receipts';
function routeTab(): Tab {
    return route.query.tab === 'suppliers' || route.query.tab === 'receipts'
        ? route.query.tab
        : 'orders';
}
const tab = ref<Tab>(routeTab()),
    search = ref(''),
    status = ref(String(route.query.status || '')),
    active = ref(''),
    supplierFilter = ref<number | null>(null),
    warehouseFilter = ref<number | null>(null),
    page = ref(1),
    perPage = ref(20);
const orders = ref<Page<PurchaseOrder>>(),
    suppliers = ref<Page<Supplier>>(),
    receipts = ref<Page<GoodsReceipt>>();
const loading = ref(true),
    error = ref(''),
    actionError = ref(''),
    busy = ref(false),
    showOrder = ref(false),
    showSupplier = ref(false),
    editing = ref<Supplier>(),
    deleting = ref<Supplier>();
const initialSupplier = ref<Supplier>(),
    initialWarehouse = ref<Warehouse>(),
    initialProduct = ref<Product>(),
    savedSupplier = ref<Supplier>();
const meta = computed(() =>
    tab.value === 'orders'
        ? orders.value?.meta
        : tab.value === 'suppliers'
          ? suppliers.value?.meta
          : receipts.value?.meta,
);
let sequence = 0;
let timer: ReturnType<typeof setTimeout>;
async function load() {
    const current = ++sequence;
    loading.value = true;
    error.value = '';
    const params = {
        page: page.value,
        per_page: perPage.value,
        search: search.value || undefined,
        status: status.value || undefined,
        supplier_id: supplierFilter.value ?? undefined,
        warehouse_id: warehouseFilter.value ?? undefined,
    };
    try {
        const data =
            tab.value === 'orders'
                ? await procurementApi.orders(params)
                : tab.value === 'suppliers'
                  ? await procurementApi.suppliers({
                        page: page.value,
                        per_page: perPage.value,
                        search: search.value || undefined,
                        is_active: active.value || undefined,
                    })
                  : await procurementApi.receipts({
                        ...params,
                        purchase_order_id: route.query.purchase_order_id
                            ? String(route.query.purchase_order_id)
                            : undefined,
                    });
        if (current !== sequence) return;
        if (tab.value === 'orders') orders.value = data as Page<PurchaseOrder>;
        else if (tab.value === 'suppliers') suppliers.value = data as Page<Supplier>;
        else receipts.value = data as Page<GoodsReceipt>;
    } catch (e) {
        if (current === sequence) error.value = message(e);
    } finally {
        if (current === sequence) loading.value = false;
    }
}
function schedule() {
    sequence++;
    loading.value = true;
    page.value = 1;
    clearTimeout(timer);
    timer = setTimeout(load, 250);
}
watch([search, status, active, supplierFilter, warehouseFilter, perPage], schedule);
watch(tab, () => {
    search.value = '';
    page.value = 1;
    if (routeTab() !== tab.value) {
        status.value = '';
        void router.replace({ query: { tab: tab.value === 'orders' ? undefined : tab.value } });
    } else {
        status.value = String(route.query.status || '');
    }
    schedule();
});
watch(
    () => route.query.tab,
    () => {
        tab.value = routeTab();
    },
);
watch(() => route.query.purchase_order_id, schedule);
watch(
    () => route.query.status,
    () => {
        status.value = String(route.query.status || '');
    },
);
async function applyContext() {
    if (route.query.create !== 'purchase' || showOrder.value) return;
    actionError.value = '';
    try {
        const [supplier, warehouse, product] = await Promise.all([
            route.query.supplier_id
                ? procurementApi.supplier(Number(route.query.supplier_id))
                : undefined,
            route.query.warehouse_id
                ? inventoryApi.warehouse(Number(route.query.warehouse_id))
                : undefined,
            route.query.product_id ? productsApi.get(String(route.query.product_id)) : undefined,
        ]);
        initialSupplier.value = supplier;
        initialWarehouse.value = warehouse;
        initialProduct.value = product;
        showOrder.value = true;
        await router.replace({ query: { ...route.query, create: undefined } });
    } catch (e) {
        actionError.value = message(e);
    }
}
function createPurchase() {
    initialSupplier.value = undefined;
    initialWarehouse.value = undefined;
    initialProduct.value = undefined;
    showOrder.value = true;
}
watch(() => route.query.create, applyContext);
onMounted(() => {
    void load();
    void applyContext();
});
onBeforeUnmount(() => {
    sequence++;
    clearTimeout(timer);
});
function editSupplier(supplier?: Supplier) {
    editing.value = supplier;
    showSupplier.value = true;
}
async function supplierSaved(value: Supplier) {
    savedSupplier.value = value;
    showSupplier.value = false;
    await load();
}
async function orderSaved(order: PurchaseOrder) {
    showOrder.value = false;
    await router.push(`/app/purchases/${order.id}`);
}
async function removeSupplier() {
    if (!deleting.value || busy.value) return;
    busy.value = true;
    actionError.value = '';
    try {
        await procurementApi.deleteSupplier(deleting.value.id);
        deleting.value = undefined;
        if (suppliers.value?.data.length === 1 && page.value > 1) page.value--;
        await load();
    } catch (e) {
        actionError.value = message(e);
    } finally {
        busy.value = false;
    }
}
function reset() {
    search.value = '';
    status.value = '';
    active.value = '';
    supplierFilter.value = null;
    warehouseFilter.value = null;
    void router.replace({ query: { tab: tab.value === 'orders' ? undefined : tab.value } });
}
</script>
<template>
    <div class="page-heading">
        <div>
            <p class="eyebrow">ЗАКУПОЧНЫЙ ЦИКЛ</p>
            <h1>Закупки и поставки</h1>
            <p>Поставщики, заказы и приёмка товаров на ваши склады.</p>
        </div>
        <button
            v-if="tab === 'suppliers'"
            class="primary"
            data-tour="create-supplier"
            data-demo-write
            @click="editSupplier()"
        >
            <Plus :size="17" />Новый поставщик</button
        ><button
            v-else
            class="primary"
            data-tour="create-purchase"
            data-demo-write
            @click="createPurchase"
        >
            <Plus :size="17" />Новая закупка
        </button>
    </div>
    <TourLauncher :tour-id="tab === 'suppliers' ? 'suppliers' : 'purchases'" />
    <div v-if="savedSupplier" class="next-action-banner" role="status">
        <span>Поставщик «{{ savedSupplier.name }}» сохранён.</span
        ><RouterLink
            v-if="savedSupplier.is_active"
            :to="`/app/purchases?create=purchase&supplier_id=${savedSupplier.id}`"
            class="text-link"
            data-demo-write
            >Создать закупку у поставщика →</RouterLink
        >
    </div>
    <p v-if="actionError && !deleting" class="alert" role="alert">{{ actionError }}</p>
    <section class="panel" data-tour="procurement-list">
        <div class="inventory-tabs" role="tablist" aria-label="Закупки и поставки">
            <button role="tab" :aria-selected="tab === 'orders'" @click="tab = 'orders'">
                <ShoppingCart :size="16" />Закупки</button
            ><button role="tab" :aria-selected="tab === 'suppliers'" @click="tab = 'suppliers'">
                <Users :size="16" />Поставщики</button
            ><button role="tab" :aria-selected="tab === 'receipts'" @click="tab = 'receipts'">
                <PackageCheck :size="16" />Приёмки
            </button>
        </div>
        <div class="toolbar inventory-toolbar">
            <div class="search-input">
                <Search :size="17" /><input
                    v-model="search"
                    maxlength="100"
                    aria-label="Поиск закупок и поставщиков"
                    :placeholder="
                        tab === 'suppliers'
                            ? 'Название, контакт или ИНН'
                            : 'Номер, поставщик, товар или SKU'
                    "
                />
            </div>
            <template v-if="tab === 'suppliers'"
                ><select v-model="active" aria-label="Активность поставщика">
                    <option value="">Все поставщики</option>
                    <option value="1">Активные</option>
                    <option value="0">Отключённые</option>
                </select></template
            ><template v-else
                ><select v-model="status" aria-label="Статус документа">
                    <option value="">Все статусы</option>
                    <option
                        v-for="(label, value) in tab === 'orders'
                            ? purchaseStatusNames
                            : receiptStatusNames"
                        :key="value"
                        :value="value"
                    >
                        {{ label }}
                    </option></select
                ><ResourcePicker
                    v-model="supplierFilter"
                    kind="suppliers"
                    label="Фильтр поставщика"
                    class="filter-picker" /><ResourcePicker
                    v-model="warehouseFilter"
                    kind="warehouses"
                    label="Фильтр склада"
                    class="filter-picker" /></template
            ><button class="text-link" @click="reset">Сбросить фильтры</button>
        </div>
        <div v-if="tab === 'receipts'" class="filter-context">
            {{
                route.query.purchase_order_id
                    ? `Приёмки закупки #${route.query.purchase_order_id}.`
                    : 'Создайте приёмку из карточки заказанной закупки.'
            }}
            Остатки изменяются при подтверждении документа.
        </div>
        <PageState :loading="loading" :error="error" :empty="!meta?.total" @retry="load"
            ><template #emptyActions
                ><button
                    v-if="tab === 'suppliers'"
                    class="secondary"
                    data-demo-write
                    @click="editSupplier()"
                >
                    Добавить первого поставщика</button
                ><button
                    v-else-if="tab === 'orders'"
                    class="secondary"
                    data-demo-write
                    @click="createPurchase"
                >
                    Создать первый черновик</button
                ><RouterLink v-else to="/app/purchases" class="text-link"
                    >Выбрать закупку для приёмки →</RouterLink
                ></template
            >
            <div class="table-scroll">
                <table v-if="tab === 'orders'">
                    <thead>
                        <tr>
                            <th>Закупка</th>
                            <th>Поставщик / склад</th>
                            <th>Статус</th>
                            <th>Сумма</th>
                            <th>Получено / заказано</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="order in orders?.data" :key="order.id">
                            <td>
                                <RouterLink :to="`/app/purchases/${order.id}`" class="product-name"
                                    >Закупка #{{ order.id }}</RouterLink
                                ><span class="table-subtitle">{{
                                    shortDate(order.created_at)
                                }}</span>
                            </td>
                            <td>
                                {{ order.supplier.name
                                }}<span class="table-subtitle">{{ order.warehouse.name }}</span>
                            </td>
                            <td>
                                <span
                                    class="badge"
                                    :class="
                                        order.status === 'received'
                                            ? 'success'
                                            : order.status === 'partially_received'
                                              ? 'warning'
                                              : ''
                                    "
                                    >{{ purchaseStatusNames[order.status] }}</span
                                >
                            </td>
                            <td class="nowrap">{{ amount(order.total_amount, order.currency) }}</td>
                            <td>
                                {{ order.received_quantity ?? 0 }} /
                                {{ order.ordered_quantity ?? 0 }} шт.
                            </td>
                        </tr>
                    </tbody>
                </table>
                <table v-else-if="tab === 'suppliers'">
                    <thead>
                        <tr>
                            <th>Поставщик</th>
                            <th>Контакт</th>
                            <th>ИНН / комментарий</th>
                            <th>Статус</th>
                            <th>Закупки</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="supplier in suppliers?.data" :key="supplier.id">
                            <td>
                                <strong>{{ supplier.name }}</strong
                                ><span class="table-subtitle">{{
                                    supplier.contact_name || 'Контакт не указан'
                                }}</span>
                            </td>
                            <td>
                                {{ supplier.phone || '—'
                                }}<span class="table-subtitle">{{ supplier.email || '—' }}</span>
                            </td>
                            <td>
                                {{ supplier.inn || '—'
                                }}<span class="table-subtitle movement-comment">{{
                                    supplier.comment || 'Без комментария'
                                }}</span>
                            </td>
                            <td>
                                <span class="badge" :class="supplier.is_active ? 'success' : ''">{{
                                    supplier.is_active ? 'Активен' : 'Отключён'
                                }}</span>
                            </td>
                            <td>{{ supplier.purchase_orders_count ?? 0 }}</td>
                            <td>
                                <div class="row-actions">
                                    <RouterLink
                                        v-if="supplier.is_active"
                                        :to="`/app/purchases?create=purchase&supplier_id=${supplier.id}`"
                                        class="text-link nowrap"
                                        data-demo-write
                                        >Создать закупку</RouterLink
                                    >
                                    <button
                                        class="icon-button"
                                        aria-label="Редактировать поставщика"
                                        data-demo-write
                                        @click="editSupplier(supplier)"
                                    >
                                        <Pencil :size="16" /></button
                                    ><button
                                        class="icon-button danger-text"
                                        aria-label="Удалить поставщика"
                                        data-demo-write
                                        @click="
                                            deleting = supplier;
                                            actionError = '';
                                        "
                                    >
                                        <Trash2 :size="16" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <table v-else>
                    <thead>
                        <tr>
                            <th>Приёмка</th>
                            <th>Закупка / поставщик</th>
                            <th>Склад</th>
                            <th>Статус</th>
                            <th>Количество</th>
                            <th>Проведена</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="receipt in receipts?.data" :key="receipt.id">
                            <td>
                                <RouterLink
                                    :to="`/app/purchases/receipts/${receipt.id}`"
                                    class="product-name"
                                    >Приёмка #{{ receipt.id }}</RouterLink
                                ><span class="table-subtitle">{{
                                    shortDate(receipt.created_at)
                                }}</span>
                            </td>
                            <td>
                                <RouterLink
                                    :to="`/app/purchases/${receipt.purchase_order_id}`"
                                    class="text-link"
                                    >Закупка #{{ receipt.purchase_order_id }}</RouterLink
                                ><span class="table-subtitle">{{
                                    receipt.purchase_order.supplier.name
                                }}</span>
                            </td>
                            <td>{{ receipt.purchase_order.warehouse.name }}</td>
                            <td>
                                <span
                                    class="badge"
                                    :class="receipt.status === 'confirmed' ? 'success' : ''"
                                    >{{ receiptStatusNames[receipt.status] }}</span
                                >
                            </td>
                            <td>{{ receipt.total_quantity ?? 0 }} шт.</td>
                            <td class="nowrap">
                                {{ receipt.confirmed_at ? shortDate(receipt.confirmed_at) : '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div></PageState
        >
        <div v-if="meta" class="table-footer">
            <label class="page-size"
                >На странице<select v-model="perPage">
                    <option :value="20">20</option>
                    <option :value="50">50</option>
                    <option :value="100">100</option>
                </select></label
            ><PaginationBar
                :meta="meta"
                :disabled="loading"
                @change="
                    page = $event;
                    load();
                "
            />
        </div>
    </section>
    <PurchaseForm
        v-if="showOrder"
        :initial-supplier="initialSupplier"
        :initial-warehouse="initialWarehouse"
        :initial-product="initialProduct"
        @close="showOrder = false"
        @saved="orderSaved"
    /><SupplierForm
        v-if="showSupplier"
        :supplier="editing"
        @close="showSupplier = false"
        @saved="supplierSaved"
    />
    <div v-if="deleting" class="modal-backdrop" @keydown.esc="!busy && (deleting = undefined)">
        <section
            class="modal"
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="supplier-delete-title"
        >
            <h2 id="supplier-delete-title">Удалить поставщика?</h2>
            <p>
                {{ deleting.name }}. Поставщика с закупками можно только отключить, чтобы сохранить
                документы.
            </p>
            <p v-if="actionError" class="alert" role="alert">{{ actionError }}</p>
            <div class="form-actions">
                <button
                    class="secondary"
                    :disabled="busy"
                    data-demo-write
                    @click="deleting = undefined"
                >
                    Отмена</button
                ><button class="danger" :disabled="busy" data-demo-write @click="removeSupplier">
                    Удалить
                </button>
            </div>
        </section>
    </div>
</template>
