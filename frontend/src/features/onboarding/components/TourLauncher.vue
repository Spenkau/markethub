<script setup lang="ts">
import { useProductTour } from '../composables/useProductTour';
defineProps<{ tourId: string }>();
const tour = useProductTour();
</script>
<template>
    <button type="button" class="text-link tour-help-link" @click="tour.start(tourId)">
        Как это работает?
    </button>
</template>
