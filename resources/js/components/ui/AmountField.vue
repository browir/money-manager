<script setup>
import { ref, watch } from 'vue';
import { digits } from '@/lib/money';

/** Input rupiah yang memformat titik ribuan saat diketik. Boleh negatif. */
const model = defineModel({ type: Number, default: 0 });
const text = ref(format(model.value));

function format(n) {
    if (!n) return '';
    return (n < 0 ? '-' : '') + digits(Math.abs(n));
}

function onInput(e) {
    const raw = e.target.value;
    const negative = raw.trim().startsWith('-');
    const value = Number(raw.replace(/\D/g, '').slice(0, 12)) * (negative ? -1 : 1);
    model.value = value;
    text.value = negative && !value ? '-' : format(value);
    e.target.value = text.value;
}

watch(model, (n) => {
    if (n !== Number(text.value.replace(/[^\d-]/g, '') || 0)) text.value = format(n);
});
</script>

<template>
    <div class="relative">
        <span class="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-[15px] text-muted">Rp</span>
        <input :value="text" type="text" inputmode="numeric" class="field pl-10 tnum" placeholder="0" @input="onInput" />
    </div>
</template>
