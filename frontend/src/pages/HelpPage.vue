<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { tours, tourStatusNames } from '../features/onboarding/config/tours';
import { useProductTour } from '../features/onboarding/composables/useProductTour';
import { message } from '../api/client';
const tour = useProductTour(),
    error = ref(''),
    changing = ref(false);
async function load() {
    error.value = '';
    try {
        await tour.load(true);
    } catch (e) {
        error.value = message(e);
    }
}
onMounted(load);
async function setting(event: Event) {
    changing.value = true;
    error.value = '';
    try {
        await tour.setPreferences({ auto_start: (event.target as HTMLInputElement).checked });
    } catch (e) {
        error.value = message(e);
    } finally {
        changing.value = false;
    }
}
</script>
<template>
    <div class="page-heading">
        <div>
            <p class="eyebrow">ВАШ ПОМОЩНИК</p>
            <h1>Помощь и обучение</h1>
            <p>
                Короткие экскурсии по реальным разделам. Обучение не создаёт записи и не заменяет
                работу с документами.
            </p>
        </div>
    </div>
    <p v-if="error" class="alert" role="alert">
        {{ error }} <button class="text-link" @click="load">Повторить</button>
    </p>
    <section class="panel section-card">
        <label class="checkbox-label"
            ><input
                type="checkbox"
                :checked="tour.preferences.auto_start"
                :disabled="changing || !tour.loaded"
                @change="setting"
            />Автоматически предлагать подсказки</label
        >
        <p class="fine-print">
            Настройка и прогресс сохраняются для вашей учётной записи на всех устройствах.
            Отключение не мешает запускать экскурсии вручную.
        </p>
    </section>
    <div class="tour-catalog">
        <article
            v-for="definition in tours"
            :key="definition.id"
            class="panel section-card"
            :data-testid="`tour-${definition.id}`"
        >
            <div class="section-heading">
                <h2>{{ definition.title }}</h2>
                <span
                    class="badge"
                    :class="tour.progress(definition)?.status === 'completed' ? 'success' : ''"
                    >{{ tourStatusNames[tour.progress(definition)?.status ?? 'not_started'] }}</span
                >
            </div>
            <p>{{ definition.description }}</p>
            <p class="fine-print">
                {{ definition.steps.length }} шагов · Версия {{ definition.version
                }}<span
                    v-if="
                        tour.progress(definition)?.status === 'snoozed' &&
                        tour.progress(definition)?.next_eligible_at
                    "
                >
                    · Повторный показ возможен после
                    {{
                        new Date(tour.progress(definition)!.next_eligible_at!).toLocaleString(
                            'ru-RU',
                        )
                    }}</span
                >
            </p>
            <div class="heading-actions">
                <button
                    class="primary"
                    :disabled="tour.busy || !tour.loaded"
                    @click="tour.start(definition.id)"
                >
                    {{
                        tour.progress(definition)?.status === 'in_progress'
                            ? 'Продолжить'
                            : 'Начать'
                    }}</button
                ><button
                    v-if="tour.progress(definition)"
                    class="secondary"
                    :disabled="tour.busy || !tour.loaded"
                    @click="tour.start(definition.id, true)"
                >
                    Пройти заново
                </button>
            </div>
        </article>
    </div>
</template>
