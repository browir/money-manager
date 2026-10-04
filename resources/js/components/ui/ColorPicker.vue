<script setup>
import { Check } from 'lucide-vue-next';
import { COLORS, color } from '@/lib/palette';

const model = defineModel({ required: true });
</script>

<template>
    <div class="flex flex-wrap gap-2.5" role="radiogroup" aria-label="Warna">
        <button
            v-for="key in COLORS"
            :key="key"
            type="button"
            role="radio"
            :aria-checked="model === key"
            :aria-label="key"
            class="grid size-9 place-items-center rounded-full text-white transition-transform active:scale-90"
            :class="model === key && 'ring-2 ring-offset-2 ring-offset-surface'"
            :style="{ background: color(key), '--tw-ring-color': color(key) }"
            @click="model = key"
        >
            <Check v-if="model === key" class="size-4" :stroke-width="2.6" />
        </button>
    </div>
</template>
