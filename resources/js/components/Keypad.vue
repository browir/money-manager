<script setup>
import { Check, Delete, Minus, Plus } from 'lucide-vue-next';

defineProps({
    canSubmit: { type: Boolean, default: false },
    processing: { type: Boolean, default: false },
    submitLabel: { type: String, default: 'Simpan' },
});
const emit = defineEmits(['press', 'submit']);

let holdTimer = null;

function press(key) {
    navigator.vibrate?.(4);
    emit('press', key);
}

// Tahan tombol hapus untuk mengosongkan.
function holdStart() {
    holdTimer = setTimeout(() => {
        navigator.vibrate?.(12);
        emit('press', 'clear');
        holdTimer = null;
    }, 450);
}
function holdEnd() {
    if (holdTimer) {
        clearTimeout(holdTimer);
        holdTimer = null;
        press('back');
    }
}
function holdCancel() {
    clearTimeout(holdTimer);
    holdTimer = null;
}

const rows = [
    ['1', '2', '3'],
    ['4', '5', '6'],
    ['7', '8', '9'],
];
</script>

<template>
    <div class="grid grid-cols-4 gap-1.5 select-none" @contextmenu.prevent>
        <template v-for="(row, r) in rows" :key="r">
            <button v-for="key in row" :key="key" type="button" class="key" @click="press(key)">{{ key }}</button>
            <button
                v-if="r === 0"
                type="button"
                class="key text-ink-2"
                aria-label="Hapus (tahan untuk kosongkan)"
                @pointerdown="holdStart"
                @pointerup="holdEnd"
                @pointerleave="holdCancel"
            >
                <Delete class="size-[22px]" :stroke-width="1.7" />
            </button>
            <button v-if="r === 1" type="button" class="key text-ink-2" aria-label="Kurang" @click="press('-')">
                <Minus class="size-5" :stroke-width="1.9" />
            </button>
            <button v-if="r === 2" type="button" class="key text-ink-2" aria-label="Tambah" @click="press('+')">
                <Plus class="size-5" :stroke-width="1.9" />
            </button>
        </template>
        <button type="button" class="key text-[18px]" @click="press('000')">000</button>
        <button type="button" class="key" @click="press('0')">0</button>
        <button
            type="button"
            class="col-span-2 inline-flex h-[52px] items-center justify-center gap-2 rounded-2xl bg-accent text-[15px] font-semibold text-accent-fg transition active:scale-[0.97] disabled:opacity-40"
            :disabled="!canSubmit || processing"
            :class="processing && 'disabled:opacity-80'"
            @click="emit('submit')"
        >
            <svg v-if="processing" class="size-[18px] animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3" />
                <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
            </svg>
            <Check v-else class="size-[18px]" :stroke-width="2.4" />
            {{ processing ? 'Menyimpan…' : submitLabel }}
        </button>
    </div>
</template>

<style scoped>
@reference '../../css/app.css';

.key {
    @apply inline-flex h-[52px] items-center justify-center rounded-2xl bg-sunken text-[21px] font-medium text-ink transition-[transform,background-color] duration-100 active:scale-[0.94] active:bg-line;
}
</style>
