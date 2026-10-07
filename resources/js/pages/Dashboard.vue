<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useIntersectionObserver } from '@vueuse/core';
import {
    ArrowDownLeft, ArrowDownRight, ArrowRightLeft, ArrowUpRight, Eye, EyeOff, Plus, Search, Sun,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { route } from 'ziggy-js';
import CategoryBreakdown from '@/components/CategoryBreakdown.vue';
import DailyChart from '@/components/DailyChart.vue';
import DueRecurring from '@/components/DueRecurring.vue';
import TransactionGroups from '@/components/TransactionGroups.vue';
import IconTile from '@/components/ui/IconTile.vue';
import Money from '@/components/ui/Money.vue';
import MonthSwitcher from '@/components/ui/MonthSwitcher.vue';
import { togglePrivacy, usePrivacy } from '@/composables/usePrivacy';
import { openQuickAdd } from '@/composables/useQuickAdd';
import { useLedger } from '@/composables/useLedger';
import { useShortcuts } from '@/composables/useShortcuts';
import { currentMonth, daysInMonth, monthLabel, shiftMonth, today } from '@/lib/dates';
import { ACCOUNT_TYPES, accountIcon } from '@/lib/icons';
import { rupiah } from '@/lib/money';
import { color, tint } from '@/lib/palette';

const props = defineProps({
    month: String,
    totals: Object,
    byCategory: Array,
    daily: Array,
    recent: Array,
    due: { type: Array, default: () => [] }, // jadwal berulang yang jatuh tempo
});

const page = usePage();
const { activeAccounts, categories, netWorth } = useLedger();
const hidden = usePrivacy();

/* ---- Sapaan ---- */
const name = computed(() => page.props.auth.user?.name ?? '');
const firstName = computed(() => name.value.split(' ')[0]);
const initials = computed(() =>
    name.value
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0])
        .join('')
        .toUpperCase(),
);
const greeting = (() => {
    const h = new Date().getHours();
    if (h < 11) return 'Selamat pagi';
    if (h < 15) return 'Selamat siang';
    if (h < 19) return 'Selamat sore';
    return 'Selamat malam';
})();
const todayLong = new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date());

/* ---- Bulan ---- */
const isCurrentMonth = computed(() => props.month === currentMonth());
const net = computed(() => props.totals.income - props.totals.expense);

function delta(now, before) {
    if (!before) return null;
    return Math.round(((now - before) / before) * 100);
}
const incomeDelta = computed(() => delta(props.totals.income, props.totals.prevIncome));
const expenseDelta = computed(() => delta(props.totals.expense, props.totals.prevExpense));
const prevLabel = computed(() => monthLabel(shiftMonth(props.month, -1), { withYear: false }).slice(0, 3));

/** Porsi pemasukan yang sudah terpakai. */
const spentRatio = computed(() => (props.totals.income ? props.totals.expense / props.totals.income : null));
const ratioText = computed(() => {
    if (spentRatio.value === null) return props.totals.expense ? 'Belum ada pemasukan' : 'Belum ada catatan';
    const pct = Math.round(spentRatio.value * 100);
    return pct > 100 ? `Melebihi pemasukan ${pct - 100}%` : `Terpakai ${pct}% dari pemasukan`;
});

/* ---- Wawasan hari ini (hanya bulan berjalan) ---- */
const todayStat = computed(() => {
    if (!isCurrentMonth.value) return null;
    const day = Number(today().slice(8));
    const spent = props.daily[day - 1]?.expense ?? 0;
    const before = props.daily.slice(0, day - 1);
    const avg = before.length ? before.reduce((s, d) => s + d.expense, 0) / before.length : 0;
    return { spent, avg, diff: avg ? Math.round(((spent - avg) / avg) * 100) : null };
});

/* ---- Anggaran bulan berjalan (budget & spent per kategori dari server) ---- */
const budget = computed(() => {
    if (!isCurrentMonth.value) return null;
    const list = categories.value.filter((c) => c.type === 'expense' && c.budget);
    if (!list.length) return null;
    const total = list.reduce((s, c) => s + c.budget, 0);
    const spent = list.reduce((s, c) => s + c.spent, 0);
    const left = total - spent;
    const daysLeft = daysInMonth(props.month) - Number(today().slice(8)) + 1;
    return {
        total,
        spent,
        left,
        daysLeft,
        perDay: left > 0 ? Math.floor(left / daysLeft) : 0,
        ratio: spent / total,
        overCount: list.filter((c) => c.spent > c.budget).length,
    };
});

