<script setup lang="ts">
import { nextTick, onMounted, ref, watch } from 'vue';
import { X } from 'lucide-vue-next';
import type { TourStep } from '../types';
const props = defineProps<{
    step: TourStep;
    index: number;
    total: number;
    disabled: boolean;
    error: string;
    fallback: string;
}>();
const emit = defineEmits<{ back: []; next: []; skip: []; complete: []; snooze: [] }>();
const panel = ref<HTMLElement>();
function trap(event: KeyboardEvent) {
    if (event.key !== 'Tab') return;
    const elements = Array.from(
        panel.value?.querySelectorAll<HTMLButtonElement>('button:not(:disabled)') ?? [],
    );
    const at = elements.indexOf(document.activeElement as HTMLButtonElement);
    if (event.shiftKey && at <= 0) {
        event.preventDefault();
        elements.at(-1)?.focus();
    } else if (!event.shiftKey && (at === -1 || at === elements.length - 1)) {
        event.preventDefault();
        elements[0]?.focus();
    }
}
async function focus() {
    await nextTick();
    panel.value
        ?.querySelector<HTMLButtonElement>('button[aria-label="Закрыть экскурсию"]')
        ?.focus();
}
onMounted(focus);
watch(() => props.index, focus);
defineExpose({ panel });
</script>
<template>
    <section
        ref="panel"
        class="tour-tooltip"
        role="dialog"
        aria-modal="true"
        aria-labelledby="tour-title"
        aria-describedby="tour-description"
        @keydown="trap"
    >
        <div class="tour-tooltip-top">
            <span>Шаг {{ index + 1 }} из {{ total }}</span
            ><button
                type="button"
                class="icon-button"
                aria-label="Закрыть экскурсию"
                @click="emit('skip')"
            >
                <X :size="18" />
            </button>
        </div>
        <h2 id="tour-title">{{ step.title }}</h2>
        <p id="tour-description">{{ step.description }}</p>
        <p v-if="fallback" class="tour-fallback" role="status">{{ fallback }}</p>
        <p v-if="error" class="alert" role="alert">{{ error }}</p>
        <div class="tour-step-progress" aria-hidden="true">
            <span :style="{ width: `${((index + 1) / total) * 100}%` }"></span>
        </div>
        <div class="tour-buttons">
            <button
                type="button"
                class="secondary"
                :disabled="disabled || index === 0"
                @click="emit('back')"
            >
                Назад</button
            ><button
                v-if="index < total - 1"
                type="button"
                class="primary"
                :disabled="disabled"
                @click="emit('next')"
            >
                Далее</button
            ><button
                v-else
                type="button"
                class="primary"
                :disabled="disabled"
                @click="emit('complete')"
            >
                Завершить
            </button>
        </div>
        <div class="tour-secondary-actions">
            <button type="button" class="text-link" @click="emit('skip')">Пропустить</button
            ><button type="button" class="text-link" @click="emit('snooze')">
                Напомнить позже
            </button>
        </div>
    </section>
</template>
