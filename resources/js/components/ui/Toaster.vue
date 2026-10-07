<script setup>
import { router } from '@inertiajs/vue3';
import { Undo2 } from 'lucide-vue-next';
import { dismissToast, useToasts } from '@/composables/useToast';

const toasts = useToasts();

function undo(toast) {
    dismissToast(toast.id);
    router.post(toast.undo, {}, { preserveScroll: true });
}
</script>

<template>
    <div
        class="pointer-events-none fixed inset-x-0 bottom-[calc(96px+env(safe-area-inset-bottom))] z-[60] flex flex-col items-center gap-2 px-4 md:right-6 md:bottom-6 md:left-auto md:items-end"
        aria-live="polite"
    >
        <TransitionGroup name="toast">
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="pointer-events-auto flex min-h-11 max-w-sm items-center gap-3 rounded-2xl bg-ink py-2 pr-2 pl-4 text-sm text-paper shadow-float"
                :class="!toast.undo && 'pr-4'"
            >
                <span
                    v-if="toast.tone === 'warn'"
                    class="size-1.5 shrink-0 rounded-full bg-[var(--c-sand)]"
                    aria-hidden="true"
                />
                <span class="py-1">{{ toast.message }}</span>
                <button
                    v-if="toast.undo"
                    type="button"
                    class="inline-flex h-8 items-center gap-1.5 rounded-xl px-3 font-medium text-paper/90 transition hover:bg-paper/10 active:scale-95"
                    @click="undo(toast)"
                >
                    <Undo2 class="size-3.5" />
                    Urungkan
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>

<style scoped>
.toast-enter-active {
    transition:
        opacity 0.3s var(--ease-out-soft),
        transform 0.4s var(--ease-sheet);
}
.toast-leave-active {
    transition:
        opacity 0.2s ease,
        transform 0.2s ease;
}
.toast-enter-from {
    opacity: 0;
    transform: translateY(14px) scale(0.96);
}
.toast-leave-to {
    opacity: 0;
    transform: scale(0.96);
}
.toast-move {
    transition: transform 0.3s var(--ease-out-soft);
}
</style>
