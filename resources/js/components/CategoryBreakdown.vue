<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { route } from 'ziggy-js';
import IconTile from '@/components/ui/IconTile.vue';
import { useLedger } from '@/composables/useLedger';
import { categoryIcon } from '@/lib/icons';
import { rupiah } from '@/lib/money';
import { color } from '@/lib/palette';

const props = defineProps({
    month: { type: String, required: true },
    items: { type: Array, required: true }, // [{ category_id, total, count }]
});

const { categoryById } = useLedger();
const total = computed(() => props.items.reduce((s, i) => s + i.total, 0));

const rows = computed(() =>
    props.items.map((item) => {
        const c = categoryById.value[item.category_id];
        return {
            ...item,
            name: c?.name ?? 'Tanpa kategori',
            icon: categoryIcon(c?.icon),
            color: c?.color ?? 'slate',
            share: total.value ? item.total / total.value : 0,
        };
    }),
);

/** Batang bertumpuk: 6 teratas, sisanya dilipat menjadi "lainnya". */
const segments = computed(() => {
    const top = rows.value.slice(0, 6);
    const rest = rows.value.slice(6).reduce((s, r) => s + r.share, 0);
    return rest > 0 ? [...top, { category_id: 'rest', share: rest, color: 'slate' }] : top;
});

const percent = (share) => (share >= 0.995 ? '100' : share < 0.01 ? '<1' : Math.round(share * 100)) + '%';
</script>

<template>
    <section>
        <div class="mb-4 flex items-baseline justify-between">
            <h2 class="text-[15px] font-medium">Per kategori</h2>
            <span class="text-[13px] text-muted tnum">{{ rupiah(total) }}</span>
        </div>

        <template v-if="rows.length">
            <div class="mb-5 flex h-2.5 gap-[2px] overflow-hidden rounded-full" aria-hidden="true">
                <span
                    v-for="s in segments"
                    :key="s.category_id ?? 'none'"
                    class="h-full transition-[flex-grow] duration-700 ease-out-soft first:rounded-l-full last:rounded-r-full"
                    :style="{ flexGrow: s.share, flexBasis: 0, background: color(s.color) }"
                />
            </div>

            <ul class="-mx-2 flex flex-col">
                <li v-for="row in rows" :key="row.category_id ?? 'none'">
                    <Link
                        :href="route('transactions.index', { month, category: row.category_id ?? undefined, type: 'expense' })"
                        class="flex items-center gap-3 rounded-xl px-2 py-2 transition-colors hover:bg-sunken/70"
                    >
                        <IconTile :icon="row.icon" :color="row.color" size="sm" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[14px]">{{ row.name }}</span>
                            <span class="block text-[12px] text-muted">{{ row.count }} transaksi</span>
                        </span>
                        <span class="text-right">
                            <span class="block text-[14px] font-medium tnum">{{ rupiah(row.total) }}</span>
                            <span class="block text-[12px] text-muted tnum">{{ percent(row.share) }}</span>
                        </span>
                    </Link>
                </li>
            </ul>
        </template>
        <p v-else class="rounded-2xl border border-dashed border-line px-4 py-8 text-center text-sm text-muted">
            Belum ada pengeluaran bulan ini.
        </p>
    </section>
</template>
