<script setup lang="ts">
import type { Page } from '../../types';
defineProps<{ meta: Page<unknown>['meta']; disabled?: boolean }>();
defineEmits<{ change: [page: number] }>();
</script>
<template>
    <div class="pagination">
        <span
            >Всего: {{ meta.total }} · Страница {{ meta.current_page }} из
            {{ meta.last_page }}</span
        >
        <div class="flex gap-2">
            <button
                class="secondary"
                :disabled="disabled || meta.current_page <= 1"
                aria-label="Предыдущая страница"
                @click="$emit('change', meta.current_page - 1)"
            >
                ←</button
            ><button
                class="secondary"
                :disabled="disabled || meta.current_page >= meta.last_page"
                aria-label="Следующая страница"
                @click="$emit('change', meta.current_page + 1)"
            >
                →
            </button>
        </div>
    </div>
</template>
