<script setup>
import { router } from '@inertiajs/vue3';
import { RefreshCw } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/*
 * Tarik ke bawah dari atas halaman untuk memuat ulang data.
 * Penting di PWA terpasang karena tidak ada tombol refresh browser.
 */
const THRESHOLD = 72;
const pull = ref(0);
const refreshing = ref(false);
let start = null;

function onStart(e) {
    if (refreshing.value || window.scrollY > 0 || e.touches.length > 1) return;
    if (e.target.closest?.('[data-modal-open], [data-no-pull]')) return;
    start = { x: e.touches[0].clientX, y: e.touches[0].clientY, locked: null, armed: false };
}

function onMove(e) {
    if (!start) return;
    const dx = e.touches[0].clientX - start.x;
    const dy = e.touches[0].clientY - start.y;

    // Kunci arah dulu agar geser kartu/baris ke samping tidak memicu refresh.
    if (start.locked === null && Math.hypot(dx, dy) > 8) start.locked = Math.abs(dy) > Math.abs(dx) ? 'y' : 'x';
    if (start.locked !== 'y' || dy <= 0 || window.scrollY > 0) {
        pull.value = 0;
        return;
    }

    if (e.cancelable) e.preventDefault();
    pull.value = Math.min(130, dy * 0.5);

    if (pull.value >= THRESHOLD && !start.armed) {
        start.armed = true;
        navigator.vibrate?.(8);
    } else if (pull.value < THRESHOLD) {
        start.armed = false;
    }
}

function onEnd() {
    if (!start) return;
    start = null;
    if (pull.value < THRESHOLD) {
        pull.value = 0;
        return;
    }
    refreshing.value = true;
    pull.value = 60;
    router.reload({
        onFinish: () =>
            setTimeout(() => {
                refreshing.value = false;
                pull.value = 0;
            }, 300),
    });
}

onMounted(() => {
    window.addEventListener('touchstart', onStart, { passive: true });
    window.addEventListener('touchmove', onMove, { passive: false });
    window.addEventListener('touchend', onEnd);
    window.addEventListener('touchcancel', onEnd);
});
onBeforeUnmount(() => {
    window.removeEventListener('touchstart', onStart);
    window.removeEventListener('touchmove', onMove);
    window.removeEventListener('touchend', onEnd);
    window.removeEventListener('touchcancel', onEnd);
});
</script>

<template>
    <div
        class="pointer-events-none fixed inset-x-0 top-0 z-40 flex justify-center md:hidden"
        :style="{
            transform: `translateY(${pull - 44}px)`,
            opacity: Math.min(1, pull / 40),
            transition: start ? 'none' : 'transform 0.35s var(--ease-sheet), opacity 0.25s ease',
        }"
        aria-hidden="true"
    >
        <span
            class="mt-[env(safe-area-inset-top)] grid size-10 place-items-center rounded-full border border-line bg-surface shadow-float"
            :class="pull >= THRESHOLD || refreshing ? 'text-accent-text' : 'text-muted'"
        >
            <RefreshCw
                class="size-[18px]"
                :class="refreshing && 'animate-spin'"
                :style="refreshing ? undefined : { transform: `rotate(${pull * 3}deg)` }"
                :stroke-width="2.2"
            />
        </span>
    </div>
</template>
