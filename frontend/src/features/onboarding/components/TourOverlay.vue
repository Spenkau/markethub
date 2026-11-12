<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useProductTour } from '../composables/useProductTour';
import { tooltipPosition, type Rect } from '../services/geometry';
import TourTooltip from './TourTooltip.vue';
const tour = useProductTour(),
    tooltip = ref<InstanceType<typeof TourTooltip>>();
const viewport = ref({ width: window.innerWidth, height: window.innerHeight }),
    rect = ref<Rect>(),
    height = ref(270);
const width = computed(() => Math.min(360, viewport.value.width - 24));
const position = computed(() =>
    tooltipPosition(rect.value, width.value, height.value, viewport.value, tour.step?.placement),
);
const fallback = computed(() =>
    tour.preparing
        ? 'Открываем страницу и ищем элемент…'
        : tour.missing
          ? 'Элемент сейчас недоступен: возможно, список пуст или действие не подходит статусу документа. Можно продолжить объяснение или закрыть экскурсию.'
          : rect.value && !position.value.highlight
            ? 'На этом размере экрана подсветка не помещается рядом с подсказкой. Объяснение доступно, экскурсию можно продолжить.'
            : '',
);
const path = computed(() => {
    const w = viewport.value.width,
        h = viewport.value.height;
    if (!rect.value || !position.value.highlight || tour.preparing) return `M0,0 H${w} V${h} H0 Z`;
    const r = rect.value,
        left = Math.max(0, r.left - 6),
        top = Math.max(0, r.top - 6),
        right = Math.min(w, r.right + 6),
        bottom = Math.min(h, r.bottom + 6);
    return `M0,0 H${w} V${h} H0 Z M${left},${top} V${bottom} H${right} V${top} Z`;
});
let observer: ResizeObserver | undefined,
    frame = 0;
const previousFocus = document.activeElement as HTMLElement | null;
function measure() {
    viewport.value = { width: window.innerWidth, height: window.innerHeight };
    const element = tour.target;
    rect.value =
        !tour.preparing && element?.isConnected && element.getBoundingClientRect().width
            ? (element.getBoundingClientRect().toJSON() as Rect)
            : undefined;
    height.value = tooltip.value?.panel?.getBoundingClientRect().height ?? 270;
}
function update() {
    cancelAnimationFrame(frame);
    frame = requestAnimationFrame(measure);
}
watch(
    () => [tour.target, tour.index, tour.preparing],
    async () => {
        await nextTick();
        observer?.disconnect();
        observer = new ResizeObserver(update);
        if (tour.target) observer.observe(tour.target);
        if (tooltip.value?.panel) observer.observe(tooltip.value.panel);
        measure();
    },
    { immediate: true },
);
onMounted(() => {
    window.addEventListener('resize', update);
    window.addEventListener('scroll', update, true);
    measure();
});
onBeforeUnmount(() => {
    observer?.disconnect();
    cancelAnimationFrame(frame);
    window.removeEventListener('resize', update);
    window.removeEventListener('scroll', update, true);
    if (previousFocus?.isConnected) previousFocus.focus();
    else document.querySelector<HTMLElement>('[data-tour="help-navigation"]')?.focus();
});
</script>
<template>
    <Teleport to="body"
        ><div v-if="tour.step && tour.active" class="tour-overlay" data-tour-engine>
            <svg
                class="tour-shade"
                :viewBox="`0 0 ${viewport.width} ${viewport.height}`"
                preserveAspectRatio="none"
                aria-hidden="true"
            >
                <path :d="path" fill-rule="evenodd" />
            </svg>
            <div
                v-if="rect && position.highlight && !tour.preparing"
                class="tour-highlight"
                :style="{
                    left: `${rect.left - 5}px`,
                    top: `${rect.top - 5}px`,
                    width: `${rect.width + 10}px`,
                    height: `${rect.height + 10}px`,
                }"
            ></div>
            <TourTooltip
                ref="tooltip"
                :style="{
                    left: `${position.left}px`,
                    top: `${position.top}px`,
                    width: `${width}px`,
                    maxHeight: `${viewport.height - 24}px`,
                }"
                :step="tour.step"
                :index="tour.index"
                :total="tour.active.steps.length"
                :disabled="tour.busy || tour.preparing"
                :error="tour.error"
                :fallback="fallback"
                @back="tour.move(-1)"
                @next="tour.move(1)"
                @complete="tour.finish('complete')"
                @skip="tour.finish('skip')"
                @snooze="tour.finish('snooze')"
            /></div
    ></Teleport>
</template>
