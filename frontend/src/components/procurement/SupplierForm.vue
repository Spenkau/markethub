<script setup lang="ts">
import { reactive, ref } from 'vue';
import { procurementApi, type Supplier, type SupplierInput } from '../../api/procurement';
import { ApiError, message } from '../../api/client';
const props = defineProps<{ supplier?: Supplier }>();
const emit = defineEmits<{ close: []; saved: [supplier: Supplier] }>();
const form = reactive<SupplierInput>(
    props.supplier
        ? {
              name: props.supplier.name,
              contact_name: props.supplier.contact_name,
              phone: props.supplier.phone,
              email: props.supplier.email,
              inn: props.supplier.inn,
              comment: props.supplier.comment,
              is_active: props.supplier.is_active,
          }
        : {
              name: '',
              contact_name: '',
              phone: '',
              email: '',
              inn: '',
              comment: '',
              is_active: true,
          },
);
const busy = ref(false),
    error = ref(''),
    fields = ref<Record<string, string[]>>({});
async function save() {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    fields.value = {};
    try {
        const supplier = await procurementApi.saveSupplier({ ...form }, props.supplier?.id);
        emit('saved', supplier);
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
            class="modal inventory-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="supplier-title"
        >
            <h2 id="supplier-title">
                {{ supplier ? 'Редактировать поставщика' : 'Новый поставщик' }}
            </h2>
            <form @submit.prevent="save">
                <p v-if="error" class="alert" role="alert">{{ error }}</p>
                <ul v-if="Object.keys(fields).length" class="validation-list">
                    <li v-for="(errors, field) in fields" :key="field">{{ errors.join(' ') }}</li>
                </ul>
                <fieldset :disabled="busy" data-tour="supplier-fields">
                    <label
                        >Название поставщика<input
                            v-model="form.name"
                            required
                            maxlength="255"
                            autofocus
                    /></label>
                    <div class="form-grid">
                        <label
                            >Контактное лицо<input
                                v-model="form.contact_name"
                                maxlength="255" /></label
                        ><label
                            >Телефон<input v-model="form.phone" maxlength="50" type="tel"
                        /></label>
                    </div>
                    <div class="form-grid">
                        <label
                            >Email<input v-model="form.email" type="email" maxlength="255" /></label
                        ><label>ИНН<input v-model="form.inn" maxlength="30" /></label>
                    </div>
                    <label
                        >Комментарий<textarea
                            v-model="form.comment"
                            maxlength="5000"
                            rows="3"
                        /></label
                    ><label class="checkbox-label"
                        ><input v-model="form.is_active" type="checkbox" />Поставщик активен</label
                    >
                </fieldset>
                <div class="form-actions">
                    <button
                        type="button"
                        class="secondary"
                        :disabled="busy"
                        data-tour="close-supplier-form"
                        @click="emit('close')"
                    >
                        Отмена</button
                    ><button class="primary" :disabled="busy">
                        {{ busy ? 'Сохраняем…' : 'Сохранить поставщика' }}
                    </button>
                </div>
            </form>
        </section>
    </div>
</template>
