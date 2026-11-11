<script setup lang="ts">
defineProps<{ errors?: string[] }>();
</script>
<template>
    <span v-for="error in errors" :key="error" class="field-error" role="alert">{{ error }}</span>
</template>
