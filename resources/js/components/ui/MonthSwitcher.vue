<script setup>
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';
import { usePeriod } from '@/composables/usePeriod';
import { monthLabel, shiftMonth } from '@/lib/dates';

const props = defineProps({
    month: { type: String, required: true },
});
const emit = defineEmits(['change']);

const period = usePeriod();
const isCurrent = computed(() => props.month >= period.current.value);
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
            class="min-h-9 min-w-[7.5rem] rounded-xl px-2 py-0.5 text-[15px] font-medium capitalize transition-colors hover:bg-sunken"
            :title="isCurrent ? '' : `Kembali ke ${period.thisLabel.value}`"
            @click="!isCurrent && emit('change', period.current.value)"
        >
            <Transition name="fade" mode="out-in">
                <span :key="month" class="block leading-tight">
                    {{ monthLabel(month) }}
                    <span v-if="period.startDay.value > 1" class="block text-tiny font-normal text-muted normal-case tnum">
                        {{ period.rangeLabel(month) }}
                    </span>
                </span>
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
