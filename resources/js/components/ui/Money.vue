<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { usePrivacy } from '@/composables/usePrivacy';
import { compact as toCompact, rupiah } from '@/lib/money';

const props = defineProps({
    value: { type: Number, default: 0 },
    sign: { type: Boolean, default: false },
    compact: { type: Boolean, default: false },
    /** Angka "bergulir" ke nilai baru saat berubah. */
    animate: { type: Boolean, default: false },
    /** Pisahkan "Rp" agar bisa ditata lebih kecil (untuk angka besar). */
    splitCurrency: { type: Boolean, default: false },
    /** Tampilkan angka walau mode privasi aktif (mis. saat ditahan untuk mengintip). */
    reveal: { type: Boolean, default: false },
});

const privacy = usePrivacy();
const hidden = computed(() => privacy.value && !props.reveal);
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

/*
 * Mode privasi: angka diganti titik dengan panjang tetap (seperti aplikasi bank).
 * Panjang tetap = besar-kecilnya nominal tidak bisa ditebak dari lebar teks.
 * Tanda +/− tetap tampil karena arahnya sudah terlihat dari warna & konteks.
 */
const MASK = '••••••';

function prefix() {
    if (props.value < 0) return '−';
    return props.sign && props.value > 0 ? '+' : '';
}

function text() {
    if (hidden.value) return props.compact ? prefix() + '•••' : `${prefix()}Rp ${MASK}`;
    if (props.compact) {
        const v = toCompact(shown.value);
        return props.sign && shown.value > 0 ? '+' + v : v;
    }
    return rupiah(shown.value, { sign: props.sign });
}

/** Bagian depan ("−Rp") dan angka, untuk splitCurrency. */
const head = () => text().match(/^[^\d•]*/)[0].trim();
const body = () => text().replace(/^[^\d•]*/, '');
</script>

<template>
    <span class="amount whitespace-nowrap" :aria-label="hidden ? 'Nominal disembunyikan' : undefined">
        <Transition name="amount-swap" mode="out-in">
            <span v-if="splitCurrency" :key="hidden ? 'mask' : 'value'" :class="hidden && 'amount-mask'"
                ><span class="mr-[0.18em] align-[0.42em] text-[0.42em] font-medium tracking-normal text-muted">{{ head() }}</span
                ><span :class="hidden && 'align-[0.1em] text-[0.62em] tracking-[0.14em]'">{{ body() }}</span></span
            >
            <span v-else :key="hidden ? 'mask' : 'value'" :class="hidden && 'amount-mask'">{{ text() }}</span>
        </Transition>
    </span>
</template>