/* ---- Bilah mini saat saldo utama tergulir keluar layar (HP) ---- */
const hero = ref(null);
const heroVisible = ref(true);
useIntersectionObserver(hero, ([entry]) => (heroVisible.value = entry.isIntersecting), { rootMargin: '-56px 0px 0px 0px' });

const quickActions = [
    { type: 'expense', label: 'Keluar', icon: ArrowUpRight },
    { type: 'income', label: 'Masuk', icon: ArrowDownLeft },
    { type: 'transfer', label: 'Transfer', icon: ArrowRightLeft },
];

/** Latar kartu akun: gradasi warna identitas di atas permukaan. */
const accountBg = (key) => `linear-gradient(150deg, ${tint(key, 20)}, ${tint(key, 5)}), var(--surface)`;

function changeMonth(month) {
    router.get(route('dashboard'), month === currentMonth() ? {} : { month }, { preserveState: true, preserveScroll: true });
}

useShortcuts({
    arrowleft: () => changeMonth(shiftMonth(props.month, -1)),
    arrowright: () => props.month < currentMonth() && changeMonth(shiftMonth(props.month, 1)),
    h: togglePrivacy,
});
</script>

<template>
    <Head title="Beranda" />

    <!-- Bilah mini (HP) -->
    <Teleport to="body">
    <Transition name="minibar">
        <div v-if="!heroVisible" class="fixed inset-x-0 top-0 z-20 border-b border-line bg-paper/85 pt-safe backdrop-blur-xl md:hidden">
            <div class="flex h-12 items-center gap-3 px-4">
                <span class="text-[13px] text-muted">Total saldo</span>
                <Money :value="netWorth" class="flex-1 text-right text-[15px] font-semibold tnum" :class="netWorth < 0 && 'text-neg'" />
                <button type="button" class="icon-btn -mr-2 size-8" :aria-label="hidden ? 'Tampilkan nominal' : 'Sembunyikan nominal'" @click="togglePrivacy">
                    <EyeOff v-if="hidden" class="size-4" />
                    <Eye v-else class="size-4" />
                </button>
            </div>
        </div>
    </Transition>
    </Teleport>

    <!-- Bilah atas -->
    <header class="mb-5 flex items-center gap-3 md:mb-7">
        <Link
            :href="route('settings')"
            class="grid size-10 shrink-0 place-items-center rounded-full bg-accent-soft text-[14px] font-semibold text-accent-text ring-2 ring-surface transition active:scale-95"
            aria-label="Pengaturan"
        >
            {{ initials || 'S' }}
        </Link>
        <div class="min-w-0 flex-1">
            <p class="truncate text-[15px] leading-tight font-semibold">{{ greeting }}, {{ firstName }}</p>
            <p class="mt-0.5 text-[12px] text-muted first-letter:uppercase">{{ todayLong }}</p>
        </div>
        <Link :href="route('transactions.index')" class="icon-btn -mr-2 md:hidden" aria-label="Cari transaksi">
            <Search class="size-5" />
        </Link>
    </header>

    <!-- Kartu saldo utama -->
    <section ref="hero" class="hero-card mb-7 p-5 md:mb-9 md:p-7">
        <!-- Ornamen: cahaya + logo besar samar -->
        <span class="absolute -top-24 -right-20 -z-10 size-64 rounded-full bg-white/10 blur-3xl" aria-hidden="true" />
        <svg
            class="absolute -right-8 -bottom-14 -z-10 size-56 text-white opacity-[0.07] md:right-6 md:-bottom-20 md:size-72"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2.2"
            stroke-linecap="round"
            aria-hidden="true"
        >
            <path d="M6 8.5c0-1.4 1.6-2.5 6-2.5s6 1.1 6 2.5S16.4 11 12 12s-6 2.1-6 3.5S7.6 18 12 18s6-1.1 6-2.5" />
        </svg>

        <div class="flex flex-col gap-6 md:flex-row md:items-end md:justify-between md:gap-10">
            <div class="min-w-0">
                <div class="flex items-center gap-1.5">
                    <p class="text-[13px] font-medium text-(--hero-muted)">Total saldo</p>
                    <button
                        type="button"
                        class="grid size-7 place-items-center rounded-full text-(--hero-muted) transition-colors hover:bg-white/10 hover:text-(--hero-fg)"
                        :aria-pressed="hidden"
                        :aria-label="hidden ? 'Tampilkan nominal' : 'Sembunyikan nominal'"
                        :title="hidden ? 'Tampilkan nominal (H)' : 'Sembunyikan nominal (H)'"
                        @click="togglePrivacy"
                    >
                        <EyeOff v-if="hidden" class="size-4" />
                        <Eye v-else class="size-4" />
                    </button>
                </div>
                <p class="mt-1.5 text-[40px] leading-none font-semibold tracking-[-0.04em] md:text-[54px]" :class="netWorth < 0 && 'text-(--hero-neg)'">
                    <Money :value="netWorth" animate split-currency />
                </p>
                <div class="mt-4 flex flex-wrap items-center gap-2 text-[12px]">
                    <span
                        v-if="isCurrentMonth && net !== 0"
                        class="hero-glass inline-flex items-center gap-1 rounded-full px-2.5 py-1 font-semibold"
                        :class="net > 0 ? 'text-(--hero-pos)' : 'text-(--hero-neg)'"
                    >
                        <component :is="net > 0 ? ArrowUpRight : ArrowDownRight" class="size-3.5" :stroke-width="2.4" />
                        <Money :value="Math.abs(net)" />
                        <span class="font-normal text-(--hero-muted)">bulan ini</span>
                    </span>
                    <Link
                        :href="route('accounts.index')"
                        class="hero-glass rounded-full px-2.5 py-1 text-(--hero-muted) transition-colors hover:text-(--hero-fg)"
                    >
                        {{ activeAccounts.length }} akun
                    </Link>
                </div>
            </div>

            <!-- Aksi cepat -->
            <div class="grid grid-cols-3 gap-2 md:w-[330px] md:shrink-0">
                <button
                    v-for="action in quickActions"
                    :key="action.type"
                    type="button"
                    class="hero-glass flex flex-col items-center gap-1.5 rounded-2xl pt-3 pb-2.5 transition hover:bg-white/20 active:scale-[0.95]"
                    @click="openQuickAdd({ type: action.type })"
                >
                    <span class="grid size-9 place-items-center rounded-full bg-white/15">
                        <component :is="action.icon" class="size-[18px]" :stroke-width="2.2" />
                    </span>
                    <span class="text-[12px] font-medium">{{ action.label }}</span>
                </button>
            </div>
        </div>
    </section>

    <!-- Kartu akun yang bisa digeser (HP) -->
    <section class="mb-8 md:hidden">
        <div class="mb-3 flex items-baseline justify-between">
            <h2 class="section-title">Akun</h2>
            <Link :href="route('accounts.index')" class="text-[13px] font-medium text-accent-text">Kelola</Link>
        </div>
        <div class="no-scrollbar -mx-4 flex snap-x snap-mandatory scroll-px-4 gap-3 overflow-x-auto px-4 pt-1 pb-3">
            <Link
                v-for="account in activeAccounts"
                :key="account.id"
                :href="route('transactions.index', { account: account.id })"
                class="relative isolate flex w-[46%] min-w-[156px] shrink-0 snap-start flex-col justify-between gap-6 overflow-hidden rounded-[20px] border border-line p-4 shadow-card transition active:scale-[0.97]"
                :style="{ background: accountBg(account.color) }"
            >
                <span class="absolute -top-10 -right-10 -z-10 size-28 rounded-full" :style="{ background: tint(account.color, 16) }" aria-hidden="true" />
                <span class="flex items-center justify-between">
                    <span class="grid size-9 place-items-center rounded-full bg-surface/85 shadow-card">
                        <component :is="accountIcon(account.type)" class="size-[18px]" :style="{ color: color(account.color) }" :stroke-width="2" />
                    </span>
                    <span class="text-[10.5px] font-semibold tracking-wider text-ink-2/75 uppercase">{{ ACCOUNT_TYPES[account.type]?.label }}</span>
                </span>
                <span class="min-w-0">
                    <span class="block truncate text-[13px] text-ink-2">{{ account.name }}</span>
                    <Money
                        :value="account.balance"
                        animate
                        class="mt-0.5 block truncate text-[18px] font-semibold tracking-[-0.02em]"
                        :class="account.balance < 0 && 'text-neg'"
                    />
                </span>
            </Link>
            <Link
                :href="route('accounts.index')"
                class="flex w-[28%] min-w-[104px] shrink-0 snap-start flex-col items-center justify-center gap-2 rounded-[20px] border border-dashed border-line-strong text-[13px] text-muted transition active:scale-[0.97]"
            >
                <Plus class="size-5" />
                Akun
            </Link>
        </div>
    </section>

    <div class="grid gap-x-8 gap-y-6 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div class="flex min-w-0 flex-col gap-6">
            <DueRecurring v-if="isCurrentMonth && due.length" :items="due" />

            <!-- Arus kas bulan ini -->
            <section class="card p-4 md:p-5">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="section-title">Arus kas</h2>
                    <MonthSwitcher :month="month" class="-mr-2" @change="changeMonth" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="mb-2 flex items-center gap-2 text-[13px] text-muted">
                            <span class="grid size-6 place-items-center rounded-full bg-pos/12 text-pos">
                                <ArrowDownLeft class="size-3.5" :stroke-width="2.4" />
                            </span>
                            Masuk
                        </p>
                        <p class="text-[21px] font-semibold tracking-tight md:text-[24px]">
                            <Money :value="totals.income" animate />
                        </p>
                        <p v-if="incomeDelta !== null" class="mt-1 flex items-center gap-1 text-[12px] text-muted">
                            <component :is="incomeDelta >= 0 ? ArrowUpRight : ArrowDownRight" class="size-3.5" />
                            {{ Math.abs(incomeDelta) }}% vs {{ prevLabel }}
                        </p>
                    </div>
                    <div>
                        <p class="mb-2 flex items-center gap-2 text-[13px] text-muted">
                            <span class="grid size-6 place-items-center rounded-full bg-neg/12 text-neg">
                                <ArrowUpRight class="size-3.5" :stroke-width="2.4" />
                            </span>
                            Keluar
                        </p>
                        <p class="text-[21px] font-semibold tracking-tight md:text-[24px]">
                            <Money :value="totals.expense" animate />
                        </p>
                        <p
                            v-if="expenseDelta !== null"
                            class="mt-1 flex items-center gap-1 text-[12px]"
                            :class="expenseDelta > 0 ? 'text-neg' : 'text-pos'"
                        >
                            <component :is="expenseDelta >= 0 ? ArrowUpRight : ArrowDownRight" class="size-3.5" />
                            {{ Math.abs(expenseDelta) }}% vs {{ prevLabel }}
                        </p>
                    </div>
                </div>

                <div class="mt-5">
                    <div class="h-2 overflow-hidden rounded-full bg-sunken" role="presentation">
                        <div
                            class="h-full rounded-full transition-[width] duration-700 ease-out-soft"
                            :style="{
                                width: `${Math.min(100, (spentRatio ?? (totals.expense ? 1 : 0)) * 100)}%`,
                                background: spentRatio !== null && spentRatio <= 1 ? 'var(--bar)' : 'var(--neg)',
                            }"
                        />
                    </div>
                    <p class="mt-2 flex items-center justify-between gap-3 text-[12px] text-muted">
                        <span>{{ ratioText }}</span>
                        <span class="whitespace-nowrap">
                            Selisih <Money :value="net" sign class="font-medium" :class="net >= 0 ? 'text-pos' : 'text-neg'" />
                        </span>
                    </p>
                </div>
            </section>

            <!-- Wawasan hari ini -->
            <div v-if="todayStat" class="-mt-2 flex items-center gap-3 rounded-2xl border border-accent/10 bg-accent-soft/70 px-4 py-3">
                <span class="grid size-8 shrink-0 place-items-center rounded-full bg-surface text-accent-text shadow-card">
                    <Sun class="size-4" :stroke-width="2" />
                </span>
                <p class="text-[13px] leading-snug text-ink-2">
                    <template v-if="todayStat.spent">
                        Hari ini keluar <span class="amount font-semibold text-ink">{{ rupiah(todayStat.spent) }}</span>
                        <template v-if="todayStat.diff !== null">
                            ·
                            <span class="whitespace-nowrap" :class="todayStat.diff > 0 ? 'text-neg' : 'text-pos'">
                                {{ Math.abs(todayStat.diff) }}% {{ todayStat.diff > 0 ? 'di atas' : 'di bawah' }} rata-rata
                            </span>
                        </template>
                    </template>
                    <template v-else>Belum ada pengeluaran hari ini. Pertahankan!</template>
                </p>
            </div>

            <!-- Anggaran -->
            <section v-if="budget" class="card p-4 md:p-5">
                <div class="mb-3 flex items-baseline justify-between">
                    <h2 class="section-title">Anggaran</h2>
                    <Link :href="route('categories.index')" class="text-[13px] text-accent-text hover:underline">Atur</Link>
                </div>
                <template v-if="budget.left > 0">
                    <p class="text-[13px] text-muted">Jatah harian</p>
                    <p class="mt-0.5 text-[24px] font-semibold tracking-tight">
                        <Money :value="budget.perDay" animate /><span class="text-[15px] font-medium text-muted"> /hari</span>
                    </p>
                    <p class="mt-1 text-[12px] text-muted">
                        Sisa <Money :value="budget.left" class="font-medium text-ink-2" /> untuk {{ budget.daysLeft }} hari lagi
                    </p>
                </template>
                <template v-else>
                    <p class="text-[13px] text-muted">Anggaran terlampaui</p>
                    <p class="mt-0.5 text-[24px] font-semibold tracking-tight text-neg"><Money :value="-budget.left" /></p>
                    <p class="mt-1 text-[12px] text-muted">di atas total anggaran bulan ini</p>
                </template>
                <div class="mt-4 h-2 overflow-hidden rounded-full bg-sunken" role="presentation">
                    <div
                        class="h-full rounded-full transition-[width] duration-700 ease-out-soft"
                        :class="budget.ratio > 1 ? 'bg-neg' : budget.ratio >= 0.85 ? 'bg-warn' : 'bg-bar'"
                        :style="{ width: `${Math.min(100, budget.ratio * 100)}%` }"
                    />
                </div>
                <p class="mt-2 flex items-center justify-between gap-3 text-[12px] text-muted">
                    <span>Terpakai <Money :value="budget.spent" /> dari <Money :value="budget.total" /></span>
                    <span v-if="budget.overCount" class="whitespace-nowrap text-neg">{{ budget.overCount }} kategori lewat</span>
                </p>
            </section>

            <div class="card p-4 md:p-5">
                <DailyChart :month="month" :daily="daily" />
            </div>

            <!-- Terakhir -->
            <section>
                <div class="mb-2 flex items-baseline justify-between">
                    <h2 class="section-title">Terakhir</h2>
                    <Link
                        :href="route('transactions.index', isCurrentMonth ? {} : { month })"
                        class="text-[13px] text-accent-text hover:underline"
                    >
                        Lihat semua
                    </Link>
                </div>
                <TransactionGroups v-if="recent.length" :transactions="recent" :sticky="false" />
                <div v-else class="card px-4 py-10 text-center">
                    <span class="mx-auto mb-3 grid size-12 place-items-center rounded-full bg-accent-soft text-accent-text">
                        <Plus class="size-5" />
                    </span>
                    <p class="mb-4 text-sm text-muted">Belum ada catatan di bulan ini.</p>
                    <button type="button" class="btn btn-quiet" @click="openQuickAdd()">Catat transaksi pertama</button>
                </div>
            </section>
        </div>

        <aside class="flex min-w-0 flex-col gap-6">
            <CategoryBreakdown class="card p-4 md:p-5" :month="month" :items="byCategory" :limit="5" />

            <section class="card hidden p-5 md:block">
                <div class="mb-3 flex items-baseline justify-between">
                    <h2 class="section-title">Akun</h2>
                    <Link :href="route('accounts.index')" class="text-[13px] text-accent-text hover:underline">Kelola</Link>
                </div>
                <ul class="-mx-2">
                    <li v-for="account in activeAccounts" :key="account.id">
                        <Link
                            :href="route('transactions.index', { account: account.id })"
                            class="flex items-center gap-3 rounded-xl px-2 py-2 transition-colors hover:bg-sunken/70"
                        >
                            <IconTile :icon="accountIcon(account.type)" :color="account.color" size="sm" />
                            <span class="flex-1 truncate text-[14px]">{{ account.name }}</span>
                            <Money :value="account.balance" class="text-[14px] font-medium tnum" :class="account.balance < 0 && 'text-neg'" />
                        </Link>
                    </li>
                </ul>
            </section>
        </aside>
    </div>
</template>

<style scoped>
.minibar-enter-active,
.minibar-leave-active {
    transition:
        transform 0.3s var(--ease-sheet),
        opacity 0.2s ease;
}
.minibar-enter-from,
.minibar-leave-to {
    transform: translateY(-100%);
    opacity: 0;
}
</style>
