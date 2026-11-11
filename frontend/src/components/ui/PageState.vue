<script setup lang="ts">
defineProps<{ loading?: boolean; error?: string; empty?: boolean }>();
defineEmits<{ retry: [] }>();
</script>
<template>
    <div v-if="loading" class="state" role="status">
        <span class="spinner"></span>Загружаем данные…
    </div>
    <div v-else-if="error" class="state" role="alert">
        <p>{{ error }}</p>
        <button class="secondary" @click="$emit('retry')">Повторить</button>
    </div>
    <div v-else-if="empty" class="state">
        <h3>Пока ничего нет</h3>
        <p>Добавьте запись или измените условия поиска.</p>
    </div>
    <slot v-else />
</template>
