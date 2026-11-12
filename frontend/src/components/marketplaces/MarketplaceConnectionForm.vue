<script setup lang="ts">
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { connectionsApi, type Provider, type Connection } from '../../api/connections';
import { ApiError, message } from '../../api/client';
import FieldError from '../ui/FieldError.vue';
const probeNotice = ref('');
const props = defineProps<{ account?: Connection; initialProvider?: string }>();
const emit = defineEmits<{ saved: [account: Connection]; close: [] }>();
const providers = ref<Provider[]>([]),
    provider = ref(props.account?.marketplace || props.initialProvider || 'ozon'),
    name = ref(props.account?.name || ''),
    status = ref(props.account?.status || 'demo'),
    configuration = reactive<Record<string, string>>({ ...props.account?.configuration }),
    credentials = reactive<Record<string, string>>({}),
    busy = ref(false),
    error = ref(''),
    fields = ref<Record<string, string[]>>({});
const selected = computed(() => providers.value.find((p) => p.code === provider.value));
onMounted(async () => {
    try {
        providers.value = await connectionsApi.providers();
    } catch (e) {
        error.value = message(e);
    }
});
watch(
    [name, provider, () => JSON.stringify(configuration), () => JSON.stringify(credentials)],
    () => {
        probeNotice.value = '';
    },
);
async function probe() {
    busy.value = true;
    error.value = '';
    probeNotice.value = '';
    try {
        const result = await connectionsApi.probe({
            name: name.value,
            marketplace: provider.value,
            configuration: { ...configuration },
            credentials: { ...credentials },
        });
        probeNotice.value = result.missing_scopes.length
            ? `Недостаточно прав: ${result.missing_scopes.join(', ')}`
            : 'API проверен. Можно сохранить подключение.';
    } catch (e) {
        error.value = message(e);
        if (e instanceof ApiError) fields.value = e.fields;
    } finally {
        busy.value = false;
    }
}

async function save() {
    busy.value = true;
    error.value = '';
    fields.value = {};
    try {
        const data: Record<string, unknown> = { name: name.value };
        if (props.account) data.status = status.value;
        else data.marketplace = provider.value;
        if (selected.value?.connection_type !== 'demo') {
            data.configuration = { ...configuration };
            if (Object.values(credentials).some(Boolean)) data.credentials = { ...credentials };
        }
        const account = await connectionsApi.save(data, props.account?.id);
        emit('saved', account);
    } catch (e) {
        error.value = message(e);
        if (e instanceof ApiError) fields.value = e.fields;
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <form class="connection-form" data-tour="connection-form" @submit.prevent="save">
        <h2>{{ account ? 'Изменить подключение' : 'Новое подключение' }}</h2>
        <p v-if="error" class="alert" role="alert">{{ error }}</p>
        <label
            >Название<input
                v-model="name"
                :disabled="busy"
                required
                maxlength="255"
                autofocus /><FieldError :errors="fields.name" /></label
        ><label
            >Маркетплейс<select
                v-model="provider"
                :disabled="!!account || busy"
                aria-label="Маркетплейс"
            >
                <option v-for="p in providers" :key="p.code" :value="p.code">
                    {{ p.name }}
                </option></select
            ><FieldError :errors="fields.marketplace"
        /></label>
        <p v-if="selected?.connection_type === 'demo'" class="info-banner">
            Демонстрационный провайдер. Реального обмена с API нет.
        </p>
        <template v-else
            ><label v-for="f in selected?.configuration_schema" :key="f.key"
                >{{ f.key === 'shop_domain' ? 'Домен магазина' : f.key
                }}<input
                    v-model="configuration[f.key]"
                    :disabled="busy"
                    :required="f.required"
                    :readonly="!!account" /><FieldError
                    :errors="fields[`configuration.${f.key}`]" /></label
            ><label v-for="f in selected?.credentials_schema" :key="f.key"
                >{{
                    f.key === 'client_id'
                        ? 'Client ID'
                        : f.key === 'client_secret'
                          ? 'Client Secret'
                          : f.key
                }}<input
                    v-model="credentials[f.key]"
                    :disabled="busy"
                    :type="f.type === 'password' ? 'password' : 'text'"
                    :required="!account && f.required"
                    autocomplete="new-password" /><FieldError
                    :errors="fields[`credentials.${f.key}`]"
            /></label>
            <p class="fine-print">
                {{ selected?.authorization_notice }} После сохранения проверьте API и подтвердите
                первый импорт.
            </p></template
        ><label v-if="account"
            >Статус<select v-model="status" :disabled="busy">
                <option v-if="account.is_demo" value="demo">Демо</option>
                <option value="active">Активно</option>
                <option value="disabled">Отключено</option>
            </select></label
        >
        <p v-if="probeNotice" role="status" class="info-banner">{{ probeNotice }}</p>
        <div class="form-actions">
            <button
                v-if="!account && selected?.capabilities.includes('check_connection')"
                type="button"
                class="secondary"
                :disabled="busy"
                @click="probe"
            >
                Проверить подключение</button
            ><button type="button" class="secondary" :disabled="busy" @click="emit('close')">
                Отмена</button
            ><button class="primary" :disabled="busy || !selected">
                {{ busy ? 'Сохраняем…' : 'Сохранить' }}
            </button>
        </div>
    </form>
</template>
<style scoped>
.connection-form {
    display: grid;
    gap: 18px;
    width: 100%;
    max-width: 620px;
}
.connection-form label {
    display: grid;
    gap: 7px;
    margin: 0;
}
.connection-form .form-actions {
    margin-top: 4px;
}
</style>
