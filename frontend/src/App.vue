<script setup lang="ts">
import { ref } from 'vue';
import { router } from './router';
import { message } from './api/client';
const error = ref('');
router.onError((e) => {
    error.value = message(e);
});
</script>
<template>
    <div v-if="error" class="fatal" role="alert">
        <h1>Не удалось открыть страницу</h1>
        <p>{{ error }}</p>
        <button
            @click="
                error = '';
                router.go(0);
            "
        >
            Повторить
        </button>
    </div>
    <RouterView v-else />
</template>
