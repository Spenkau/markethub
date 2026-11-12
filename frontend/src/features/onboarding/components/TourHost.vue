<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useAuth } from '../../../stores/auth';
import { pendingMutations, message } from '../../../api/client';
import { useProductTour, pageIsBusy } from '../composables/useProductTour';
import TourOverlay from './TourOverlay.vue';
const route = useRoute(),
    auth = useAuth(),
    tour = useProductTour();
const safe = ref(true);
let timer: ReturnType<typeof setTimeout>, observer: MutationObserver;
function schedule() {
    safe.value = !pageIsBusy();
    clearTimeout(timer);
    timer = setTimeout(() => {
        void tour.offerForRoute();
    }, 800);
}
function escape(event: KeyboardEvent) {
    if (tour.active && event.key === 'Escape') {
        event.preventDefault();
        event.stopImmediatePropagation();
        void tour.finish('skip');
    }
}
function refresh() {
    if (document.visibilityState === 'visible')
        void tour
            .load(true)
            .then(schedule)
            .catch(() => undefined);
}
watch(
    () => route.fullPath,
    () => {
        if (tour.offered) tour.dismissOffer();
        if (tour.active && !tour.preparing) {
            tour.clear();
            tour.notice =
                'Экскурсия приостановлена при переходе. Её можно продолжить в центре обучения.';
        }
        schedule();
    },
);
watch(
    () => auth.user?.active_organization_id,
    () => {
        if (tour.active) tour.clear();
        schedule();
    },
);
watch(pendingMutations, schedule);
onMounted(() => {
    document.addEventListener('keydown', escape, true);
    document.addEventListener('focusin', schedule);
    document.addEventListener('focusout', schedule);
    document.addEventListener('visibilitychange', refresh);
    observer = new MutationObserver(schedule);
    observer.observe(document.getElementById('app')!, { childList: true, subtree: true });
    void tour
        .load()
        .then(schedule)
        .catch(() => undefined);
});
onBeforeUnmount(() => {
    clearTimeout(timer);
    observer?.disconnect();
    document.removeEventListener('keydown', escape, true);
    document.removeEventListener('focusin', schedule);
    document.removeEventListener('focusout', schedule);
    document.removeEventListener('visibilitychange', refresh);
    tour.clear();
});
async function disable() {
    try {
        await tour.setPreferences({ auto_start: false });
    } catch (e) {
        tour.notice = message(e);
    }
}
</script>
<template>
    <TourOverlay v-if="tour.active" />
    <aside
        v-if="tour.offered && !tour.active && safe"
        class="tour-offer"
        aria-label="Предложение обучения"
    >
        <strong>{{ tour.offered.title }}</strong>
        <p>{{ tour.offered.description }}</p>
        <div>
            <button
                type="button"
                class="primary"
                :disabled="tour.busy"
                @click="tour.start(tour.offered.id)"
            >
                Начать экскурсию</button
            ><button type="button" class="text-link" @click="tour.finish('snooze')">
                Напомнить позже
            </button>
        </div>
        <button type="button" class="tour-disable text-link" @click="disable">
            Отключить автоматические подсказки
        </button>
    </aside>
    <div v-if="tour.notice" class="tour-notice" role="status">
        {{ tour.notice }}
        <button type="button" class="text-link" @click="tour.notice = ''">Закрыть</button>
    </div>
</template>
