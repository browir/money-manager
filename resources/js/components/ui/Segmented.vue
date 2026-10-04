<script setup>
import { computed } from 'vue';

const props = defineProps({
    options: { type: Array, required: true }, // [{ value, label }]
    size: { type: String, default: 'md' },
});
const model = defineModel({ required: true });

const index = computed(() => Math.max(0, props.options.findIndex((o) => o.value === model.value)));
</script>

<template>
    <div
        role="radiogroup"
        class="relative grid rounded-xl bg-sunken p-1"
        :class="size === 'sm' ? 'h-9' : 'h-10'"
        :style="{ gridTemplateColumns: `repeat(${options.length}, minmax(0, 1fr))` }"
    >
        <span
            aria-hidden="true"
            class="absolute inset-y-1 left-1 rounded-[9px] bg-surface shadow-[0_1px_2px_rgb(0_0_0/0.08)] transition-transform duration-300 ease-out-soft dark:bg-line-strong"
            :style="{
                width: `calc((100% - 0.5rem) / ${options.length})`,
                transform: `translateX(${index * 100}%)`,
            }"
        />
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            role="radio"
            :aria-checked="option.value === model"
            class="relative z-10 rounded-[9px] text-[13px] font-medium transition-colors duration-200"
            :class="option.value === model ? 'text-ink' : 'text-muted hover:text-ink-2'"
            @click="model = option.value"
        >
            {{ option.label }}
        </button>
    </div>
</template>
