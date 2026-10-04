<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowRightLeft, ChevronDown, Plus, Trash2 } from 'lucide-vue-next';
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

const { accounts, activeAccounts, netWorth } = useLedger();
const archived = computed(() => accounts.value.filter((a) => a.archived));
const showArchived = ref(false);

const TYPE_OPTIONS = Object.entries(ACCOUNT_TYPES).map(([value, { label }]) => ({ value, label }));

const sheetOpen = ref(false);
const editing = ref(null);
const form = useForm({ name: '', type: 'bank', color: 'ink', initial_balance: 0, archived: false });

function edit(account = null) {
    editing.value = account;
    form.clearErrors();
    form.name = account?.name ?? '';
    form.type = account?.type ?? 'bank';
    form.color = account?.color ?? 'ink';
    form.initial_balance = account?.initial_balance ?? 0;
    form.archived = account?.archived ?? false;
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

    <header class="mb-8 flex items-end justify-between gap-4">
        <div>
            <h1 class="mb-3 text-[26px] font-semibold tracking-[-0.025em] md:text-[30px]">Akun</h1>
            <p class="eyebrow mb-1">Total saldo</p>
            <p class="text-[28px] font-semibold tracking-[-0.03em]" :class="netWorth < 0 && 'text-neg'">
                <Money :value="netWorth" animate />
            </p>
        </div>
        <div class="flex gap-2">
            <button
                v-if="activeAccounts.length > 1"
                type="button"
                class="btn btn-quiet"
                title="Transfer antar akun"
                @click="openQuickAdd({ type: 'transfer' })"
            >
                <ArrowRightLeft class="size-4" /> <span class="hidden sm:inline">Transfer</span>
            </button>
            <button type="button" class="btn btn-primary" @click="edit()"><Plus class="size-4" /> Akun</button>
        </div>
    </header>

    <TransitionGroup tag="ul" name="list" class="relative grid gap-3 sm:grid-cols-2">
        <li v-for="account in activeAccounts" :key="account.id">
            <button
                type="button"
                class="group flex w-full flex-col gap-6 rounded-2xl border border-line bg-surface p-4 text-left transition-[border-color,transform] hover:border-line-strong active:scale-[0.99] md:p-5"
                @click="edit(account)"
            >
                <span class="flex w-full items-center gap-3">
                    <IconTile :icon="accountIcon(account.type)" :color="account.color" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[15px] font-medium">{{ account.name }}</span>
                        <span class="block text-[13px] text-muted">{{ ACCOUNT_TYPES[account.type]?.label }}</span>
                    </span>
                </span>
                <Money
                    :value="account.balance"
                    animate
                    class="text-[22px] font-semibold tracking-[-0.02em]"
                    :class="account.balance < 0 && 'text-neg'"
                />
            </button>
        </li>
    </TransitionGroup>

    <section v-if="archived.length" class="mt-10">
        <button type="button" class="mb-3 flex items-center gap-1.5 text-[13px] text-muted hover:text-ink" @click="showArchived = !showArchived">
            <ChevronDown class="size-4 transition-transform" :class="!showArchived && '-rotate-90'" />
            Diarsipkan ({{ archived.length }})
        </button>
        <ul v-if="showArchived" class="divide-y divide-line">
            <li v-for="account in archived" :key="account.id">
                <button type="button" class="flex w-full items-center gap-3 py-3 text-left opacity-70 hover:opacity-100" @click="edit(account)">
                    <IconTile :icon="accountIcon(account.type)" :color="account.color" size="sm" />
                    <span class="flex-1 truncate text-sm">{{ account.name }}</span>
                    <Money :value="account.balance" class="text-sm tnum" />
                </button>
            </li>
        </ul>
    </section>

    <Sheet :open="sheetOpen" :title="editing ? 'Ubah akun' : 'Akun baru'" @close="sheetOpen = false">
        <form id="account-form" class="flex flex-col gap-5 pb-2" @submit.prevent="save">
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
                <p class="mt-1.5 text-[12px] text-muted">Saldo sebelum transaksi pertama dicatat di Sisih.</p>
                <p v-if="form.errors.initial_balance" class="field-error">{{ form.errors.initial_balance }}</p>
            </div>
            <label v-if="editing" class="flex cursor-pointer items-center justify-between gap-4 rounded-xl bg-sunken px-4 py-3">
                <span>
                    <span class="block text-sm font-medium">Arsipkan</span>
                    <span class="block text-[12px] text-muted">Disembunyikan dari pilihan, riwayat tetap ada.</span>
                </span>
                <input v-model="form.archived" type="checkbox" class="size-5 accent-[var(--accent)]" />
            </label>
        </form>

        <template #footer>
            <div class="flex items-center gap-2">
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
