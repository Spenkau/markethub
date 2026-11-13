<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { inventoryApi } from '../../api/inventory';
import { procurementApi } from '../../api/procurement';
import { productsApi } from '../../api/products';
import { message } from '../../api/client';
const props = defineProps<{
    kind: 'products' | 'warehouses' | 'suppliers';
    selectedOption?: { id: number; label: string };
    label: string;
    activeOnly?: boolean;
    expanded?: boolean;
}>();
const model = defineModel<number | null>({ required: true });
const search = ref(''),
    page = ref(1),
    lastPage = ref(1),
    loading = ref(false),
    error = ref('');
const options = ref<{ id: number; label: string }[]>([]),
    chosen = ref<{ id: number; label: string } | undefined>(props.selectedOption);
const visibleOptions = computed(() =>
    chosen.value && !options.value.some((o) => o.id === chosen.value?.id)
        ? [chosen.value, ...options.value]
        : options.value,
);
let sequence = 0;
let timer: ReturnType<typeof setTimeout>;
async function load() {
    const current = ++sequence;
    loading.value = true;
    error.value = '';
    try {
        const params = { page: page.value, per_page: 20, search: search.value || undefined };
        const response =
            props.kind === 'products'
                ? await productsApi.list(params)
                : props.kind === 'suppliers'
                  ? await procurementApi.suppliers({
                        ...params,
                        is_active: props.activeOnly ? 1 : undefined,
                    })
                  : await inventoryApi.warehouses({
                        ...params,
                        is_active: props.activeOnly ? 1 : undefined,
                    });
        if (current !== sequence) return;
        options.value = response.data.map((item) => ({
            id: item.id,
            label: `${item.name} · ${'sku' in item ? item.sku : 'code' in item ? item.code : 'Поставщик'}`,
        }));
        lastPage.value = response.meta.last_page;
    } catch (e) {
        if (current === sequence) error.value = message(e);
    } finally {
        if (current === sequence) loading.value = false;
    }
}
function select(event: Event) {
    const value = Number((event.target as HTMLSelectElement).value);
    model.value = value || null;
    chosen.value = options.value.find((o) => o.id === value) ?? chosen.value;
}
watch(
    () => props.selectedOption,
    (value) => {
        if (value && value.id === model.value) chosen.value = value;
    },
);
watch(search, () => {
    page.value = 1;
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});
watch(page, load);
watch(() => props.kind, load, { immediate: true });
onBeforeUnmount(() => {
    sequence++;
    clearTimeout(timer);
});
</script>
<template>
    <div class="resource-picker">
        <label
            >{{ label
            }}<input
                v-model="search"
                :aria-label="`Поиск: ${label}`"
                placeholder="Поиск по названию или коду"
                maxlength="100"
            /><select
                v-if="!expanded"
                :value="model ?? ''"
                :aria-label="label"
                :disabled="loading"
                @change="select"
            >
                <option value="">{{ loading ? 'Загрузка…' : 'Выберите из списка' }}</option>
                <option v-for="option in visibleOptions" :key="option.id" :value="option.id">
                    {{ option.label }}
                </option>
            </select></label
        >
        <div
            v-if="expanded"
            class="picker-options"
            role="group"
            :aria-label="label"
            :aria-busy="loading"
        >
            <p v-if="loading">Загрузка товаров…</p>
            <template v-else>
                <button
                    v-for="option in visibleOptions"
                    :key="option.id"
                    type="button"
                    class="picker-option"
                    :class="{ chosen: model === option.id }"
                    :aria-pressed="model === option.id"
                    @click="
                        model = option.id;
                        chosen = option;
                    "
                >
                    {{ option.label }}
                </button>
                <p v-if="!visibleOptions.length">Товары не найдены</p>
            </template>
        </div>
        <div v-if="lastPage > 1" class="picker-pagination">
            <button
                type="button"
                class="text-link"
                :disabled="page <= 1 || loading"
                @click="page--"
            >
                ← Назад</button
            ><span>{{ page }} / {{ lastPage }}</span
            ><button
                type="button"
                class="text-link"
                :disabled="page >= lastPage || loading"
                @click="page++"
            >
                Далее →
            </button>
        </div>
        <p v-if="error" class="field-error">
            {{ error }} <button type="button" class="text-link" @click="load">Повторить</button>
        </p>
    </div>
</template>

<style scoped>
.picker-options {
    display: grid;
    gap: 6px;
    max-height: 320px;
    overflow: auto;
    padding: 8px 0;
}
.picker-option {
    text-align: left;
    white-space: normal;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: white;
    color: #24344d;
}
.picker-option.chosen {
    border-color: var(--primary);
    background: #eff6ff;
}
</style>
