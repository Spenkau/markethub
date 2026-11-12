<script setup lang="ts">
import TourLauncher from '../features/onboarding/components/TourLauncher.vue';
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { procurementApi, receiptStatusNames, type GoodsReceipt } from '../api/procurement';
import { ApiError, message } from '../api/client';
import { shortDate } from '../utils/date';
import PageState from '../components/ui/PageState.vue';
import ReceiptForm from '../components/procurement/ReceiptForm.vue';
const route = useRoute();
const receipt = ref<GoodsReceipt>(),
    loading = ref(true),
    error = ref(''),
    actionError = ref(''),
    busy = ref(false),
    editing = ref(false),
    cancelling = ref(false);
const canEdit = computed(
    () =>
        receipt.value?.status === 'draft' &&
        ['ordered', 'partially_received'].includes(receipt.value.purchase_order.status),
);
const canConfirm = computed(
    () =>
        canEdit.value &&
        receipt.value?.purchase_order.warehouse.is_active &&
        receipt.value.items?.every(
            (item) =>
                item.quantity <=
                (receipt.value?.purchase_order.items?.find(
                    (line) => line.id === item.purchase_order_item_id,
                )?.remaining_quantity ?? 0),
        ),
);
async function load() {
    loading.value = true;
    error.value = '';
    try {
        receipt.value = await procurementApi.receipt(Number(route.params.id));
    } catch (e) {
        error.value = message(e);
    } finally {
        loading.value = false;
    }
}
onMounted(load);
async function action(type: 'confirm' | 'cancel') {
    if (!receipt.value || busy.value) return;
    busy.value = true;
    actionError.value = '';
    try {
        receipt.value =
            type === 'confirm'
                ? (await procurementApi.confirmReceipt(receipt.value.id)).data
                : await procurementApi.cancelReceipt(receipt.value.id);
        cancelling.value = false;
    } catch (e) {
        actionError.value = message(e);
        if (e instanceof ApiError && Object.keys(e.fields).length)
            actionError.value += ' ' + Object.values(e.fields).flat().join(' ');
    } finally {
        busy.value = false;
    }
}
function saved(value: GoodsReceipt) {
    receipt.value = value;
    editing.value = false;
}
</script>
<template>
    <TourLauncher tour-id="purchases" />
    <RouterLink to="/app/purchases?tab=receipts" class="text-link">← Все приёмки</RouterLink
    ><PageState :loading="loading" :error="error" @retry="load"
        ><template v-if="receipt"
            ><div class="page-heading">
                <div>
                    <p class="eyebrow">ДОКУМЕНТ ПРИЁМКИ</p>
                    <h1>Приёмка #{{ receipt.id }}</h1>
                    <p>
                        {{ receipt.purchase_order.supplier.name }} ·
                        {{ shortDate(receipt.created_at) }}
                    </p>
                </div>
                <span class="badge" :class="receipt.status === 'confirmed' ? 'success' : ''">{{
                    receiptStatusNames[receipt.status]
                }}</span>
            </div>
            <p v-if="actionError && !cancelling" class="alert" role="alert">{{ actionError }}</p>
            <div v-if="receipt.status === 'confirmed'" class="next-action-banner" role="status">
                <span
                    >Приёмка проведена. Товары учтены на складе «{{
                        receipt.purchase_order.warehouse.name
                    }}».</span
                ><RouterLink
                    :to="`/app/stocks?warehouse_id=${receipt.purchase_order.warehouse_id}`"
                    class="text-link"
                    >К остаткам склада →</RouterLink
                >
            </div>
            <div class="inventory-actions">
                <template v-if="receipt.status === 'draft'"
                    ><button
                        v-if="canConfirm"
                        class="primary"
                        :disabled="busy"
                        data-tour="receipt-confirm"
                        @click="action('confirm')"
                    >
                        {{ busy ? 'Проводим…' : 'Подтвердить приёмку' }}</button
                    ><button
                        v-if="canEdit"
                        class="secondary"
                        :disabled="busy"
                        @click="editing = true"
                    >
                        Редактировать приёмку</button
                    ><button
                        class="secondary"
                        :disabled="busy"
                        @click="
                            cancelling = true;
                            actionError = '';
                        "
                    >
                        Отменить приёмку
                    </button></template
                ><RouterLink :to="`/app/purchases/${receipt.purchase_order_id}`" class="text-link"
                    >Закупка #{{ receipt.purchase_order_id }} →</RouterLink
                >
            </div>
            <p v-if="receipt.status === 'draft'" class="info-banner">
                Документ ещё не проведён. После подтверждения все строки будут оприходованы одной
                транзакцией через складской сервис.
            </p>
            <div class="panel purchase-detail-summary">
                <div>
                    <span>Склад назначения</span
                    ><strong>{{ receipt.purchase_order.warehouse.name }}</strong
                    ><small>{{ receipt.purchase_order.warehouse.code }}</small>
                </div>
                <div>
                    <span>Создал</span><strong>{{ receipt.creator.name }}</strong
                    ><small>{{ shortDate(receipt.created_at) }}</small>
                </div>
                <div>
                    <span>Проведение</span
                    ><strong>{{ receipt.confirmer?.name || 'Не проведена' }}</strong
                    ><small>{{
                        receipt.confirmed_at
                            ? new Date(receipt.confirmed_at).toLocaleString('ru-RU')
                            : 'Складские остатки не изменены'
                    }}</small>
                </div>
            </div>
            <section class="panel">
                <div class="section-heading padded">
                    <h2>
                        {{
                            receipt.status === 'confirmed' ? 'Принятые товары' : 'Товары документа'
                        }}
                    </h2>
                </div>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Товар / SKU</th>
                                <th>Количество</th>
                                <th>Складское движение</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in receipt.items" :key="item.id">
                                <td>
                                    <RouterLink
                                        :to="`/app/products/${item.product.id}`"
                                        class="product-name"
                                        >{{ item.product.name }}</RouterLink
                                    ><span class="table-subtitle mono">{{ item.product.sku }}</span>
                                </td>
                                <td>{{ item.quantity }} шт.</td>
                                <td>
                                    <RouterLink
                                        v-if="item.stock_operation_id"
                                        :to="{
                                            path: '/app/stocks',
                                            query: {
                                                tab: 'movements',
                                                operation_id: item.stock_operation_id,
                                            },
                                        }"
                                        class="text-link"
                                        >Движение #{{ item.stock_movement_id }} →</RouterLink
                                    ><span v-else class="muted">После подтверждения</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <div class="panel section-card procurement-note">
                <h2>Комментарий</h2>
                <p class="preserve-lines">{{ receipt.comment || 'Комментарий не добавлен.' }}</p>
                <p v-if="receipt.status === 'confirmed'" class="fine-print">
                    Проведённая приёмка сохраняется без изменений. Повторное подтверждение не
                    создаёт новых остатков или движений.
                </p>
            </div>
            <ReceiptForm
                v-if="editing"
                :order="receipt.purchase_order"
                :receipt="receipt"
                @close="editing = false"
                @saved="saved" /></template
    ></PageState>
    <div v-if="cancelling" class="modal-backdrop">
        <section
            class="modal"
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="cancel-receipt-title"
        >
            <h2 id="cancel-receipt-title">Отменить черновик приёмки?</h2>
            <p>Остатки не изменятся. Отменённый документ останется в списке приёмок.</p>
            <p v-if="actionError" class="alert" role="alert">{{ actionError }}</p>
            <div class="form-actions">
                <button class="secondary" :disabled="busy" @click="cancelling = false">Назад</button
                ><button class="danger" :disabled="busy" @click="action('cancel')">
                    Подтвердить отмену
                </button>
            </div>
        </section>
    </div>
</template>
