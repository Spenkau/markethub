<script setup lang="ts">
import { PackageOpen, TriangleAlert } from 'lucide-vue-next';
defineProps<{ loading?: boolean; error?: string; empty?: boolean }>();
defineEmits<{ retry: [] }>();
</script>
<template>
    <div
        v-if="loading"
        class="state loading-state"
        role="status"
        aria-live="polite"
        aria-busy="true"
    >
        <div class="skeleton-content" aria-hidden="true">
            <span></span><span></span><span></span>
        </div>
        <span class="loading-caption"><span class="spinner"></span>Загружаем данные…</span>
    </div>
    <div v-else-if="error" class="state" role="alert">
        <span class="state-icon state-error"><TriangleAlert :size="23" /></span>
        <h3>Не удалось загрузить данные</h3>
        <p>{{ error }}</p>
        <button class="secondary" @click="$emit('retry')">Повторить</button>
    </div>
    <div v-else-if="empty" class="state">
        <span class="state-icon"><PackageOpen :size="25" /></span>
        <h3>Пока ничего нет</h3>
        <p>По выбранным условиям записей нет. Измените фильтры или добавьте новую запись.</p>
        <slot name="emptyActions" />
    </div>
    <slot v-else />
</template>
