<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Check, ChevronDown } from 'lucide-vue-next';
import { client, message } from '../../../api/client';
import { useProductTour } from '../composables/useProductTour';
import OrganizationForm from './OrganizationForm.vue';
interface Result {
    organization_id: number | null;
    steps: { id: string; completed: boolean; can_act: boolean }[];
    completed: number;
    total: number;
}
const route = useRoute(),
    router = useRouter(),
    tour = useProductTour();
const data = ref<Result>(),
    loading = ref(true),
    error = ref(''),
    showOrganization = ref(false);
const definitions = [
    {
        id: 'organization',
        title: 'Создать организацию',
        description: 'Общее пространство данных для вашей команды.',
        to: '/app',
        action: 'Создать организацию',
    },
    {
        id: 'warehouse',
        title: 'Добавить первый склад',
        description: 'Определите, где будет учитываться физическое наличие.',
        to: '/app/stocks?tab=warehouses',
        action: 'Добавить склад',
    },
    {
        id: 'product',
        title: 'Создать первый товар',
        description: 'Добавьте карточку с названием и уникальным SKU.',
        to: '/app/products',
        action: 'К товарам',
    },
    {
        id: 'supplier',
        title: 'Добавить поставщика',
        description: 'Сохраните контакт для будущих заказов.',
        to: '/app/purchases?tab=suppliers',
        action: 'К поставщикам',
    },
    {
        id: 'purchase',
        title: 'Создать закупку',
        description: 'Выберите поставщика, склад и товары из каталога.',
        to: '/app/purchases',
        action: 'К закупкам',
    },
    {
        id: 'receipt',
        title: 'Принять первую поставку',
        description: 'Создайте и подтвердите приёмку заказанной закупки.',
        to: '/app/purchases',
        action: 'Выбрать закупку',
    },
];
const steps = computed(() =>
    definitions.map((d) => ({ ...d, ...data.value?.steps.find((s) => s.id === d.id) })),
);
async function load() {
    loading.value = true;
    error.value = '';
    try {
        const [result] = await Promise.all([
            client.get<{ data: Result }>('api/v1/onboarding/checklist'),
            tour.load(),
        ]);
        data.value = result.data.data;
    } catch (e) {
        error.value = message(e);
    } finally {
        loading.value = false;
    }
}
onMounted(load);
watch(
    () => route.query.create,
    (value) => {
        if (value === 'organization') showOrganization.value = true;
    },
    { immediate: true },
);
async function toggle() {
    try {
        await tour.setPreferences({ checklist_collapsed: !tour.preferences.checklist_collapsed });
    } catch (e) {
        error.value = message(e);
    }
}
async function organizationSaved() {
    showOrganization.value = false;
    await router.replace('/app');
    await load();
}
</script>
<template>
    <section class="panel section-card getting-started" data-tour="getting-started">
        <div class="section-heading">
            <div>
                <p class="eyebrow">БЕЗ ОБЯЗАТЕЛЬНОГО ПОРЯДКА</p>
                <h2>Начало работы</h2>
                <p>
                    {{
                        loading
                            ? 'Проверяем данные организации…'
                            : `${data?.completed ?? 0} из ${data?.total ?? 6} этапов выполнено`
                    }}
                </p>
            </div>
            <button
                class="icon-button"
                :aria-expanded="!tour.preferences.checklist_collapsed"
                aria-label="Свернуть или развернуть начало работы"
                @click="toggle"
            >
                <ChevronDown :size="18" />
            </button>
        </div>
        <p v-if="error" class="alert" role="alert">
            {{ error }} <button class="text-link" @click="load">Повторить</button>
        </p>
        <template v-if="data"
            ><progress
                class="checklist-progress"
                :value="data.completed"
                :max="data.total"
                :aria-label="`Выполнено ${data.completed} из ${data.total}`"
            ></progress>
            <ol v-if="!tour.preferences.checklist_collapsed" class="checklist-steps">
                <li v-for="step in steps" :key="step.id" :data-testid="`checklist-${step.id}`">
                    <span class="checklist-check" :class="{ done: step.completed }"
                        ><Check v-if="step.completed" :size="16" /><span v-else>{{
                            steps.indexOf(step) + 1
                        }}</span></span
                    >
                    <div>
                        <strong>{{ step.title }}</strong>
                        <p>{{ step.description }}</p>
                        <span class="fine-print">{{
                            step.completed ? 'Выполнено' : 'Ещё не выполнено'
                        }}</span>
                    </div>
                    <button
                        v-if="step.id === 'organization' && !step.completed"
                        class="secondary"
                        :disabled="!step.can_act"
                        @click="showOrganization = true"
                    >
                        Создать организацию</button
                    ><RouterLink v-else-if="step.can_act" :to="step.to" class="secondary">{{
                        step.completed ? 'Открыть раздел' : step.action
                    }}</RouterLink
                    ><span v-else class="fine-print">Сначала выберите организацию</span>
                </li>
            </ol>
            <p v-if="data.completed === data.total" class="fine-print">
                Основные шаги пройдены. Чек-лист можно свернуть; он останется доступен здесь.
            </p></template
        ><OrganizationForm
            v-if="showOrganization"
            @close="
                showOrganization = false;
                router.replace('/app');
            "
            @saved="organizationSaved"
        />
    </section>
</template>
