<script setup>
import { Head, router } from '@inertiajs/vue3';
import { useDebounceFn, useMediaQuery } from '@vueuse/core';
import { CalendarRange, Search, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { route } from 'ziggy-js';
import TransactionGroups from '@/components/TransactionGroups.vue';
import IconTile from '@/components/ui/IconTile.vue';
import Money from '@/components/ui/Money.vue';
import MonthSwitcher from '@/components/ui/MonthSwitcher.vue';
import Segmented from '@/components/ui/Segmented.vue';
import Sheet from '@/components/ui/Sheet.vue';
import { openQuickAdd } from '@/composables/useQuickAdd';
import { useLedger } from '@/composables/useLedger';
import { usePeriod } from '@/composables/usePeriod';
import { useShortcuts } from '@/composables/useShortcuts';
import { monthLabel, shiftMonth, shortDate, today } from '@/lib/dates';
import { categoryIcon } from '@/lib/icons';
import { color } from '@/lib/palette';

const props = defineProps({
    month: String,
    filters: Object,
    range: Object, // { mode: 'period' | 'all' | 'custom', from, to }
    totals: Object, // { count, income, expense } — dihitung penuh di server
    transactions: Array,
});

const { accounts, categories, categoryById } = useLedger();
const period = usePeriod();
const wide = useMediaQuery('(min-width: 1024px)');
const search = ref(props.filters.q ?? '');
const searchInput = ref(null);

const TYPES = [
    { value: '', label: 'Semua' },
    { value: 'expense', label: 'Keluar' },
    { value: 'income', label: 'Masuk' },
    { value: 'transfer', label: 'Transfer' },
];

/* ---- Navigasi: semua saringan ada di URL ---- */
function apply(changes) {
    const range = props.range.mode === 'all' ? { all: 1 } : props.range.mode === 'custom' ? { from: props.range.from, to: props.range.to } : {};
    const params = { month: props.month, ...props.filters, ...range, ...changes };
    if (params.all || params.from || params.to || params.month === period.current.value) delete params.month;
    for (const key of Object.keys(params)) if (params[key] === undefined || params[key] === '' || params[key] === null) delete params[key];
    router.get(route('transactions.index'), params, { preserveState: true, preserveScroll: true, replace: true });
}

const applySearch = useDebounceFn((q) => q !== (props.filters.q ?? '') && apply({ q }), 280);
let silent = false;
watch(search, (q) => (silent ? (silent = false) : applySearch(q)));

function clearFilters() {
    silent = search.value !== '';
    search.value = '';
    apply({ type: undefined, account: undefined, category: undefined, q: undefined, all: undefined, from: undefined, to: undefined });
}

const type = computed({
    get: () => props.filters.type ?? '',
    set: (value) => apply({ type: value || undefined }),
});

/* ---- Rentang waktu ---- */
const rangeSheet = ref(false);
const draft = ref({ from: '', to: '' });

function setRange(mode) {
    rangeSheet.value = false;
    if (mode === 'period') apply({ all: undefined, from: undefined, to: undefined, month: period.current.value });
    if (mode === 'all') apply({ all: 1, from: undefined, to: undefined });
}
function applyCustom() {
    if (!draft.value.from && !draft.value.to) return;
    rangeSheet.value = false;
    apply({ all: undefined, from: draft.value.from || undefined, to: draft.value.to || undefined });
}
function openRangeSheet() {
    draft.value = { from: props.range.from ?? '', to: props.range.to ?? '' };
    rangeSheet.value = true;
}
watch(
    () => props.range,
    (r) => (draft.value = { from: r.from ?? '', to: r.to ?? '' }),
    { immediate: true },
);

const rangeMode = computed({
    get: () => props.range.mode,
    set: (mode) => {
        if (mode === 'custom') {
            // Isi awal: periode yang sedang dilihat, lalu pengguna menyesuaikan.
            const r = period.range(props.month);
            apply({ all: undefined, from: r.start, to: r.end > today() ? today() : r.end });
        } else setRange(mode);
    },
});

const rangeText = computed(() => {
    const { mode, from, to } = props.range;
    if (mode === 'all') return 'Semua waktu';
    if (mode === 'custom') {
        if (from && to) return from === to ? shortDate(from) : `${shortDate(from)} – ${shortDate(to)}`;
        return from ? `Sejak ${shortDate(from)}` : `Sampai ${shortDate(to)}`;
    }
    const label = monthLabel(props.month);
    return period.startDay.value > 1 ? `${label} (${period.rangeLabel(props.month)})` : label;
});

/* ---- Ringkasan ---- */
const net = computed(() => props.totals.income - props.totals.expense);
const limited = computed(() => props.transactions.length < props.totals.count);
const hasFilters = computed(() => Object.keys(props.filters).length > 0 || props.range.mode !== 'period');

const activeAccount = computed(() => accounts.value.find((a) => a.id == props.filters.account));
const activeCategory = computed(() => categoryById.value[props.filters.category]);
const categoryGroups = computed(() => [
    { label: 'Pengeluaran', items: categories.value.filter((c) => c.type === 'expense') },
    { label: 'Pemasukan', items: categories.value.filter((c) => c.type === 'income') },
]);

/** Pengeluaran per kategori dari daftar yang tampil (panel kanan, desktop). */
const byCategory = computed(() => {
    const map = new Map();
    for (const t of props.transactions) {
        if (t.type !== 'expense') continue;
        map.set(t.category_id, (map.get(t.category_id) ?? 0) + t.amount);
    }
    const rows = [...map].map(([id, total]) => ({ id, total, category: categoryById.value[id] })).sort((a, b) => b.total - a.total);
    const max = rows[0]?.total ?? 0;
    return rows.slice(0, 6).map((r) => ({ ...r, share: max ? r.total / max : 0 }));
});

useShortcuts({
    '/': () => searchInput.value?.focus(),
    arrowleft: () => props.range.mode === 'period' && apply({ month: shiftMonth(props.month, -1) }),
    arrowright: () =>
        props.range.mode === 'period' && props.month < period.current.value && apply({ month: shiftMonth(props.month, 1) }),
});
</script>

<template>
    <Head title="Transaksi" />

    <header class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <h1 class="page-title">Transaksi</h1>
            <p class="mt-0.5 text-small text-muted tnum">{{ totals.count }} transaksi</p>
        </div>
        <MonthSwitcher v-if="range.mode === 'period'" :month="month" class="-mr-2" @change="(m) => apply({ month: m })" />
        <button v-else type="button" class="chip" aria-pressed="true" title="Kembali ke periode ini" @click="setRange('period')">
            <CalendarRange class="size-4" /> {{ rangeText }} <X class="size-3.5" />
        </button>
    </header>

    <div class="lg:grid lg:grid-cols-[minmax(0,1fr)_320px] lg:items-start lg:gap-8">
        <div class="min-w-0">
            <!-- Cari -->
            <label class="relative block">
                <Search class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted" />
                <input
                    ref="searchInput"
                    v-model="search"
                    type="search"
                    class="field h-12 rounded-2xl pr-10 pl-10 shadow-card"
                    placeholder="Cari catatan atau kategori…"
                    @keydown.esc="search = ''"
                />
                <kbd
                    class="absolute top-1/2 right-3 hidden -translate-y-1/2 rounded-md border border-line px-1.5 font-mono text-tiny text-muted pointer:block"
                    >/</kbd
                >
            </label>
            <button
                v-if="filters.q && range.mode !== 'all'"
                type="button"
                class="mt-2 px-1 text-small font-medium text-accent-text hover:underline"
                @click="setRange('all')"
            >
                Cari “{{ filters.q }}” di semua waktu →
            </button>

            <!-- Saringan (HP & tablet; desktop ada di panel kanan) -->
            <div v-if="!wide" class="no-scrollbar -mx-4 mt-3 flex gap-2 overflow-x-auto px-4 py-0.5 md:mx-0 md:flex-wrap md:px-0">
                <button
                    v-for="t in TYPES"
                    :key="t.value"
                    type="button"
                    class="chip"
                    :aria-pressed="(filters.type ?? '') === t.value"
                    @click="type = t.value"
                >
                    {{ t.label }}
                </button>

                <span class="mx-1 w-px shrink-0 self-stretch bg-line" />

                <button type="button" class="chip" :aria-pressed="range.mode !== 'period'" @click="openRangeSheet">
                    <CalendarRange class="size-4" /> {{ range.mode === 'period' ? 'Rentang' : rangeText }}
                </button>

                <label class="chip relative pr-8" :aria-pressed="!!activeAccount">
                    {{ activeAccount?.name ?? 'Semua akun' }}
                    <svg class="pointer-events-none absolute right-3 size-3 opacity-60" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6"><path d="m3 4.5 3 3 3-3" /></svg>
                    <select
                        class="absolute inset-0 cursor-pointer opacity-0"
                        :value="filters.account ?? ''"
                        aria-label="Saring akun"
                        @change="apply({ account: $event.target.value || undefined })"
                    >
                        <option value="">Semua akun</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                    </select>
                </label>

                <label class="chip relative pr-8" :aria-pressed="!!activeCategory">
                    {{ activeCategory?.name ?? 'Semua kategori' }}
                    <svg class="pointer-events-none absolute right-3 size-3 opacity-60" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6"><path d="m3 4.5 3 3 3-3" /></svg>
                    <select
                        class="absolute inset-0 cursor-pointer opacity-0"
                        :value="filters.category ?? ''"
                        aria-label="Saring kategori"
                        @change="apply({ category: $event.target.value || undefined })"
                    >
                        <option value="">Semua kategori</option>
                        <optgroup v-for="g in categoryGroups" :key="g.label" :label="g.label">
                            <option v-for="c in g.items" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </optgroup>
                    </select>
                </label>
            </div>

            <!-- Ringkasan (HP & tablet): dua kolom + selisih di bawah agar nominal besar tidak terpotong -->
            <div v-if="!wide && totals.count" class="card mt-5 overflow-hidden">
                <div class="grid grid-cols-2 divide-x divide-line py-3.5">
                    <div class="min-w-0 px-4">
                        <p class="text-small text-muted">Masuk</p>
                        <Money :value="totals.income" class="mt-0.5 block truncate text-[16px] font-semibold text-pos tnum md:text-[17px]" />
                    </div>
                    <div class="min-w-0 px-4">
                        <p class="text-small text-muted">Keluar</p>
                        <Money :value="totals.expense" class="mt-0.5 block truncate text-[16px] font-semibold tnum md:text-[17px]" />
                    </div>
                </div>
                <div class="flex items-baseline justify-between gap-3 border-t border-line bg-sunken/40 px-4 py-2.5">
                    <span class="text-small text-muted">Selisih</span>
                    <Money :value="net" sign class="truncate text-[15px] font-semibold tnum" :class="net >= 0 ? 'text-pos' : 'text-neg'" />
                </div>
            </div>

            <p v-if="limited" class="mt-4 rounded-xl bg-sunken px-3.5 py-2.5 text-small text-muted">
                Menampilkan {{ transactions.length }} transaksi terbaru dari {{ totals.count }}. Ringkasan tetap menghitung semuanya.
            </p>

            <TransactionGroups v-if="transactions.length" :transactions="transactions" class="mt-5" />

            <div v-else class="card mt-5 px-4 py-14 text-center">
                <span class="mx-auto mb-3 flex size-12 items-center justify-center rounded-full bg-accent-soft text-accent-text">
                    <Search class="size-5" />
                </span>
                <p class="mb-1 text-[15px] font-medium">{{ hasFilters ? 'Tidak ada yang cocok' : 'Belum ada transaksi' }}</p>
                <p class="mb-5 text-sm text-muted">
                    {{ hasFilters ? 'Coba ubah kata kunci atau saringan.' : 'Catatan untuk periode ini akan muncul di sini.' }}
                </p>
                <div class="flex flex-wrap justify-center gap-2">
                    <button v-if="filters.q && range.mode !== 'all'" type="button" class="btn btn-primary" @click="setRange('all')">
                        Cari di semua waktu
                    </button>
                    <button v-if="hasFilters" type="button" class="btn btn-quiet" @click="clearFilters">Hapus saringan</button>
                    <button v-else type="button" class="btn btn-quiet" @click="openQuickAdd()">Catat transaksi</button>
                </div>
            </div>
        </div>

        <!-- Panel kanan (desktop) -->
        <aside v-if="wide" class="sticky top-6 flex flex-col gap-5">
            <section class="card p-5">
                <h2 class="section-title mb-4">Ringkasan</h2>
                <dl class="flex flex-col gap-2.5 text-[14px]">
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-muted">Masuk</dt>
                        <dd><Money :value="totals.income" class="font-medium text-pos tnum" /></dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-muted">Keluar</dt>
                        <dd><Money :value="totals.expense" class="font-medium tnum" /></dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3 border-t border-line pt-2.5">
                        <dt class="font-medium">Selisih</dt>
                        <dd>
                            <Money :value="net" sign class="text-[17px] font-semibold tnum" :class="net >= 0 ? 'text-pos' : 'text-neg'" />
                        </dd>
                    </div>
                </dl>
            </section>

            <section class="card flex flex-col gap-4 p-5">
                <div class="flex items-baseline justify-between">
                    <h2 class="section-title">Saring</h2>
                    <button v-if="hasFilters" type="button" class="text-[13px] text-accent-text hover:underline" @click="clearFilters">
                        Hapus semua
                    </button>
                </div>

                <div>
                    <span class="field-label">Jenis</span>
                    <Segmented v-model="type" :options="TYPES" size="sm" />
                </div>

                <div>
                    <span class="field-label">Rentang</span>
                    <Segmented
                        v-model="rangeMode"
                        :options="[
                            { value: 'period', label: 'Periode' },
                            { value: 'all', label: 'Semua' },
                            { value: 'custom', label: 'Tanggal' },
                        ]"
                        size="sm"
                    />
                    <form v-if="range.mode === 'custom'" class="mt-2.5 grid grid-cols-2 gap-2" @submit.prevent="applyCustom">
                        <input v-model="draft.from" type="date" class="field h-10 px-2.5 text-[14px]" aria-label="Dari tanggal" @change="applyCustom" />
                        <input v-model="draft.to" type="date" class="field h-10 px-2.5 text-[14px]" aria-label="Sampai tanggal" @change="applyCustom" />
                    </form>
                </div>

                <label class="block">
                    <span class="field-label">Akun</span>
                    <select class="field h-10 text-[14px]" :value="filters.account ?? ''" @change="apply({ account: $event.target.value || undefined })">
                        <option value="">Semua akun</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                    </select>
                </label>

                <label class="block">
                    <span class="field-label">Kategori</span>
                    <select class="field h-10 text-[14px]" :value="filters.category ?? ''" @change="apply({ category: $event.target.value || undefined })">
                        <option value="">Semua kategori</option>
                        <optgroup v-for="g in categoryGroups" :key="g.label" :label="g.label">
                            <option v-for="c in g.items" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </optgroup>
                    </select>
                </label>
            </section>

            <section v-if="byCategory.length" class="card p-5">
                <h2 class="section-title mb-3">Pengeluaran teratas</h2>
                <ul class="-mx-2 flex flex-col">
                    <li v-for="row in byCategory" :key="row.id ?? 'none'">
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left transition-colors hover:bg-sunken/70"
                            :title="row.category ? `Saring: ${row.category.name}` : undefined"
                            @click="row.id && apply({ category: row.id })"
                        >
                            <IconTile :icon="categoryIcon(row.category?.icon)" :color="row.category?.color ?? 'slate'" size="sm" />
                            <span class="min-w-0 flex-1">
                                <span class="flex items-baseline justify-between gap-3 text-[14px]">
                                    <span class="truncate">{{ row.category?.name ?? 'Tanpa kategori' }}</span>
                                    <Money :value="row.total" class="shrink-0 font-medium tnum" />
                                </span>
                                <span class="mt-1.5 block h-1.5 overflow-hidden rounded-full bg-sunken">
                                    <span
                                        class="block h-full rounded-full"
                                        :style="{ width: `${row.share * 100}%`, background: color(row.category?.color ?? 'slate') }"
                                    />
                                </span>
                            </span>
                        </button>
                    </li>
                </ul>
            </section>
        </aside>
    </div>

    <!-- Pilih rentang (HP) -->
    <Sheet :open="rangeSheet" title="Rentang waktu" @close="rangeSheet = false">
        <div class="flex flex-col gap-2 pb-2">
            <button
                type="button"
                class="flex h-12 items-center justify-between rounded-xl px-4 text-left text-[15px]"
                :class="range.mode === 'period' ? 'bg-accent-soft font-medium text-accent-text' : 'bg-sunken'"
                @click="setRange('period')"
            >
                Periode ini
                <span class="text-small text-muted">{{ period.rangeLabel(period.current.value) }}</span>
            </button>
            <button
                type="button"
                class="flex h-12 items-center rounded-xl px-4 text-left text-[15px]"
                :class="range.mode === 'all' ? 'bg-accent-soft font-medium text-accent-text' : 'bg-sunken'"
                @click="setRange('all')"
            >
                Semua waktu
            </button>

            <form class="mt-3 flex flex-col gap-3" @submit.prevent="applyCustom">
                <span class="field-label mb-0">Pilih tanggal</span>
                <div class="grid grid-cols-2 gap-2">
                    <label class="block">
                        <span class="mb-1 block text-small text-muted">Dari</span>
                        <input v-model="draft.from" type="date" class="field" />
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-small text-muted">Sampai</span>
                        <input v-model="draft.to" type="date" class="field" />
                    </label>
                </div>
                <button type="submit" class="btn btn-primary h-12" :disabled="!draft.from && !draft.to">Terapkan</button>
            </form>
        </div>
    </Sheet>
</template>
