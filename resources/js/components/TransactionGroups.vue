<script setup>
import { computed } from 'vue';
import TransactionRow from '@/components/TransactionRow.vue';
import Money from '@/components/ui/Money.vue';
import { dayLabel } from '@/lib/dates';

const props = defineProps({
    transactions: { type: Array, required: true },
    /** Judul tanggal menempel saat digulir (HP). Matikan bila ada bilah tetap lain di atas. */
    sticky: { type: Boolean, default: true },
});

/** Kelompokkan per tanggal (data sudah terurut dari server). */
const groups = computed(() => {
    const map = new Map();
    for (const t of props.transactions) {
        if (!map.has(t.occurred_on)) map.set(t.occurred_on, []);
        map.get(t.occurred_on).push(t);
    }
    return [...map].map(([date, items]) => ({
        date,
        items,
        net: items.reduce((s, t) => s + (t.type === 'income' ? t.amount : t.type === 'expense' ? -t.amount : 0), 0),
    }));
});
</script>

<template>
    <div class="flex flex-col gap-5">
        <section v-for="group in groups" :key="group.date">
            <header
                class="flex items-baseline justify-between py-2 md:px-2"
                :class="sticky && 'sticky top-0 z-10 -mx-4 bg-paper/90 px-4 backdrop-blur-md md:static md:mx-0 md:bg-transparent md:backdrop-blur-none'"
            >
                <h3 class="text-[13px] font-medium text-ink-2 first-letter:uppercase">{{ dayLabel(group.date) }}</h3>
                <Money v-if="group.net" :value="group.net" :sign="true" class="text-[13px] text-muted tnum" />
            </header>
            <TransitionGroup tag="div" name="list" class="relative divide-y divide-line md:divide-y-0">
                <TransactionRow v-for="t in group.items" :key="t.id" :transaction="t" />
            </TransitionGroup>
        </section>
    </div>
</template>
