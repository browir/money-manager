<script setup>
import { useMediaQuery } from '@vueuse/core';
import { X } from 'lucide-vue-next';
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: '' },
});
const emit = defineEmits(['close', 'closed']);

const desktop = useMediaQuery('(min-width: 768px)');
const panel = ref(null);
const dragY = ref(0);
const dragging = ref(false);
let start = null;

function close() {
    emit('close');
}

function onKey(e) {
    if (e.key === 'Escape' && props.open) {
        e.stopPropagation();
        close();
    }
}

watch(
    () => props.open,
    async (open) => {
        document.documentElement.style.overflow = open ? 'hidden' : '';
        if (open) {
            window.addEventListener('keydown', onKey);
            dragY.value = 0;
            await nextTick();
            // Di HP jangan fokus otomatis ke input: keyboard akan menutupi sheet.
            const target = desktop.value ? panel.value?.querySelector('[data-autofocus]') : null;
            (target ?? panel.value)?.focus({ preventScroll: true });
        } else {
            window.removeEventListener('keydown', onKey);
        }
    },
);

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    document.documentElement.style.overflow = '';
});

/* Tarik ke bawah untuk menutup (HP). */
function startDrag(e) {
    if (desktop.value) return;
    start = { y: e.clientY, t: performance.now() };
    dragging.value = true;
    window.addEventListener('pointermove', moveDrag);
    window.addEventListener('pointerup', endDrag, { once: true });
    window.addEventListener('pointercancel', endDrag, { once: true });
}

function moveDrag(e) {
    const dy = e.clientY - start.y;
    // Tarikan ke atas diberi hambatan supaya terasa "mentok".
    dragY.value = dy > 0 ? dy : dy / 6;
}

function endDrag(e) {
    window.removeEventListener('pointermove', moveDrag);
    dragging.value = false;
    const velocity = (e.clientY - start.y) / (performance.now() - start.t);
    if (dragY.value > 120 || (dragY.value > 30 && velocity > 0.5)) {
        close();
    } else {
        dragY.value = 0;
    }
}
</script>

<template>
    <Teleport to="body">
        <Transition name="scrim">
            <div v-if="open" class="fixed inset-0 z-40 bg-scrim" @click="close" />
        </Transition>

        <Transition :name="desktop ? 'panel' : 'sheet'" @after-leave="emit('closed')">
            <div
                v-if="open"
                ref="panel"
                data-modal-open
                role="dialog"
                aria-modal="true"
                :aria-label="title"
                tabindex="-1"
                class="fixed inset-x-0 bottom-0 z-50 flex max-h-[94dvh] flex-col rounded-t-[22px] bg-surface shadow-float outline-none md:inset-y-3 md:right-3 md:left-auto md:max-h-none md:w-[440px] md:rounded-[20px]"
                :style="
                    dragY
                        ? { transform: `translateY(${dragY}px)`, transition: dragging ? 'none' : undefined }
                        : undefined
                "
            >
                <div class="flex shrink-0 touch-none justify-center pt-2.5 pb-1 md:hidden" @pointerdown="startDrag">
                    <span class="h-1 w-9 rounded-full bg-line-strong" />
                </div>

                <header
                    v-if="title || $slots.header"
                    class="flex shrink-0 touch-none items-center justify-between gap-3 px-5 pt-1.5 pb-3 md:touch-auto md:pt-5"
                    @pointerdown="startDrag"
                >
                    <slot name="header">
                        <h2 class="text-[17px] font-semibold tracking-tight">{{ title }}</h2>
                    </slot>
                    <button type="button" class="icon-btn -mr-2 hidden md:inline-flex" aria-label="Tutup" @click="close">
                        <X class="size-[18px]" />
                    </button>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5">
                    <slot />
                </div>

                <footer v-if="$slots.footer" class="shrink-0 px-5 pt-3 pb-[max(1rem,env(safe-area-inset-bottom))]">
                    <slot name="footer" />
                </footer>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.scrim-enter-active,
.scrim-leave-active {
    transition: opacity 0.3s ease;
}
.scrim-enter-from,
.scrim-leave-to {
    opacity: 0;
}

.sheet-enter-active,
.panel-enter-active,
[role='dialog'] {
    transition: transform 0.44s var(--ease-sheet);
}
.sheet-leave-active,
.panel-leave-active {
    transition: transform 0.26s cubic-bezier(0.4, 0, 1, 1);
}
.sheet-enter-from,
.sheet-leave-to {
    transform: translateY(100%);
}
.panel-enter-from,
.panel-leave-to {
    transform: translateX(calc(100% + 24px));
}
</style>
