<script setup lang="ts">
import { ref, watch, nextTick, onBeforeUnmount } from 'vue';
import type { Image } from '../../api/catalog';
const props = defineProps<{ images: Image[]; thumbnail?: boolean; title: string }>();
const selected = ref(0),
    zoom = ref(false),
    failed = ref(new Set<string>());
const opener = ref<HTMLButtonElement>(),
    dialog = ref<HTMLElement>(),
    closeButton = ref<HTMLButtonElement>();
function fail(url?: string) {
    if (url) failed.value.add(url);
}
async function open() {
    zoom.value = true;
    document.addEventListener('keydown', keydown, true);
    await nextTick();
    closeButton.value?.focus();
}
function close() {
    zoom.value = false;
    document.removeEventListener('keydown', keydown, true);
    opener.value?.focus();
}
function keydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        close();
    }
    if (event.key === 'ArrowRight') {
        event.preventDefault();
        selected.value = Math.min(props.images.length - 1, selected.value + 1);
    }
    if (event.key === 'ArrowLeft') {
        event.preventDefault();
        selected.value = Math.max(0, selected.value - 1);
    }
    if (event.key === 'Tab') {
        const buttons = Array.from(
            dialog.value?.querySelectorAll<HTMLButtonElement>('button:not(:disabled)') || [],
        );
        const first = buttons[0],
            last = buttons[buttons.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last?.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first?.focus();
        }
    }
}
watch(
    () => props.images,
    () => {
        if (zoom.value) close();
        selected.value = 0;
        failed.value = new Set();
    },
);
onBeforeUnmount(() => document.removeEventListener('keydown', keydown, true));
</script>
<template>
    <div class="product-gallery" :class="{ compact: thumbnail }" data-tour="product-gallery">
        <button
            v-if="images.length"
            ref="opener"
            class="gallery-main"
            :aria-label="`Увеличить изображение: ${title}`"
            @click="open"
        >
            <img
                v-if="images[selected] && !failed.has(images[selected]!.url)"
                :src="images[selected]?.url"
                :alt="images[selected]?.alt || title"
                loading="lazy"
                @error="fail(images[selected]?.url)"
            />
            <span v-else class="gallery-placeholder" role="img" aria-label="Изображение недоступно"
                >Фото недоступно</span
            >
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
                <img
                    v-if="!failed.has(image.url)"
                    :src="image.url"
                    :alt="image.alt || title"
                    loading="lazy"
                    @error="fail(image.url)"
                />
                <span v-else role="img" aria-label="Изображение недоступно">Нет фото</span>
            </button>
        </div>
        <Teleport to="body">
            <div v-if="zoom" class="modal-backdrop" @click.self="close">
                <section
                    ref="dialog"
                    class="modal gallery-zoom"
                    role="dialog"
                    aria-modal="true"
                    aria-label="Просмотр изображения"
                >
                    <button ref="closeButton" class="secondary" @click="close">
                        Закрыть изображение
                    </button>
                    <img
                        v-if="images[selected] && !failed.has(images[selected]!.url)"
                        :src="images[selected]?.url"
                        :alt="images[selected]?.alt || title"
                        @error="fail(images[selected]?.url)"
                    />
                    <div
                        v-else
                        class="gallery-placeholder"
                        role="img"
                        aria-label="Изображение недоступно"
                    >
                        Фото недоступно
                    </div>
                    <div class="form-actions">
                        <button class="secondary" :disabled="selected === 0" @click="selected--">
                            Назад
                        </button>
                        <span>{{ selected + 1 }} / {{ images.length }}</span>
                        <button
                            class="secondary"
                            :disabled="selected === images.length - 1"
                            @click="selected++"
                        >
                            Далее
                        </button>
                    </div>
                </section>
            </div>
        </Teleport>
    </div>
</template>
<style scoped>
.gallery-main,
.gallery-placeholder {
    width: 100%;
    min-height: 260px;
    border: 1px solid var(--border, var(--border));
    border-radius: 12px;
    background: var(--muted);
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
    width: 56px;
    min-height: 56px;
    height: 56px;
}
.compact .gallery-main img {
    width: 56px;
    height: 56px;
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
    border: 1px solid var(--border);
    border-radius: 8px;
}
.gallery-thumbs button[aria-pressed='true'] {
    border-color: var(--primary);
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
