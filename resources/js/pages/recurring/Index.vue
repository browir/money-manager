<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowRight, ArrowRightLeft, CalendarClock, Plus, Repeat, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import { route } from 'ziggy-js';
import CalendarPicker from '@/components/ui/CalendarPicker.vue';
import AmountField from '@/components/ui/AmountField.vue';
import IconTile from '@/components/ui/IconTile.vue';
import Money from '@/components/ui/Money.vue';
import Segmented from '@/components/ui/Segmented.vue';
import Sheet from '@/components/ui/Sheet.vue';
import { useLedger } from '@/composables/useLedger';
import { addDays, dayLabel, fromIso, toIso, today } from '@/lib/dates';
import { accountIcon, categoryIcon } from '@/lib/icons';
import { color, tint } from '@/lib/palette';
import { FREQUENCIES, daysUntil, dueLabel, frequencyLabel } from '@/lib/recurring';

const props = defineProps({
    recurrings: Array,
    prefill: Object, // transaksi sumber dari "Jadikan berulang"
});

const { activeAccounts, accountById, categories, categoryById } = useLedger();

const TYPES = [
    { value: 'expense', label: 'Pengeluaran' },
    { value: 'income', label: 'Pemasukan' },
    { value: 'transfer', label: 'Transfer' },
];

/* ---- Daftar ---- */
function title(r) {
    if (r.note) return r.note;
    if (r.type === 'transfer') return 'Transfer';
    return categoryById.value[r.category_id]?.name ?? 'Tanpa kategori';
}
function subtitle(r) {
    const from = accountById.value[r.account_id]?.name;
    if (r.type === 'transfer') return `${frequencyLabel(r)} · ${from} → ${accountById.value[r.to_account_id]?.name ?? '?'}`;
    return [frequencyLabel(r), from].filter(Boolean).join(' · ');
}
const signed = (r) => (r.type === 'expense' ? -r.amount : r.amount);

/** Perkiraan beban bulanan: mingguan ×52/12, tahunan ÷12. */
const monthly = computed(() =>
    props.recurrings.reduce(
        (acc, r) => {
            const per = r.frequency === 'weekly' ? (r.amount * 52) / 12 : r.frequency === 'yearly' ? r.amount / 12 : r.amount;
            if (r.type === 'income') acc.income += per;
            if (r.type === 'expense') acc.expense += per;
            return acc;
        },
        { income: 0, expense: 0 },
    ),
);

/* ---- Form ---- */
const sheetOpen = ref(false);
const editing = ref(null);
const confirmDelete = ref(false);
const showCalendar = ref(false);
const form = useForm({
    type: 'expense',
    amount: 0,
    account_id: null,
    to_account_id: null,
    category_id: null,
    note: '',
    frequency: 'monthly',
    next_due: today(),
});

const typeCategories = computed(() =>
    categories.value.filter((c) => c.type === (form.type === 'income' ? 'income' : 'expense')),
);
const targetAccounts = computed(() => activeAccounts.value.filter((a) => a.id !== form.account_id));

/** Tanggal yang sama bulan depan (dipotong ke akhir bulan). */
function nextMonth(iso) {
    const d = fromIso(iso);
    const target = new Date(d.getFullYear(), d.getMonth() + 1, 1);
    const last = new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate();
    target.setDate(Math.min(d.getDate(), last));
    return toIso(target);
}

function edit(r = null, source = null) {
    const s = r ?? source;
    editing.value = r;
    confirmDelete.value = false;
    showCalendar.value = false;
    form.clearErrors();
    form.type = s?.type ?? 'expense';
    form.amount = s?.amount ?? 0;
    form.account_id = s?.account_id ?? activeAccounts.value[0]?.id ?? null;
    form.to_account_id = s?.to_account_id ?? null;
    form.category_id = s?.category_id ?? null;
    form.note = s?.note ?? '';
    form.frequency = r?.frequency ?? 'monthly';
    form.next_due = r?.next_due ?? (source ? nextMonth(source.occurred_on) : today());
    if (form.type === 'transfer' && !form.to_account_id) form.to_account_id = targetAccounts.value[0]?.id ?? null;
    sheetOpen.value = true;
}

