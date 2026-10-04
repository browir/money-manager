<script setup>
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';
import { currentMonth, monthLabel, shiftMonth } from '@/lib/dates';

const props = defineProps({
    month: { type: String, required: true },
});
const emit = defineEmits(['change']);

const isCurrent = computed(() => props.month >= currentMonth());
</script>

<template>
    <div class="inline-flex items-center gap-0.5 rounded-xl">
        <button
            type="button"
            class="icon-btn"
            aria-label="Bulan sebelumnya"
            title="Bulan sebelumnya (←)"
            @click="emit('change', shiftMonth(month, -1))"
        >
            <ChevronLeft class="size-[18px]" />
        </button>
        <button
            type="button"
            class="h-9 min-w-[7.5rem] rounded-xl px-2 text-[15px] font-medium capitalize transition-colors hover:bg-sunken"
            :title="isCurrent ? '' : 'Kembali ke bulan ini'"
            @click="!isCurrent && emit('change', currentMonth())"
        >
            <Transition name="fade" mode="out-in">
                <span :key="month">{{ monthLabel(month) }}</span>
            </Transition>
        </button>
        <button
            type="button"
            class="icon-btn disabled:opacity-30"
            aria-label="Bulan berikutnya"
            title="Bulan berikutnya (→)"
            :disabled="isCurrent"
            @click="emit('change', shiftMonth(month, 1))"
        >
            <ChevronRight class="size-[18px]" />
        </button>
    </div>
</template>
