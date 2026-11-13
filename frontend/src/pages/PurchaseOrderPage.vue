<script setup lang="ts">
import TourLauncher from '../features/onboarding/components/TourLauncher.vue';
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
    procurementApi,
    purchaseStatusNames,
    type PurchaseOrder,
    type GoodsReceipt,
} from '../api/procurement';
import { ApiError, message } from '../api/client';
import { amount } from '../utils/procurement';
import { shortDate } from '../utils/date';
import PageState from '../components/ui/PageState.vue';
import PurchaseForm from '../components/procurement/PurchaseForm.vue';
import ReceiptForm from '../components/procurement/ReceiptForm.vue';
const route = useRoute(),
    router = useRouter();
const order = ref<PurchaseOrder>(),
    loading = ref(true),
    error = ref(''),
    actionError = ref(''),
    busy = ref(false),
    editing = ref(false),
    receiving = ref(false),
    cancelling = ref(false);
async function load() {
    loading.value = true;
    error.value = '';
    try {
        order.value = await procurementApi.order(Number(route.params.id));
    } catch (e) {
        error.value = message(e);
    } finally {
        loading.value = false;
    }
}
onMounted(load);
async function transition(action: 'order' | 'cancel') {
    if (!order.value || busy.value) return;
    busy.value = true;
    actionError.value = '';
    try {
        order.value = await procurementApi.transitionOrder(order.value.id, action);
        cancelling.value = false;
    } catch (e) {
        actionError.value = message(e);
        if (e instanceof ApiError && Object.keys(e.fields).length)
            actionError.value += ' ' + Object.values(e.fields).flat().join(' ');
    } finally {
        busy.value = false;
    }
}
function saved(value: PurchaseOrder) {
    order.value = value;
    editing.value = false;
}
async function receiptSaved(value: GoodsReceipt) {
    receiving.value = false;
    await router.push(`/app/purchases/receipts/${value.id}`);
}
</script>
<template>
    <TourLauncher tour-id="purchases" />
    <RouterLink to="/app/purchases" class="text-link">← Все закупки</RouterLink
    ><PageState :loading="loading" :error="error" @retry="load"
        ><template v-if="order"
            ><div class="page-heading">
                <div>
                    <p class="eyebrow">ЗАКАЗ ПОСТАВЩИКУ</p>
                    <h1>Закупка #{{ order.id }}</h1>
                    <p>{{ order.supplier.name }} · {{ shortDate(order.created_at) }}</p>
                </div>
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
            </div>
            <p v-if="actionError && !cancelling" class="alert" role="alert">{{ actionError }}</p>
            <div class="inventory-actions">
                <template v-if="order.status === 'draft'"
                    ><button
                        class="secondary"
                        :disabled="busy"
                        data-demo-write
                        @click="editing = true"
                    >
                        Редактировать черновик</button
                    ><button
                        class="primary"
                        :disabled="busy"
                        data-demo-write
                        @click="transition('order')"
                    >
                        Отметить заказанной
                    </button></template
                ><button
                    v-if="['ordered', 'partially_received'].includes(order.status)"
                    class="primary"
                    :disabled="busy"
                    data-tour="purchase-receive"
                    data-demo-write
                    @click="receiving = true"
                >
                    Создать приёмку</button
                ><button
                    v-if="['draft', 'ordered', 'partially_received'].includes(order.status)"
                    class="secondary"
                    :disabled="busy"
                    data-demo-write
                    @click="
                        cancelling = true;
                        actionError = '';
                    "
                >
                    Отменить закупку</button
                ><RouterLink
                    :to="{
                        path: '/app/purchases',
                        query: { tab: 'receipts', purchase_order_id: order.id },
                    }"
                    class="text-link"
                    >Документы приёмки ({{ order.receipts_count ?? 0 }}) →</RouterLink
                >
            </div>
            <div class="purchase-detail-summary panel">
                <div>
                    <span>Поставщик</span><strong>{{ order.supplier.name }}</strong
                    ><small>{{ order.supplier.contact_name || 'Контакт не указан' }}</small>
                </div>
                <div>
                    <span>Склад назначения</span><strong>{{ order.warehouse.name }}</strong
                    ><small>{{ order.warehouse.code }}</small>
                </div>
                <div>
                    <span>Общая стоимость</span
                    ><strong>{{ amount(order.total_amount, order.currency) }}</strong
                    ><small>Закупочные цены, без изменения цен каталога</small>
                </div>
            </div>
            <section class="panel">
                <div class="section-heading padded">
                    <div>
                        <h2>Состав закупки</h2>
                        <p>Заказано, принято и осталось к получению</p>
                    </div>
                </div>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Товар / SKU</th>
                                <th>Заказано</th>
                                <th>Цена</th>
                                <th>Сумма</th>
                                <th>Получено</th>
                                <th>Осталось</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in order.items" :key="item.id">
                                <td>
                                    <RouterLink
                                        :to="`/app/products/${item.product.id}`"
                                        class="product-name"
                                        >{{ item.product.name }}</RouterLink
                                    ><span class="table-subtitle mono">{{ item.product.sku }}</span>
                                </td>
                                <td>{{ item.quantity }}</td>
                                <td class="nowrap">
                                    {{ amount(item.unit_price, order.currency) }}
                                </td>
                                <td class="nowrap">
                                    {{ amount(item.line_total, order.currency) }}
                                </td>
                                <td>
                                    <span
                                        class="badge"
                                        :class="
                                            item.received_quantity === item.quantity
                                                ? 'success'
                                                : ''
                                        "
                                        >{{ item.received_quantity }}</span
                                    >
                                </td>
                                <td>{{ item.remaining_quantity }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <div class="panel section-card procurement-note">
                <h2>Комментарий</h2>
                <p class="preserve-lines">{{ order.comment || 'Комментарий не добавлен.' }}</p>
                <p class="fine-print">
                    Автор: {{ order.creator?.name || '—' }}. Статус «Заказана» фиксируется в
                    MarketHub и не отправляет заказ поставщику.
                </p>
            </div>
            <PurchaseForm
                v-if="editing"
                :order="order"
                @close="editing = false"
                @saved="saved" /><ReceiptForm
                v-if="receiving"
                :order="order"
                @close="receiving = false"
                @saved="receiptSaved" /></template
    ></PageState>
    <div v-if="cancelling" class="modal-backdrop">
        <section
            class="modal"
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="cancel-purchase-title"
        >
            <h2 id="cancel-purchase-title">Отменить закупку?</h2>
            <p>
                Новые приёмки будут запрещены, черновики приёмок отменятся. Уже полученные товары и
                складские движения сохранятся.
            </p>
            <p v-if="actionError" class="alert" role="alert">{{ actionError }}</p>
            <div class="form-actions">
                <button
                    class="secondary"
                    :disabled="busy"
                    data-demo-write
                    @click="cancelling = false"
                >
                    Назад</button
                ><button
                    class="danger"
                    :disabled="busy"
                    data-demo-write
                    @click="transition('cancel')"
                >
                    Подтвердить отмену
                </button>
            </div>
        </section>
    </div>
</template>
