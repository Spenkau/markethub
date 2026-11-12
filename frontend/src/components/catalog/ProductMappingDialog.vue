<script setup lang="ts">
import { ref } from 'vue';
import ResourcePicker from '../inventory/ResourcePicker.vue';
import FieldError from '../ui/FieldError.vue';
import { catalogApi, type Source } from '../../api/catalog';
import { ApiError, message } from '../../api/client';
const props = defineProps<{ source: Source }>();
const emit = defineEmits<{ close: []; saved: [text: string] }>();
const product = ref<number | null>(props.source.product_id),
    mode = ref('map'),
    sku = ref(props.source.sku || ''),
    name = ref(props.source.title || props.source.variant_title || ''),
    busy = ref(false),
    error = ref(''),
    fields = ref<Record<string, string[]>>({});
async function save() {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    fields.value = {};
    try {
        if (mode.value === 'create')
            await catalogApi.createLocal(props.source.id, sku.value, name.value);
        else await catalogApi.map(props.source.id, product.value);
        emit(
            'saved',
            mode.value === 'create'
                ? 'Товар MarketHub создан и сопоставлен.'
                : 'Сопоставление сохранено.',
        );
    } catch (e) {
        error.value = message(e);
        if (e instanceof ApiError) fields.value = e.fields;
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <div class="modal-backdrop" @keydown.esc="!busy && emit('close')">
        <section
            class="modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="mapping-title"
            data-tour="product-mapping"
        >
            <h2 id="mapping-title">Сопоставить с MarketHub</h2>
            <p>
                {{ source.variant_title || source.title }} · {{ source.sku || source.external_id }}
            </p>
            <form @submit.prevent="save">
                <label
                    >Действие<select v-model="mode" aria-label="Действие">
                        <option value="map">Выбрать локальную SKU</option>
                        <option v-if="!source.product_id" value="create">
                            Создать товар MarketHub
                        </option>
                    </select></label
                ><template v-if="mode === 'map'"
                    ><ResourcePicker
                        v-model="product"
                        kind="products"
                        :selected-option="
                            source.local_product
                                ? {
                                      id: source.local_product.id,
                                      label: `${source.local_product.name} · ${source.local_product.sku}`,
                                  }
                                : undefined
                        "
                        label="Товар MarketHub"
                        expanded
                    />
                    <p>
                        Выберите конкретную SKU. Совпадение названий не создаёт связь автоматически.
                    </p></template
                ><template v-else
                    ><label
                        >SKU нового товара<input
                            v-model="sku"
                            required
                            maxlength="100" /><FieldError :errors="fields.sku" /></label
                    ><label
                        >Название<input v-model="name" required maxlength="255" /><FieldError
                            :errors="fields.name"
                    /></label>
                    <p>
                        Будет создана локальная единица учёта. Физический остаток останется прежним.
                    </p></template
                >
                <p v-if="error" class="alert" role="alert">{{ error }}</p>
                <div class="form-actions">
                    <button type="button" class="secondary" :disabled="busy" @click="emit('close')">
                        Отмена</button
                    ><button class="primary" :disabled="busy || (mode === 'map' && !product)">
                        {{
                            busy
                                ? 'Сохраняем…'
                                : mode === 'create'
                                  ? 'Создать и сопоставить'
                                  : 'Сопоставить'
                        }}
                    </button>
                </div>
            </form>
        </section>
    </div>
</template>
