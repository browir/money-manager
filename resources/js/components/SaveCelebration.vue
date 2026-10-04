<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { dismissCelebration, useCelebration } from '@/composables/useCelebration';
import { rupiah } from '@/lib/money';
import { color, tint } from '@/lib/palette';

const c = useCelebration();

/* Warna lencana mengikuti jenis transaksi; tanda centang memakai warna kontras dari tema. */
const badge = computed(
    () =>
        ({
            income: { bg: 'var(--pos)', fg: 'var(--paper)' },
            transfer: { bg: 'var(--ink)', fg: 'var(--paper)' },
        })[c.type] ?? { bg: 'var(--accent)', fg: 'var(--accent-fg)' },
);
const sign = computed(() => ({ income: '+', expense: '−' })[c.type] ?? '');

/* Nominal "berhitung" dari 0 ke nilai akhir. */
const shown = ref(0);
let frame = null;

watch(
    () => c.key,
    () => {
        cancelAnimationFrame(frame);
        shown.value = 0;
        if (matchMedia('(prefers-reduced-motion: reduce)').matches) {
            shown.value = c.amount;
            return;
        }
        const delay = 220;
        const duration = 620;
        const start = performance.now() + delay;
        const tick = (now) => {
            const t = Math.min(1, Math.max(0, (now - start) / duration));
            shown.value = Math.round(c.amount * (t === 1 ? 1 : 1 - Math.pow(2, -10 * t)));
            if (t < 1) frame = requestAnimationFrame(tick);
        };
        frame = requestAnimationFrame(tick);
    },
);

onBeforeUnmount(() => cancelAnimationFrame(frame));
</script>

<template>
    <Teleport to="body">
        <Transition name="celebrate">
            <div
                v-if="c.show"
                :key="c.key"
                class="fixed inset-0 z-[80] grid place-items-center bg-paper/75 px-8 backdrop-blur-[6px]"
                role="status"
                aria-live="polite"
                @click="dismissCelebration"
            >
                <div class="celebrate-body flex flex-col items-center text-center">
                    <div class="relative size-[88px]">
                        <span class="ripple" :style="{ borderColor: badge.bg }" />
                        <span class="ripple ripple-late" :style="{ borderColor: badge.bg }" />
                        <span
                            class="badge absolute inset-0 grid place-items-center rounded-full shadow-[0_14px_30px_-12px_var(--accent)]"
                            :style="{ background: badge.bg, color: badge.fg }"
                        >
                            <svg viewBox="0 0 52 52" class="size-11" aria-hidden="true">
                                <path
                                    class="check"
                                    d="M14 27.5l8 8 16-17"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="4.5"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                            </svg>
                        </span>
                    </div>

                    <p class="rise mt-7 text-[13px] font-medium tracking-[0.08em] text-muted uppercase" style="animation-delay: 0.16s">
                        {{ c.label }}
                    </p>
                    <p
                        class="rise mt-2 text-[38px] leading-none font-semibold tracking-[-0.035em] tnum"
                        :class="{ 'text-pos': c.type === 'income', 'text-ink-2': c.type === 'transfer' }"
                        style="animation-delay: 0.22s"
                    >
                        {{ sign }}{{ rupiah(shown) }}
                    </p>
                    <p
                        v-if="c.subtitle"
                        class="rise mt-4 inline-flex items-center gap-2 rounded-full py-1.5 pr-3.5 pl-1.5 text-[14px] text-ink-2"
                        :style="{ background: tint(c.color, 12), animationDelay: '0.3s' }"
                    >
                        <span
                            v-if="c.icon"
                            class="grid size-6 place-items-center rounded-full"
                            :style="{ background: tint(c.color, 20), color: color(c.color) }"
                        >
                            <component :is="c.icon" class="size-3.5" :stroke-width="2" />
                        </span>
                        {{ c.subtitle }}
                    </p>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.celebrate-enter-active {
    transition: opacity 0.18s ease;
}
.celebrate-leave-active {
    transition: opacity 0.32s ease;
}
.celebrate-leave-active .celebrate-body {
    transition: transform 0.32s var(--ease-out-soft);
}
.celebrate-enter-from,
.celebrate-leave-to {
    opacity: 0;
}
.celebrate-leave-to .celebrate-body {
    transform: translateY(-10px) scale(0.97);
}

.badge {
    animation: pop 0.55s cubic-bezier(0.34, 1.56, 0.64, 1) both;
}
.check {
    stroke-dasharray: 36;
    stroke-dashoffset: 36;
    animation: draw 0.36s 0.24s cubic-bezier(0.65, 0, 0.35, 1) forwards;
}
.ripple {
    position: absolute;
    inset: 0;
    border-radius: 9999px;
    border: 2px solid;
    opacity: 0;
    animation: ripple 1.1s 0.12s cubic-bezier(0.22, 1, 0.36, 1) both;
}
.ripple-late {
    animation-delay: 0.32s;
}
.rise {
    animation: rise-in 0.5s cubic-bezier(0.22, 1, 0.36, 1) both;
}

@keyframes pop {
    0% {
        transform: scale(0.3);
        opacity: 0;
    }
    100% {
        transform: scale(1);
        opacity: 1;
    }
}
@keyframes draw {
    to {
        stroke-dashoffset: 0;
    }
}
@keyframes ripple {
    0% {
        transform: scale(0.9);
        opacity: 0.55;
    }
    100% {
        transform: scale(2.3);
        opacity: 0;
    }
}
@keyframes rise-in {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
}
</style>