watch(
    () => form.type,
    (type, old) => {
        if (!old || type === old) return;
        if (!(editing.value && editing.value.type === type)) form.category_id = null;
        if (type === 'transfer' && !form.to_account_id) form.to_account_id = targetAccounts.value[0]?.id ?? null;
    },
);
watch(
    () => form.account_id,
    (id) => {
        if (form.to_account_id === id) form.to_account_id = targetAccounts.value[0]?.id ?? null;
    },
);

function save() {
    const options = { preserveScroll: true, onSuccess: () => (sheetOpen.value = false) };
    if (editing.value) form.put(route('recurring.update', editing.value.id), options);
    else form.post(route('recurring.store'), options);
}

function destroy() {
    if (!confirmDelete.value) {
        confirmDelete.value = true;
        return;
    }
    router.delete(route('recurring.destroy', editing.value.id), {
        preserveScroll: true,
        onSuccess: () => (sheetOpen.value = false),
    });
}

onMounted(() => {
    if (!props.prefill) return;
    edit(null, props.prefill);
    // Buang ?dari= agar muat ulang tidak membuka form lagi.
    const url = new URL(window.location.href);
    url.searchParams.delete('dari');
    history.replaceState(history.state, '', url);
});
</script>

<template>
    <Head title="Berulang" />

    <header class="mb-6 flex items-end justify-between gap-4">
        <div>
            <h1 class="text-[26px] font-semibold tracking-[-0.025em] md:text-[30px]">Berulang</h1>
            <p class="mt-1 text-[13px] text-muted">Gaji, tagihan, dan langganan. Muncul di beranda saat jatuh tempo.</p>
        </div>
        <button type="button" class="btn btn-primary shrink-0" @click="edit()"><Plus class="size-4" /> Jadwal</button>
    </header>

    <p v-if="recurrings.length" class="mb-4 flex flex-wrap gap-x-4 gap-y-1 text-[13px] text-muted">
        <span>Per bulan ±</span>
        <span v-if="monthly.income">Masuk <Money :value="Math.round(monthly.income)" sign class="text-pos tnum" /></span>
        <span v-if="monthly.expense">Keluar <Money :value="-Math.round(monthly.expense)" class="text-ink-2 tnum" /></span>
    </p>

    <TransitionGroup v-if="recurrings.length" tag="ul" name="list" class="relative flex flex-col">
        <li v-for="r in recurrings" :key="r.id" class="border-b border-line">
            <button type="button" class="flex w-full items-center gap-3 py-3 text-left transition-opacity hover:opacity-80" @click="edit(r)">
                <IconTile v-if="r.type === 'transfer'" :icon="ArrowRightLeft" color="slate" />
                <IconTile v-else :icon="categoryIcon(categoryById[r.category_id]?.icon)" :color="categoryById[r.category_id]?.color ?? 'slate'" />
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[15px] leading-tight font-medium">{{ title(r) }}</span>
                    <span class="mt-1 block truncate text-[13px] leading-tight text-muted">{{ subtitle(r) }}</span>
                </span>
                <span class="text-right">
                    <Money
                        :value="signed(r)"
                        :sign="r.type === 'income'"
                        class="block text-[15px] font-medium tnum"
                        :class="{ 'text-pos': r.type === 'income', 'text-ink-2': r.type === 'transfer' }"
                    />
                    <span class="mt-0.5 block text-[12px]" :class="daysUntil(r.next_due) <= 0 ? 'font-medium text-accent-text' : 'text-muted'">
                        {{ dueLabel(r.next_due) }}
                    </span>
                </span>
            </button>
        </li>
    </TransitionGroup>

    <div v-else class="rounded-2xl border border-dashed border-line px-4 py-14 text-center">
        <Repeat class="mx-auto mb-3 size-6 text-muted" />
        <p class="mb-1 text-[15px] font-medium">Belum ada jadwal</p>
        <p class="mb-5 text-sm text-muted">Atur sekali, lalu catat dengan satu ketukan setiap jatuh tempo.</p>
        <button type="button" class="btn btn-quiet" @click="edit()">Buat jadwal</button>
    </div>

    <Sheet :open="sheetOpen" :title="editing ? 'Ubah jadwal' : 'Jadwal baru'" @close="sheetOpen = false">
        <template #header>
            <Segmented v-model="form.type" :options="TYPES" class="w-full" />
        </template>

        <form id="recurring-form" class="flex flex-col gap-5 pb-2" @submit.prevent="save">
            <div>
                <span class="field-label">Nominal</span>
                <AmountField v-model="form.amount" />
                <p v-if="form.errors.amount" class="field-error">{{ form.errors.amount }}</p>
            </div>

            <section>
                <h3 class="eyebrow mb-2">{{ form.type === 'transfer' ? 'Dari akun' : 'Akun' }}</h3>
                <div class="no-scrollbar -mx-5 flex gap-2 overflow-x-auto px-5">
                    <button
                        v-for="account in activeAccounts"
                        :key="account.id"
                        type="button"
                        class="chip"
                        :aria-pressed="form.account_id === account.id"
                        @click="form.account_id = account.id"
                    >
                        <component :is="accountIcon(account.type)" class="size-4" :stroke-width="1.9" />
                        {{ account.name }}
                    </button>
                </div>
                <p v-if="form.errors.account_id" class="field-error">{{ form.errors.account_id }}</p>
            </section>

            <section v-if="form.type === 'transfer'">
                <h3 class="eyebrow mb-2 flex items-center gap-1.5">Ke akun <ArrowRight class="size-3" /></h3>
                <div class="no-scrollbar -mx-5 flex gap-2 overflow-x-auto px-5">
                    <button
                        v-for="account in targetAccounts"
                        :key="account.id"
                        type="button"
                        class="chip"
                        :aria-pressed="form.to_account_id === account.id"
                        @click="form.to_account_id = account.id"
                    >
                        <component :is="accountIcon(account.type)" class="size-4" :stroke-width="1.9" />
                        {{ account.name }}
                    </button>
                </div>
                <p v-if="form.errors.to_account_id" class="field-error">{{ form.errors.to_account_id }}</p>
            </section>

            <section v-else>
                <h3 class="eyebrow mb-2">Kategori</h3>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="category in typeCategories"
                        :key="category.id"
                        type="button"
                        class="chip pl-1.5"
                        :style="
                            form.category_id === category.id
                                ? { background: tint(category.color, 16), borderColor: color(category.color), color: 'var(--ink)' }
                                : undefined
                        "
                        @click="form.category_id = form.category_id === category.id ? null : category.id"
                    >
                        <span
                            class="inline-flex size-6 items-center justify-center rounded-full"
                            :style="{ background: tint(category.color, 18), color: color(category.color) }"
                        >
                            <component :is="categoryIcon(category.icon)" class="size-3.5" :stroke-width="2" />
                        </span>
                        {{ category.name }}
                    </button>
                </div>
            </section>

            <div>
                <span class="field-label">Catatan</span>
                <input v-model="form.note" class="field" maxlength="160" placeholder="Misal: Gaji, Kos, Netflix" />
                <p v-if="form.errors.note" class="field-error">{{ form.errors.note }}</p>
            </div>

            <div>
                <span class="field-label">Ulangi</span>
                <Segmented v-model="form.frequency" :options="FREQUENCIES" />
            </div>

            <div>
                <span class="field-label">Jatuh tempo berikutnya</span>
                <button type="button" class="chip" :aria-expanded="showCalendar" @click="showCalendar = !showCalendar">
                    <CalendarClock class="size-4" :stroke-width="1.9" />
                    {{ dayLabel(form.next_due) }}
                </button>
                <CalendarPicker
                    v-if="showCalendar"
                    v-model="form.next_due"
                    :max="addDays(today(), 730)"
                    class="mt-3"
                    @picked="showCalendar = false"
                />
                <p class="mt-1.5 text-[12px] text-muted">Tanggal ini juga menjadi patokan periode berikutnya.</p>
                <p v-if="form.errors.next_due" class="field-error">{{ form.errors.next_due }}</p>
            </div>
        </form>

        <template #footer>
            <div class="flex items-center gap-2">
                <button
                    v-if="editing"
                    type="button"
                    class="btn btn-danger"
                    :class="confirmDelete ? 'bg-neg text-white hover:bg-neg' : 'px-3'"
                    @click="destroy"
                >
                    <Trash2 class="size-4" />
                    <span v-if="confirmDelete">Yakin hapus?</span>
                </button>
                <button type="submit" form="recurring-form" class="btn btn-primary h-12 flex-1" :disabled="form.processing">
                    {{ editing ? 'Simpan perubahan' : 'Buat jadwal' }}
                </button>
            </div>
            <p v-if="confirmDelete" class="mt-2 text-[12px] text-muted">Transaksi yang sudah tercatat tidak ikut terhapus.</p>
        </template>
    </Sheet>
</template>
