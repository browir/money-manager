<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRightLeft, ChevronDown, Plus, Scale, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { route } from 'ziggy-js';
import AmountField from '@/components/ui/AmountField.vue';
import ColorPicker from '@/components/ui/ColorPicker.vue';
import IconTile from '@/components/ui/IconTile.vue';
import Money from '@/components/ui/Money.vue';
import Segmented from '@/components/ui/Segmented.vue';
import Sheet from '@/components/ui/Sheet.vue';
import { openQuickAdd } from '@/composables/useQuickAdd';
import { useLedger } from '@/composables/useLedger';
import { ACCOUNT_TYPES, accountIcon } from '@/lib/icons';
import { color, tint } from '@/lib/palette';

const { accounts, activeAccounts, netWorth } = useLedger();
const archived = computed(() => accounts.value.filter((a) => a.archived));
const showArchived = ref(false);

const TYPE_OPTIONS = Object.entries(ACCOUNT_TYPES).map(([value, { label }]) => ({ value, label }));

const sheetOpen = ref(false);
const editing = ref(null);
const mode = ref('edit'); // 'edit' | 'adjust'
const form = useForm({ name: '', type: 'bank', color: 'ink', initial_balance: 0, archived: false });

/* ---- Sesuaikan saldo: cocokkan ke saldo sebenarnya, selisih dicatat sebagai transaksi ---- */
const adjustForm = useForm({ balance: 0, note: '' });
// Ambil saldo terbaru dari data bersama (bisa berubah setelah transaksi lain disimpan).
const current = computed(() => accounts.value.find((a) => a.id === editing.value?.id)?.balance ?? 0);
const diff = computed(() => adjustForm.balance - current.value);

function startAdjust() {
    adjustForm.clearErrors();
    adjustForm.balance = current.value;
    adjustForm.note = '';
    mode.value = 'adjust';
}

function saveAdjust() {
    adjustForm.post(route('accounts.adjust', editing.value.id), {
        preserveScroll: true,
        onSuccess: () => (sheetOpen.value = false),
    });
}

const sheetTitle = computed(() => {
    if (mode.value === 'adjust') return 'Sesuaikan saldo';
    return editing.value ? 'Ubah akun' : 'Akun baru';
});

function edit(account = null, startIn = 'edit') {
    editing.value = account;
    mode.value = 'edit';
    form.clearErrors();
    form.name = account?.name ?? '';
    form.type = account?.type ?? 'bank';
    form.color = account?.color ?? 'ink';
    form.initial_balance = account?.initial_balance ?? 0;
    form.archived = account?.archived ?? false;
    if (account && startIn === 'adjust') startAdjust();
    sheetOpen.value = true;
}

function save() {
    const options = { preserveScroll: true, onSuccess: () => (sheetOpen.value = false) };
    if (editing.value) form.put(route('accounts.update', editing.value.id), options);
    else form.post(route('accounts.store'), options);
}

function destroy() {
    router.delete(route('accounts.destroy', editing.value.id), {
        preserveScroll: true,
        onSuccess: () => (sheetOpen.value = false),
    });
}
</script>

