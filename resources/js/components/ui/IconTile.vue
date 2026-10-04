<script setup>
import { color as paint, tint } from '@/lib/palette';

defineProps({
    icon: { type: [Object, Function], required: true },
    color: { type: String, default: 'slate' },
    size: { type: String, default: 'md' },
});
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center justify-center"
        :class="{
            'size-8 rounded-[10px]': size === 'sm',
            'size-10 rounded-xl': size === 'md',
            'size-12 rounded-[14px]': size === 'lg',
        }"
        :style="{ background: tint(color), color: paint(color) }"
    >
        <component :is="icon" :class="size === 'sm' ? 'size-4' : 'size-[18px]'" :stroke-width="1.9" />
    </span>
</template>
