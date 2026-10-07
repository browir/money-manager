<script setup>
import { Head, router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { Search, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { route } from 'ziggy-js';
import TransactionGroups from '@/components/TransactionGroups.vue';
import Money from '@/components/ui/Money.vue';
import MonthSwitcher from '@/components/ui/MonthSwitcher.vue';
import { openQuickAdd } from '@/composables/useQuickAdd';
import { useLedger } from '@/composables/useLedger';
import { useShortcuts } from '@/composables/useShortcuts';
import { currentMonth, shiftMonth } from '@/lib/dates';

const props = defineProps({
    month: String,
    filters: Object,
    transactions: Array,
});

const { accounts, categoryById } = useLedger();
const search = ref(props.filters.q ?? '');
const searchInput = ref(null);

const TYPES = [
    { value: undefined, label: 'Semua' },
    { value: 'expense', label: 'Pengeluaran' },
    { value: 'income', label: 'Pemasukan' },
    { value: 'transfer', label: 'Transfer' },
];

function apply(changes) {
    const params = { month: props.month, ...props.filters, ...changes };
    if (params.month === currentMonth()) delete params.month;
    for (const key of Object.keys(params)) if (params[key] === undefined || params[key] === '' || params[key] === null) delete params[key];
    router.get(route('transactions.index'), params, { preserveState: true, preserveScroll: true, replace: true });
}

const applySearch = useDebounceFn((q) => q !== (props.filters.q ?? '') && apply({ q }), 280);
let silent = false;
watch(search, (q) => (silent ? (silent = false) : applySearch(q)));

function clearFilters() {
    silent = search.value !== '';
    search.value = '';
    apply({ type: undefined, account: undefined, category: undefined, q: undefined });
}

const totals = computed(() =>
    props.transactions.reduce(
        (acc, t) => {
            if (t.type === 'income') acc.income += t.amount;
            if (t.type === 'expense') acc.expense += t.amount;
            return acc;
        },
        { income: 0, expense: 0 },
    ),
);

const activeCategory = computed(() => categoryById.value[props.filters.category]);
const hasFilters = computed(() => Object.keys(props.filters).length > 0);

useShortcuts({
    '/': () => searchInput.value?.focus(),
    arrowleft: () => apply({ month: shiftMonth(props.month, -1) }),
    arrowright: () => props.month < currentMonth() && apply({ month: shiftMonth(props.month, 1) }),
});
</script>

<template>
    <Head title="Transaksi" />

    <header class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="page-title">Transaksi</h1>
        <MonthSwitcher :month="month" class="-mr-2" @change="(m) => apply({ month: m })" />
    </header>

    <!-- Cari & saring -->
    <div class="mb-6 flex flex-col gap-3">
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
                class="absolute top-1/2 right-3 hidden -translate-y-1/2 rounded-md border border-line px-1.5 font-mono text-[11px] text-muted pointer:block"
                >/</kbd
            >
        </label>

        <div class="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4 md:mx-0 md:flex-wrap md:px-0">
            <button
                v-for="t in TYPES"
                :key="t.label"
                type="button"
                class="chip"
                :aria-pressed="filters.type === t.value"
                @click="apply({ type: t.value })"
            >
                {{ t.label }}
            </button>

            <span class="mx-1 w-px shrink-0 self-stretch bg-line" />

            <label class="chip relative pr-8" :aria-pressed="!!filters.account">
                {{ filters.account ? accounts.find((a) => a.id == filters.account)?.name : 'Semua akun' }}
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

            <button v-if="activeCategory" type="button" class="chip" aria-pressed="true" @click="apply({ category: undefined })">
                {{ activeCategory.name }} <X class="size-3.5" />
            </button>
        </div>
    </div>

    <!-- Ringkasan -->
    <div v-if="transactions.length" class="card mb-5 grid grid-cols-[0.7fr_1fr_1fr] divide-x divide-line py-3.5">
        <div class="px-3 md:px-4">
            <p class="text-[12px] text-muted">Transaksi</p>
            <p class="mt-0.5 text-[15px] font-semibold md:text-[17px] tnum">{{ transactions.length }}</p>
        </div>
        <div class="min-w-0 px-3 md:px-4">
            <p class="text-[12px] text-muted">Masuk</p>
            <Money :value="totals.income" class="mt-0.5 block truncate text-[15px] font-semibold md:text-[17px] text-pos tnum" />
        </div>
        <div class="min-w-0 px-3 md:px-4">
            <p class="text-[12px] text-muted">Keluar</p>
            <Money :value="totals.expense" class="mt-0.5 block truncate text-[15px] font-semibold md:text-[17px] tnum" />
        </div>
    </div>

    <TransactionGroups v-if="transactions.length" :transactions="transactions" />

    <div v-else class="card px-4 py-14 text-center">
        <span class="mx-auto mb-3 grid size-12 place-items-center rounded-full bg-accent-soft text-accent-text">
            <Search class="size-5" />
        </span>
        <p class="mb-1 text-[15px] font-medium">{{ hasFilters ? 'Tidak ada yang cocok' : 'Belum ada transaksi' }}</p>
        <p class="mb-5 text-sm text-muted">
            {{ hasFilters ? 'Coba ubah kata kunci atau saringan.' : 'Catatan untuk bulan ini akan muncul di sini.' }}
        </p>
        <button v-if="hasFilters" type="button" class="btn btn-quiet" @click="clearFilters">
            Hapus saringan
        </button>
        <button v-else type="button" class="btn btn-quiet" @click="openQuickAdd()">Catat transaksi</button>
    </div>
</template>