<template>
    <Head title="Akun" />

    <header class="mb-5 flex items-center justify-between gap-4">
        <h1 class="page-title">Akun</h1>
        <button type="button" class="btn btn-primary" @click="edit()"><Plus class="size-4" /> Akun</button>
    </header>

    <section class="hero-card mb-6 p-5 md:p-7">
        <span class="absolute -top-24 -right-20 -z-10 size-64 rounded-full bg-white/10 blur-3xl" aria-hidden="true" />
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-[13px] font-medium text-(--hero-muted)">Total saldo · {{ activeAccounts.length }} akun</p>
                <p class="mt-1.5 text-[34px] leading-none font-semibold tracking-[-0.035em] md:text-[42px]" :class="netWorth < 0 && 'text-(--hero-neg)'">
                    <Money :value="netWorth" animate />
                </p>
            </div>
            <button
                v-if="activeAccounts.length > 1"
                type="button"
                class="hero-glass inline-flex h-10 items-center gap-2 rounded-xl px-4 text-sm font-medium transition hover:bg-white/20 active:scale-[0.97]"
                @click="openQuickAdd({ type: 'transfer' })"
            >
                <ArrowRightLeft class="size-4" /> Transfer antar akun
            </button>
        </div>
        <!-- Porsi saldo per akun -->
        <div v-if="netWorth > 0" class="mt-5 flex h-2 gap-[3px] overflow-hidden rounded-full bg-white/10" aria-hidden="true">
            <span
                v-for="account in activeAccounts.filter((a) => a.balance > 0)"
                :key="account.id"
                class="h-full first:rounded-l-full last:rounded-r-full"
                :style="{ flexGrow: account.balance, flexBasis: 0, background: color(account.color) }"
            />
        </div>
    </section>

    <TransitionGroup tag="ul" name="list" class="relative grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <li v-for="account in activeAccounts" :key="account.id">
            <button
                type="button"
                class="card card-hover relative isolate flex w-full flex-col gap-7 overflow-hidden p-4 text-left md:p-5"
                :style="{ background: `linear-gradient(150deg, ${tint(account.color, 16)}, ${tint(account.color, 3)} 60%), var(--surface)` }"
                @click="edit(account)"
            >
                <span class="absolute -top-12 -right-12 -z-10 size-32 rounded-full" :style="{ background: tint(account.color, 14) }" aria-hidden="true" />
                <span class="flex w-full items-center gap-3">
                    <span class="grid size-10 place-items-center rounded-full bg-surface/85 shadow-card">
                        <component :is="accountIcon(account.type)" class="size-[18px]" :style="{ color: color(account.color) }" :stroke-width="2" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[15px] font-semibold">{{ account.name }}</span>
                        <span class="block text-small text-muted">{{ ACCOUNT_TYPES[account.type]?.label }}</span>
                    </span>
                </span>
                <span>
                    <span class="block text-small text-muted">Saldo</span>
                    <Money
                        :value="account.balance"
                        animate
                        class="text-[24px] font-semibold tracking-[-0.025em]"
                        :class="account.balance < 0 && 'text-neg'"
                    />
                </span>
            </button>
        </li>
    </TransitionGroup>

    <section v-if="archived.length" class="mt-10">
        <button type="button" class="mb-3 flex items-center gap-1.5 text-[13px] text-muted hover:text-ink" @click="showArchived = !showArchived">
            <ChevronDown class="size-4 transition-transform" :class="!showArchived && '-rotate-90'" />
            Diarsipkan ({{ archived.length }})
        </button>
        <ul v-if="showArchived" class="card divide-y divide-line px-4">
            <li v-for="account in archived" :key="account.id">
                <button type="button" class="flex w-full items-center gap-3 py-3 text-left opacity-70 hover:opacity-100" @click="edit(account)">
                    <IconTile :icon="accountIcon(account.type)" :color="account.color" size="sm" />
                    <span class="flex-1 truncate text-sm">{{ account.name }}</span>
                    <Money :value="account.balance" class="text-sm tnum" />
                </button>
            </li>
        </ul>
    </section>

    <Sheet :open="sheetOpen" :title="sheetTitle" @close="sheetOpen = false">
        <!-- Mode: sesuaikan saldo -->
        <form v-if="mode === 'adjust'" id="adjust-form" class="flex flex-col gap-5 pb-2" @submit.prevent="saveAdjust">
            <div class="flex items-center gap-3 rounded-2xl bg-sunken px-4 py-3">
                <IconTile :icon="accountIcon(editing.type)" :color="editing.color" />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-[15px] font-medium">{{ editing.name }}</p>
                    <p class="text-small text-muted">Saldo tercatat <Money :value="current" class="font-medium text-ink-2" /></p>
                </div>
            </div>

            <div>
                <label class="field-label">Saldo sebenarnya saat ini</label>
                <AmountField v-model="adjustForm.balance" />
                <p v-if="adjustForm.errors.balance" class="field-error">{{ adjustForm.errors.balance }}</p>
                <p class="mt-1.5 text-small text-muted">Lihat di aplikasi bank, e-wallet, atau hitung uang tunai Anda.</p>
            </div>

            <!-- Pratinjau selisih -->
            <div
                class="flex items-center gap-3 rounded-2xl border px-4 py-3 transition-colors"
                :class="diff === 0 ? 'border-line' : diff > 0 ? 'border-pos/30 bg-pos/8' : 'border-neg/30 bg-neg/8'"
            >
                <Scale class="size-5 shrink-0" :class="diff === 0 ? 'text-muted' : diff > 0 ? 'text-pos' : 'text-neg'" />
                <p class="text-[13px] leading-snug text-ink-2">
                    <template v-if="diff === 0">Sama dengan saldo tercatat, tidak ada yang perlu disesuaikan.</template>
                    <template v-else>
                        Selisih <Money :value="diff" sign class="font-semibold" :class="diff > 0 ? 'text-pos' : 'text-neg'" />
                        akan dicatat hari ini sebagai <span class="font-medium text-ink">Penyesuaian saldo</span>.
                    </template>
                </p>
            </div>

            <div>
                <label class="field-label" for="adjust-note">Catatan <span class="font-normal text-muted">(opsional)</span></label>
                <input id="adjust-note" v-model="adjustForm.note" class="field" maxlength="160" placeholder="Misal: biaya admin bank, uang receh hilang" />
            </div>

            <p class="text-small leading-relaxed text-muted">
                Tidak dihitung sebagai pemasukan atau pengeluaran, jadi arus kas dan anggaran tetap akurat. Saldo awal dan riwayat
                transaksi tidak berubah.
            </p>
        </form>

        <!-- Mode: ubah / tambah akun -->
        <form v-else id="account-form" class="flex flex-col gap-5 pb-2" @submit.prevent="save">
            <!-- Saldo saat ini + aksi sesuaikan (hanya untuk akun yang sudah ada) -->
            <div v-if="editing" class="flex items-center gap-3 rounded-2xl border border-line bg-sunken/60 px-4 py-3">
                <div class="min-w-0 flex-1">
                    <p class="text-small text-muted">Saldo saat ini</p>
                    <Money :value="current" class="text-[20px] font-semibold tracking-tight" :class="current < 0 && 'text-neg'" />
                </div>
                <button type="button" class="btn btn-primary h-9 shrink-0 px-3.5 text-[13px]" @click="startAdjust">
                    <Scale class="size-4" /> Sesuaikan saldo
                </button>
            </div>

            <div>
                <label class="field-label" for="account-name">Nama</label>
                <input id="account-name" v-model="form.name" data-autofocus class="field" maxlength="60" placeholder="Misal: BCA, Dompet, GoPay" />
                <p v-if="form.errors.name" class="field-error">{{ form.errors.name }}</p>
            </div>
            <div>
                <span class="field-label">Jenis</span>
                <Segmented v-model="form.type" :options="TYPE_OPTIONS" />
            </div>
            <div>
                <span class="field-label">Warna</span>
                <ColorPicker v-model="form.color" />
            </div>
            <div>
                <label class="field-label">Saldo awal</label>
                <AmountField v-model="form.initial_balance" />
                <p class="mt-1.5 text-small text-muted">
                    Saldo sebelum transaksi pertama dicatat di Sisih.
                    <template v-if="editing">Mengubahnya menggeser seluruh riwayat. Untuk mencocokkan saldo hari ini, pakai <span class="font-medium text-ink-2">Sesuaikan saldo</span>.</template>
                </p>
                <p v-if="form.errors.initial_balance" class="field-error">{{ form.errors.initial_balance }}</p>
            </div>
            <label v-if="editing" class="flex cursor-pointer items-center justify-between gap-4 rounded-xl bg-sunken px-4 py-3">
                <span>
                    <span class="block text-sm font-medium">Arsipkan</span>
                    <span class="block text-small text-muted">Disembunyikan dari pilihan, riwayat tetap ada.</span>
                </span>
                <input v-model="form.archived" type="checkbox" class="size-5 accent-[var(--accent)]" />
            </label>
        </form>

        <template #footer>
            <div v-if="mode === 'adjust'" class="flex items-center gap-2">
                <button type="button" class="btn btn-quiet h-12 px-4" @click="mode = 'edit'">
                    <ArrowLeft class="size-4" /> Kembali
                </button>
                <button type="submit" form="adjust-form" class="btn btn-primary h-12 flex-1" :disabled="adjustForm.processing || diff === 0">
                    Simpan saldo
                </button>
            </div>
            <div v-else class="flex items-center gap-2">
                <button v-if="editing" type="button" class="btn btn-danger px-3" aria-label="Hapus akun" @click="destroy">
                    <Trash2 class="size-4" />
                </button>
                <button type="submit" form="account-form" class="btn btn-primary h-12 flex-1" :disabled="form.processing">
                    {{ editing ? 'Simpan perubahan' : 'Tambah akun' }}
                </button>
            </div>
        </template>
    </Sheet>
</template>
