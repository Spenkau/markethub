<script setup lang="ts">
import { ref, watch } from 'vue';
import type { Image } from '../../api/catalog';
const props = defineProps<{ images: Image[]; thumbnail?: boolean; title: string }>();
const selected = ref(0),
    zoom = ref(false);
watch(
    () => props.images,
    () => {
        selected.value = 0;
        zoom.value = false;
    },
);
</script>
<template>
    <div class="product-gallery" :class="{ compact: thumbnail }" data-tour="product-gallery">
        <button
            v-if="images.length"
            class="gallery-main"
            :aria-label="`Увеличить изображение: ${title}`"
            @click="zoom = true"
        >
            <img
                :src="images[selected]?.url"
                :alt="images[selected]?.alt || title"
                loading="lazy"
            />
        </button>
        <div v-else class="gallery-placeholder" aria-label="Нет изображения">Нет фото</div>
        <div v-if="!thumbnail && images.length > 1" class="gallery-thumbs">
            <button
                v-for="(image, index) in images"
                :key="image.url"
                :aria-label="`Фото ${index + 1}`"
                :aria-pressed="selected === index"
                @click="selected = index"
            >
                <img :src="image.url" :alt="image.alt || title" loading="lazy" />
            </button>
        </div>
        <div
            v-if="zoom"
            class="modal-backdrop"
            @click.self="zoom = false"
            @keydown.esc="zoom = false"
        >
            <section
                class="modal gallery-zoom"
                role="dialog"
                aria-modal="true"
                aria-label="Просмотр изображения"
            >
                <button class="secondary" autofocus @click="zoom = false">
                    Закрыть изображение</button
                ><img :src="images[selected]?.url" :alt="title" />
                <div class="form-actions">
                    <button class="secondary" :disabled="selected === 0" @click="selected--">
                        Назад</button
                    ><span>{{ selected + 1 }} / {{ images.length }}</span
                    ><button
                        class="secondary"
                        :disabled="selected === images.length - 1"
                        @click="selected++"
                    >
                        Далее
                    </button>
                </div>
            </section>
        </div>
    </div>
</template>
<style scoped>
.gallery-main,
.gallery-placeholder {
    width: 100%;
    min-height: 260px;
    border: 1px solid var(--border, #e0e5ee);
    border-radius: 12px;
    background: #f8fafc;
    display: grid;
    place-items: center;
    overflow: hidden;
}
.gallery-main img {
    width: 100%;
    height: 320px;
    object-fit: contain;
}
.compact .gallery-main,
.compact .gallery-placeholder {
    width: 84px;
    min-height: 84px;
    height: 84px;
}
.compact .gallery-main img {
    width: 84px;
    height: 84px;
    object-fit: contain;
}
.gallery-placeholder {
    color: #728095;
    font-size: 12px;
}
.gallery-thumbs {
    display: flex;
    gap: 8px;
    overflow: auto;
    padding: 12px 0;
}
.gallery-thumbs img {
    width: 64px;
    height: 64px;
    object-fit: contain;
}
.gallery-thumbs button {
    background: white;
    border: 1px solid #dce3ed;
    border-radius: 8px;
}
.gallery-thumbs button[aria-pressed='true'] {
    border-color: #2563eb;
}
.gallery-zoom {
    max-width: 900px;
    width: min(94vw, 900px);
    display: grid;
    gap: 12px;
}
.gallery-zoom > img {
    width: 100%;
    max-height: 70vh;
    object-fit: contain;
}
</style>
