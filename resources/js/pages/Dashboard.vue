<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowDownRight, ArrowUpRight, ChevronRight, Search } from 'lucide-vue-next';
import { computed } from 'vue';
import { route } from 'ziggy-js';
import CategoryBreakdown from '@/components/CategoryBreakdown.vue';
import DailyChart from '@/components/DailyChart.vue';
import TransactionGroups from '@/components/TransactionGroups.vue';
import IconTile from '@/components/ui/IconTile.vue';
import Money from '@/components/ui/Money.vue';
import MonthSwitcher from '@/components/ui/MonthSwitcher.vue';
import { openQuickAdd } from '@/composables/useQuickAdd';
import { useLedger } from '@/composables/useLedger';
import { useShortcuts } from '@/composables/useShortcuts';
import { currentMonth, monthLabel, shiftMonth } from '@/lib/dates';
import { accountIcon } from '@/lib/icons';

const props = defineProps({
    month: String,
    totals: Object,
    byCategory: Array,
    daily: Array,
    recent: Array,
});

const page = usePage();
const { activeAccounts, netWorth } = useLedger();

const firstName = computed(() => page.props.auth.user?.name?.split(' ')[0]);
const greeting = computed(() => {
    const h = new Date().getHours();
    if (h < 11) return 'Selamat pagi';
    if (h < 15) return 'Selamat siang';
    if (h < 19) return 'Selamat sore';
    return 'Selamat malam';
});

const net = computed(() => props.totals.income - props.totals.expense);

/** Perubahan vs bulan lalu, dalam persen bulat. null kalau tidak ada pembanding. */
function delta(now, before) {
    if (!before) return null;
    return Math.round(((now - before) / before) * 100);
}
const incomeDelta = computed(() => delta(props.totals.income, props.totals.prevIncome));
const expenseDelta = computed(() => delta(props.totals.expense, props.totals.prevExpense));
const prevLabel = computed(() => monthLabel(shiftMonth(props.month, -1), { withYear: false }).slice(0, 3));

function changeMonth(month) {
    router.get(route('dashboard'), month === currentMonth() ? {} : { month }, { preserveState: true, preserveScroll: true });
}

useShortcuts({
    arrowleft: () => changeMonth(shiftMonth(props.month, -1)),
    arrowright: () => props.month < currentMonth() && changeMonth(shiftMonth(props.month, 1)),
});
</script>

<template>
    <Head title="Beranda" />

    <!-- Kepala -->
    <header class="mb-8 flex items-start justify-between gap-4 md:mb-10">
        <div>
            <p class="mb-3 text-[14px] text-muted">{{ greeting }}, {{ firstName }}</p>
            <p class="eyebrow mb-1.5">Total saldo</p>
            <p class="text-[42px] leading-none font-semibold tracking-[-0.035em] md:text-[56px]" :class="netWorth < 0 && 'text-neg'">
                <Money :value="netWorth" animate split-currency />
            </p>
            <Link
                :href="route('accounts.index')"
                class="mt-3 inline-flex items-center gap-1 text-[13px] text-muted transition-colors hover:text-ink"
            >
                di {{ activeAccounts.length }} akun <ChevronRight class="size-3.5" />
            </Link>
        </div>
        <Link :href="route('transactions.index')" class="icon-btn md:hidden" aria-label="Cari transaksi">
            <Search class="size-5" />
        </Link>
    </header>

    <div class="grid gap-x-12 gap-y-10 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div class="flex min-w-0 flex-col gap-10">
            <!-- Ringkasan bulan -->
            <section>
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-[15px] font-medium">Arus kas</h2>
                    <MonthSwitcher :month="month" class="-mr-2" @change="changeMonth" />
                </div>

                <div class="grid grid-cols-2 divide-x divide-line rounded-2xl border border-line bg-surface">
                    <div class="p-4 md:p-5">
                        <p class="mb-2 flex items-center gap-1.5 text-[13px] text-muted">
                            <span class="size-1.5 rounded-full bg-pos" /> Masuk
                        </p>
                        <p class="text-[20px] font-semibold tracking-tight md:text-[24px]">
                            <Money :value="totals.income" animate />
                        </p>
                        <p v-if="incomeDelta !== null" class="mt-1.5 flex items-center gap-1 text-[12px] text-muted">
                            <component :is="incomeDelta >= 0 ? ArrowUpRight : ArrowDownRight" class="size-3.5" />
                            {{ Math.abs(incomeDelta) }}% vs {{ prevLabel }}
                        </p>
                    </div>
                    <div class="p-4 md:p-5">
                        <p class="mb-2 flex items-center gap-1.5 text-[13px] text-muted">
                            <span class="size-1.5 rounded-full bg-ink" /> Keluar
                        </p>
                        <p class="text-[20px] font-semibold tracking-tight md:text-[24px]">
                            <Money :value="totals.expense" animate />
                        </p>
                        <p
                            v-if="expenseDelta !== null"
                            class="mt-1.5 flex items-center gap-1 text-[12px]"
                            :class="expenseDelta > 0 ? 'text-neg' : 'text-pos'"
                        >
                            <component :is="expenseDelta >= 0 ? ArrowUpRight : ArrowDownRight" class="size-3.5" />
                            {{ Math.abs(expenseDelta) }}% vs {{ prevLabel }}
                        </p>
                    </div>
                </div>
                <p class="mt-3 px-1 text-[13px] text-muted">
                    Selisih bulan ini
                    <Money :value="net" sign class="font-medium tnum" :class="net >= 0 ? 'text-pos' : 'text-neg'" />
                </p>
            </section>

            <DailyChart :month="month" :daily="daily" />

            <!-- Terakhir -->
            <section>
                <div class="mb-2 flex items-baseline justify-between">
                    <h2 class="text-[15px] font-medium">Terakhir</h2>
                    <Link
                        :href="route('transactions.index', month === currentMonth() ? {} : { month })"
                        class="text-[13px] text-accent-text hover:underline"
                    >
                        Lihat semua
                    </Link>
                </div>
                <TransactionGroups v-if="recent.length" :transactions="recent" />
                <div v-else class="rounded-2xl border border-dashed border-line px-4 py-10 text-center">
                    <p class="mb-4 text-sm text-muted">Belum ada catatan di bulan ini.</p>
                    <button type="button" class="btn btn-quiet" @click="openQuickAdd()">Catat transaksi pertama</button>
                </div>
            </section>
        </div>

        <aside class="flex min-w-0 flex-col gap-10">
            <CategoryBreakdown :month="month" :items="byCategory" />

            <section>
                <div class="mb-3 flex items-baseline justify-between">
                    <h2 class="text-[15px] font-medium">Akun</h2>
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
                            <Money
                                :value="account.balance"
                                class="text-[14px] font-medium tnum"
                                :class="account.balance < 0 && 'text-neg'"
                            />
                        </Link>
                    </li>
                </ul>
            </section>
        </aside>
    </div>
</template>
