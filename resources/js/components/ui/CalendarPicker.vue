<script setup>
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { fromIso, monthLabel, shiftMonth, toIso, today } from '@/lib/dates';

/**
 * Kalender sebaris pengganti <input type="date">: picker bawaan HP tidak bisa
 * diandalkan di dalam sheet / PWA (terutama iOS). Senin sebagai awal pekan.
 */
const model = defineModel({ type: String, required: true }); // "YYYY-MM-DD"
const props = defineProps({
    max: { type: String, default: () => today() },
});
const emit = defineEmits(['picked']);

const WEEKDAYS = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

const view = ref(model.value.slice(0, 7)); // "YYYY-MM"
const direction = ref(1);
const todayIso = today();

const cells = computed(() => {
    const first = fromIso(view.value + '-01');
    const offset = (first.getDay() + 6) % 7; // Senin = 0
    const days = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate();
    const out = Array.from({ length: offset }, () => null);
    for (let d = 1; d <= days; d++) out.push(toIso(new Date(first.getFullYear(), first.getMonth(), d)));
    return out;
});

const canNext = computed(() => view.value < props.max.slice(0, 7));

function go(step) {
    if (step > 0 && !canNext.value) return;
    direction.value = step;
    view.value = shiftMonth(view.value, step);
}

let justSwiped = false;

function pick(iso) {
    // Klik yang menyusul gerakan geser bukan pilihan tanggal.
    if (justSwiped) {
        justSwiped = false;
        return;
    }
    if (iso > props.max) return;
    model.value = iso;
    navigator.vibrate?.(5);
    emit('picked', iso);
}

/* Geser kiri/kanan pada grid untuk ganti bulan. */
let swipe = null;
function onDown(e) {
    swipe = { x: e.clientX, y: e.clientY };
}
function onUp(e) {
    if (!swipe) return;
    const dx = e.clientX - swipe.x;
    const dy = e.clientY - swipe.y;
    swipe = null;
    if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy) * 1.5) {
        justSwiped = true;
        setTimeout(() => (justSwiped = false), 50);
        go(dx < 0 ? 1 : -1);
    }
}
</script>

<template>
    <div class="rounded-2xl border border-line bg-surface p-3 select-none">
        <div class="mb-2 flex items-center justify-between">
            <button type="button" class="icon-btn size-8" aria-label="Bulan sebelumnya" @click="go(-1)">
                <ChevronLeft class="size-4" />
            </button>
            <span class="text-[14px] font-medium capitalize">{{ monthLabel(view) }}</span>
            <button type="button" class="icon-btn size-8 disabled:opacity-30" aria-label="Bulan berikutnya" :disabled="!canNext" @click="go(1)">
                <ChevronRight class="size-4" />
            </button>
        </div>

        <div class="grid grid-cols-7 text-center text-tiny font-medium text-muted">
            <span v-for="w in WEEKDAYS" :key="w" class="py-1">{{ w }}</span>
        </div>

        <div class="relative overflow-hidden touch-pan-y" @pointerdown="onDown" @pointerup="onUp" @pointercancel="swipe = null">
            <Transition :name="direction > 0 ? 'cal-next' : 'cal-prev'" mode="out-in">
                <div :key="view" class="grid grid-cols-7 gap-y-1">
                    <template v-for="(iso, i) in cells" :key="iso ?? 'blank-' + i">
                        <span v-if="!iso" />
                        <button
                            v-else
                            type="button"
                            class="mx-auto flex size-10 items-center justify-center rounded-full text-[14px] tnum transition-[background-color,color,transform] duration-150 active:scale-90 disabled:pointer-events-none disabled:text-line-strong"
                            :class="[
                                iso === model
                                    ? 'bg-accent font-semibold text-accent-fg'
                                    : iso === todayIso
                                      ? 'font-semibold text-accent-text ring-1 ring-accent-text/40 ring-inset'
                                      : 'text-ink hover:bg-sunken',
                            ]"
                            :disabled="iso > max"
                            :aria-pressed="iso === model"
                            :aria-label="iso"
                            @click="pick(iso)"
                        >
                            {{ Number(iso.slice(8)) }}
                        </button>
                    </template>
                </div>
            </Transition>
        </div>
    </div>
</template>

<style scoped>
.cal-next-enter-active,
.cal-next-leave-active,
.cal-prev-enter-active,
.cal-prev-leave-active {
    transition:
        opacity 0.18s ease,
        transform 0.22s var(--ease-out-soft);
}
.cal-next-enter-from,
.cal-prev-leave-to {
    opacity: 0;
    transform: translateX(24px);
}
.cal-next-leave-to,
.cal-prev-enter-from {
    opacity: 0;
    transform: translateX(-24px);
}
</style>
