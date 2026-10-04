<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { compact as toCompact, rupiah } from '@/lib/money';

const props = defineProps({
    value: { type: Number, default: 0 },
    sign: { type: Boolean, default: false },
    compact: { type: Boolean, default: false },
    /** Angka "bergulir" ke nilai baru saat berubah. */
    animate: { type: Boolean, default: false },
    /** Pisahkan "Rp" agar bisa ditata lebih kecil (untuk angka besar). */
    splitCurrency: { type: Boolean, default: false },
});

const shown = ref(props.value);
let frame = null;

watch(
    () => props.value,
    (to, from) => {
        cancelAnimationFrame(frame);
        if (!props.animate || matchMedia('(prefers-reduced-motion: reduce)').matches) {
            shown.value = to;
            return;
        }
        const start = performance.now();
        const duration = 700;
        const tick = (now) => {
            const t = Math.min(1, (now - start) / duration);
            const eased = 1 - Math.pow(2, -10 * t);
            shown.value = Math.round(from + (to - from) * (t === 1 ? 1 : eased));
            if (t < 1) frame = requestAnimationFrame(tick);
        };
        frame = requestAnimationFrame(tick);
    },
);

onBeforeUnmount(() => cancelAnimationFrame(frame));

function text() {
    if (props.compact) {
        const v = toCompact(shown.value);
        return props.sign && shown.value > 0 ? '+' + v : v;
    }
    return rupiah(shown.value, { sign: props.sign });
}
</script>

<template>
    <span v-if="splitCurrency" class="whitespace-nowrap"
        ><span class="mr-[0.18em] align-[0.42em] text-[0.42em] font-medium tracking-normal text-muted">{{
            text().match(/^[^\d]*/)[0].trim()
        }}</span
        >{{ text().replace(/^[^\d]*/, '') }}</span
    >
    <span v-else class="whitespace-nowrap">{{ text() }}</span>
</template>
