<script setup lang="ts">
import { ref } from 'vue';
import { client, message } from '../../../api/client';
import { useAuth } from '../../../stores/auth';
import { authApi } from '../../../api/auth';
import { operationKey } from '../../../utils/operationKey';
const emit = defineEmits<{ close: []; saved: [] }>();
const auth = useAuth(),
    name = ref(''),
    busy = ref(false),
    error = ref('');
const requestId = operationKey();
async function save() {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        await client.post('api/v1/organizations', {
            name: name.value.trim(),
            request_id: requestId,
        });
        auth.user = await authApi.me();
        await auth.loadOrganizations();
        emit('saved');
    } catch (e) {
        error.value = message(e);
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <div class="modal-backdrop" @keydown.esc="!busy && emit('close')">
        <section class="modal" role="dialog" aria-modal="true" aria-labelledby="organization-title">
            <h2 id="organization-title">Новая организация</h2>
            <p>Товары, склады и документы будут доступны участникам этой организации.</p>
            <form @submit.prevent="save">
                <p v-if="error" class="alert" role="alert">{{ error }}</p>
                <label
                    >Название организации<input
                        v-model="name"
                        required
                        maxlength="255"
                        autofocus
                        :disabled="busy"
                /></label>
                <div class="form-actions">
                    <button type="button" class="secondary" :disabled="busy" @click="emit('close')">
                        Отмена</button
                    ><button class="primary" :disabled="busy">
                        {{ busy ? 'Создаём…' : 'Создать организацию' }}
                    </button>
                </div>
            </form>
        </section>
    </div>
</template>
