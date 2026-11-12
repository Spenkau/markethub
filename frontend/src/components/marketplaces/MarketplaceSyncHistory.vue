<script setup lang="ts">
import { ref, watch } from 'vue';
import { connectionsApi, syncStatus, syncScope } from '../../api/connections';
import type { Run } from '../../api/catalog';
import type { Page } from '../../types';
import { message } from '../../api/client';
import PageState from '../ui/PageState.vue';
import PaginationBar from '../ui/PaginationBar.vue';
const props = defineProps<{ accountId: number; revision?: number }>();
const data = ref<Page<Run>>(),
    page = ref(1),
    status = ref(''),
    loading = ref(true),
    error = ref('');
let seq = 0;
async function load() {
    const v = ++seq;
    loading.value = true;
    try {
        const result = await connectionsApi.history(props.accountId, {
            page: page.value,
            status: status.value || undefined,
        });
        if (v === seq) data.value = result;
    } catch (e) {
        error.value = message(e);
    } finally {
        if (v === seq) loading.value = false;
    }
}
watch(
    () => [props.accountId, props.revision],
    () => {
        page.value = 1;
        void load();
    },
    { immediate: true },
);
watch(status, () => {
    page.value = 1;
    void load();
});
</script>
<template>
    <section class="panel section-card" data-tour="connection-history">
        <h2>История синхронизации</h2>
        <label
            >Статус запуска<select v-model="status">
                <option value="">Все</option>
                <option value="completed">Завершён</option>
                <option value="partial">Частично</option>
                <option value="failed">Ошибка</option>
                <option value="queued">В очереди</option>
                <option value="running">Выполняется</option>
            </select></label
        ><PageState :loading="loading" :error="error" :empty="!data?.data.length" @retry="load"
            ><div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Запуск</th>
                            <th>Область</th>
                            <th>Результат</th>
                            <th>Обработано</th>
                            <th>Ошибка</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in data?.data" :key="r.id">
                            <td>
                                {{ new Date(r.created_at).toLocaleString('ru-RU')
                                }}<span class="table-subtitle">{{ r.id }}</span>
                            </td>
                            <td>
                                {{ syncScope(r.scope)
                                }}<span v-if="r.target_listing_id">
                                    · вариант #{{ r.target_listing_id }}</span
                                >
                            </td>
                            <td>{{ syncStatus(r.status) }}</td>
                            <td>{{ r.processed }}</td>
                            <td>
                                {{ r.error_message || '—' }}
                                <details v-if="r.attempt_errors?.length">
                                    <summary>Прошлые попытки</summary>
                                    <p v-for="(e, i) in r.attempt_errors" :key="i">
                                        {{ e.message }}
                                    </p>
                                </details>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div></PageState
        ><PaginationBar
            v-if="data"
            :meta="data.meta"
            @change="
                (v) => {
                    page = v;
                    load();
                }
            "
        />
    </section>
</template>
