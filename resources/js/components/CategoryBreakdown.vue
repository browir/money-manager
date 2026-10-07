<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { route } from 'ziggy-js';
import IconTile from '@/components/ui/IconTile.vue';
import Money from '@/components/ui/Money.vue';
import { useLedger } from '@/composables/useLedger';
import { currentMonth } from '@/lib/dates';
import { categoryIcon } from '@/lib/icons';
import { color } from '@/lib/palette';

const props = defineProps({
    month: { type: String, required: true },
    items: { type: Array, required: true }, // [{ category_id, total, count }]
    /** Tampilkan n kategori teratas dulu; 0 = semua. */
    limit: { type: Number, default: 0 },
});

const expanded = ref(false);

const { categories, categoryById } = useLedger();
// Anggaran berlaku per bulan dengan nilai saat ini, jadi hanya ditampilkan untuk bulan berjalan.
const showBudget = computed(() => props.month === currentMonth());
const hasBudgets = computed(() => categories.value.some((c) => c.budget));
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
            budget: showBudget.value && c?.budget ? c.budget : null,
        };
    }),
);

/** Batang bertumpuk: 6 teratas, sisanya dilipat menjadi "lainnya". */
const segments = computed(() => {
    const top = rows.value.slice(0, 6);
    const rest = rows.value.slice(6).reduce((s, r) => s + r.share, 0);
    return rest > 0 ? [...top, { category_id: 'rest', share: rest, color: 'slate' }] : top;
});

const visibleRows = computed(() => (props.limit && !expanded.value ? rows.value.slice(0, props.limit) : rows.value));
const hiddenCount = computed(() => rows.value.length - visibleRows.value.length);

const percent = (share) => (share >= 0.995 ? '100' : share < 0.01 ? '<1' : Math.round(share * 100)) + '%';
</script>

<template>
    <section>
        <div class="mb-4 flex items-baseline justify-between">
            <h2 class="section-title">Per kategori</h2>
            <Link
                v-if="showBudget && !hasBudgets"
                :href="route('categories.index')"
                class="text-[13px] text-accent-text hover:underline"
            >
                Atur anggaran
            </Link>
            <Money v-else :value="total" class="text-[13px] text-muted tnum" />
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
                <li v-for="row in visibleRows" :key="row.category_id ?? 'none'">
                    <Link
                        :href="route('transactions.index', { month, category: row.category_id ?? undefined, type: 'expense' })"
                        class="flex items-center gap-3 rounded-xl px-2 py-2 transition-colors hover:bg-sunken/70"
                    >
                        <IconTile :icon="row.icon" :color="row.color" size="sm" />
                        <!-- Nama & nominal di atas, bilah anggaran selebar baris, keterangan di bawah:
                             panjang bilah sama di semua baris, tidak tergantung lebar angka. -->
                        <span class="min-w-0 flex-1">
                            <span class="flex items-baseline justify-between gap-3">
                                <span class="truncate text-[14px]">{{ row.name }}</span>
                                <Money :value="row.total" class="shrink-0 text-[14px] font-medium tnum" />
                            </span>
                            <span v-if="row.budget" class="mt-1.5 block h-1.5 overflow-hidden rounded-full bg-sunken">
                                <span
                                    class="block h-full rounded-full"
                                    :class="row.total > row.budget ? 'bg-neg' : row.total >= row.budget * 0.85 ? 'bg-warn' : 'bg-bar'"
                                    :style="{ width: `${Math.min(100, (row.total / row.budget) * 100)}%` }"
                                />
                            </span>
                            <span class="mt-1 flex items-baseline justify-between gap-3 text-[12px] tnum">
                                <span v-if="row.budget" class="truncate" :class="row.total > row.budget ? 'text-neg' : 'text-muted'">
                                    <template v-if="row.total > row.budget">Lewat <Money :value="row.total - row.budget" /></template>
                                    <template v-else>Sisa <Money :value="row.budget - row.total" /></template>
                                </span>
                                <span v-else class="truncate text-muted">{{ row.count }} transaksi</span>
                                <span class="shrink-0 text-muted">{{ percent(row.share) }}</span>
                            </span>
                        </span>
                    </Link>
                </li>
            </ul>
            <button
                v-if="limit && rows.length > limit"
                type="button"
                class="mt-1 w-full rounded-xl py-2.5 text-[13px] font-medium text-accent-text transition-colors hover:bg-sunken/70"
                @click="expanded = !expanded"
            >
                {{ expanded ? 'Ringkas' : `Tampilkan ${hiddenCount} lainnya` }}
            </button>
        </template>
        <p v-else class="rounded-2xl bg-sunken/60 px-4 py-8 text-center text-sm text-muted">
            Belum ada pengeluaran bulan ini.
        </p>
    </section>
</template>
