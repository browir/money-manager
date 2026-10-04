<script setup>
import { router, useForm } from '@inertiajs/vue3';
import { ArrowRight, ArrowRightLeft, CalendarDays, Trash2, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { route } from 'ziggy-js';
import Keypad from '@/components/Keypad.vue';
import CalendarPicker from '@/components/ui/CalendarPicker.vue';
import Segmented from '@/components/ui/Segmented.vue';
import Sheet from '@/components/ui/Sheet.vue';
import { celebrate } from '@/composables/useCelebration';
import { closeQuickAdd, useQuickAdd } from '@/composables/useQuickAdd';
import { useLedger } from '@/composables/useLedger';
import { addDays, dayLabel, today } from '@/lib/dates';
import { accountIcon, categoryIcon } from '@/lib/icons';
import { digits, evaluate, formatExpr, hasOperator, pressKey } from '@/lib/money';
import { color, tint } from '@/lib/palette';

const state = useQuickAdd();
const { activeAccounts, categories, accountById, categoryById } = useLedger();

const TYPES = [
    { value: 'expense', label: 'Pengeluaran' },
    { value: 'income', label: 'Pemasukan' },
    { value: 'transfer', label: 'Transfer' },
];

const form = useForm({
    type: 'expense',
    account_id: null,
    to_account_id: null,
    category_id: null,
    note: '',
    occurred_on: today(),
});
const expr = ref('');
const amount = computed(() => evaluate(expr.value));
const editing = computed(() => state.transaction);
const shake = ref(false);
const amountFocused = ref(false);
const showCalendar = ref(false);
const calendarEl = ref(null);

// Kalender bisa muncul di bawah lipatan sheet (di atas keypad): gulir agar terlihat.
watch(showCalendar, async (open) => {
    if (!open) return;
    await new Promise((r) => setTimeout(r, 320));
    calendarEl.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
});

const typeCategories = computed(() =>
    categories.value.filter((c) => c.type === (form.type === 'income' ? 'income' : 'expense')),
);
const targetAccounts = computed(() => activeAccounts.value.filter((a) => a.id !== form.account_id));

function remembered(key) {
    try {
        return JSON.parse(localStorage.getItem('sisih:last') || '{}')[key];
    } catch {
        return undefined;
    }
}
function remember(values) {
    try {
        localStorage.setItem('sisih:last', JSON.stringify({ ...JSON.parse(localStorage.getItem('sisih:last') || '{}'), ...values }));
    } catch {
        // abaikan
    }
}

function reset() {
    const t = state.transaction;
    form.clearErrors();
    showCalendar.value = false;
    if (t) {
        form.type = t.type;
        form.account_id = t.account_id;
        form.to_account_id = t.to_account_id;
        form.category_id = t.category_id;
        form.note = t.note ?? '';
        form.occurred_on = t.occurred_on;
        expr.value = String(t.amount);
        return;
    }
    const ids = activeAccounts.value.map((a) => a.id);
    const lastAccount = remembered('account');
    form.type = state.type;
    form.account_id = ids.includes(lastAccount) ? lastAccount : (ids[0] ?? null);
    form.to_account_id = null;
    form.category_id = null;
    form.note = '';
    form.occurred_on = today();
    expr.value = '';
    if (form.type === 'transfer') form.to_account_id = targetAccounts.value[0]?.id ?? null;
}

watch(
    () => state.open,
    (open) => open && reset(),
);

// Ganti tipe: kategori lama tidak lagi relevan.
watch(
    () => form.type,
    (type, old) => {
        if (old && type !== old && !(editing.value && editing.value.type === type)) form.category_id = null;
        if (type === 'transfer' && !form.to_account_id) form.to_account_id = targetAccounts.value[0]?.id ?? null;
    },
);
watch(
    () => form.account_id,
    (id) => {
        if (form.to_account_id === id) form.to_account_id = targetAccounts.value[0]?.id ?? null;
    },
);

function press(key) {
    expr.value = pressKey(expr.value, key);
    form.clearErrors('amount');
}

function onAmountKey(e) {
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    const map = { Backspace: 'back', Delete: 'clear', '+': '+', '-': '-', k: '000', K: '000' };
    const key = /^\d$/.test(e.key) ? e.key : map[e.key];
    if (key) {
        e.preventDefault();
        press(key);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        submit();
    }
}

function submit() {
    if (amount.value <= 0) {
        shake.value = true;
        navigator.vibrate?.([20, 40, 20]);
        setTimeout(() => (shake.value = false), 400);
        return;
    }

    // Rekam detail sekarang: form direset begitu sheet ditutup.
    const summary = describe();
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            remember({ account: form.account_id });
            closeQuickAdd();
            celebrate(summary);
        },
    };
    const payload = form.transform((data) => ({ ...data, amount: amount.value }));

    if (editing.value) {
        payload.put(route('transactions.update', editing.value.id), options);
    } else {
        payload.post(route('transactions.store'), options);
    }
}

const LABELS = { expense: 'Pengeluaran tercatat', income: 'Pemasukan tercatat', transfer: 'Transfer tercatat' };

function describe() {
    const from = accountById.value[form.account_id]?.name;
    if (form.type === 'transfer') {
        return {
            type: 'transfer',
            amount: amount.value,
            label: editing.value ? 'Perubahan tersimpan' : LABELS.transfer,
            subtitle: `${from} → ${accountById.value[form.to_account_id]?.name ?? '?'}`,
            icon: ArrowRightLeft,
            color: 'slate',
        };
    }
    const category = categoryById.value[form.category_id];
    return {
        type: form.type,
        amount: amount.value,
        label: editing.value ? 'Perubahan tersimpan' : LABELS[form.type],
        subtitle: [category?.name ?? 'Tanpa kategori', from].filter(Boolean).join(' · '),
        icon: categoryIcon(category?.icon),
        color: category?.color ?? 'slate',
    };
}

function destroy() {
    router.delete(route('transactions.destroy', editing.value.id), {
        preserveScroll: true,
        onSuccess: () => closeQuickAdd(),
    });
}

function setDate(iso) {
    form.occurred_on = iso;
    showCalendar.value = false;
}

const isCustomDate = computed(() => ![today(), addDays(today(), -1)].includes(form.occurred_on));
const amountTone = computed(() => ({ income: 'text-pos', transfer: 'text-ink-2' })[form.type] ?? 'text-ink');
</script>

<template>
    <Sheet :open="state.open" :title="editing ? 'Ubah transaksi' : 'Catat transaksi'" @close="closeQuickAdd">
        <template #header>
            <div class="flex w-full items-center gap-2">
                <Segmented v-model="form.type" :options="TYPES" class="flex-1" />
                <button
                    v-if="editing"
                    type="button"
                    class="icon-btn text-neg hover:text-neg"
                    aria-label="Hapus transaksi"
                    title="Hapus"
                    @click="destroy"
                >
                    <Trash2 class="size-[18px]" />
                </button>
                <button type="button" class="icon-btn -mr-2 hidden md:inline-flex" aria-label="Tutup" @click="closeQuickAdd">
                    <X class="size-[18px]" />
                </button>
            </div>
        </template>

        <!-- Nominal -->
        <div
            data-autofocus
            tabindex="0"
            role="textbox"
            aria-label="Nominal"
            class="group relative -mx-2 mb-4 rounded-2xl px-2 pt-3 pb-4 outline-none"
            :class="shake && 'animate-[shake_0.36s]'"
            @keydown="onAmountKey"
            @focus="amountFocused = true"
            @blur="amountFocused = false"
        >
            <p class="h-5 truncate text-sm text-muted tnum">
                <span v-if="hasOperator(expr)">{{ formatExpr(expr) }} =</span>
                <span v-else-if="editing" class="text-muted">{{ dayLabel(editing.occurred_on) }}</span>
            </p>
            <p class="flex items-baseline gap-1.5 font-semibold tracking-[-0.03em]" :class="amountTone">
                <span class="text-xl font-medium text-muted">Rp</span>
                <span class="text-[44px] leading-none md:text-[48px]">{{ digits(amount) }}</span>
                <span
                    class="ml-0.5 h-9 w-[2px] self-center rounded-full bg-accent-text opacity-0"
                    :class="amountFocused && 'animate-[blink_1.1s_steps(1)_infinite] pointer:opacity-100'"
                />
            </p>
            <p v-if="form.errors.amount" class="field-error">{{ form.errors.amount }}</p>
            <p class="mt-2 hidden text-xs text-muted pointer:block">
                Ketik angka · <kbd class="font-mono">k</kbd> = 000 · <kbd class="font-mono">+</kbd>/<kbd class="font-mono">−</kbd> untuk hitung ·
                <kbd class="font-mono">Enter</kbd> simpan
            </p>
        </div>

        <!-- Akun -->
        <section class="mb-5">
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

        <section v-if="form.type === 'transfer'" class="mb-5">
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

        <!-- Kategori -->
        <section v-else class="mb-5">
            <h3 class="eyebrow mb-2">Kategori</h3>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="category in typeCategories"
                    :key="category.id"
                    type="button"
                    class="chip pl-1.5 transition-[background-color,border-color,color,box-shadow]"
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

        <!-- Tanggal -->
        <section class="mb-5">
            <h3 class="eyebrow mb-2">Tanggal</h3>
            <div class="flex gap-2">
                <button type="button" class="chip" :aria-pressed="form.occurred_on === today()" @click="setDate(today())">
                    Hari ini
                </button>
                <button
                    type="button"
                    class="chip"
                    :aria-pressed="form.occurred_on === addDays(today(), -1)"
                    @click="setDate(addDays(today(), -1))"
                >
                    Kemarin
                </button>
                <button
                    type="button"
                    class="chip"
                    :aria-pressed="isCustomDate || showCalendar"
                    :aria-expanded="showCalendar"
                    @click="showCalendar = !showCalendar"
                >
                    <CalendarDays class="size-4" :stroke-width="1.9" />
                    {{ isCustomDate ? dayLabel(form.occurred_on) : 'Pilih' }}
                </button>
            </div>
            <Transition name="calendar">
                <div v-if="showCalendar" ref="calendarEl">
                    <CalendarPicker v-model="form.occurred_on" class="mt-3" @picked="showCalendar = false" />
                </div>
            </Transition>
            <p v-if="form.errors.occurred_on" class="field-error">{{ form.errors.occurred_on }}</p>
        </section>

        <!-- Catatan -->
        <section class="mb-4">
            <input
                v-model="form.note"
                type="text"
                maxlength="160"
                class="field"
                placeholder="Catatan, misal: makan siang"
                enterkeyhint="done"
                @keydown.enter.prevent="submit"
            />
            <p v-if="form.errors.note" class="field-error">{{ form.errors.note }}</p>
        </section>

        <template #footer>
            <Keypad
                class="pointer:hidden"
                :can-submit="amount > 0"
                :processing="form.processing"
                :submit-label="editing ? 'Perbarui' : 'Simpan'"
                @press="press"
                @submit="submit"
            />
            <div class="hidden items-center justify-between gap-3 pointer:flex">
                <span class="text-xs text-muted"><kbd class="font-mono">Esc</kbd> tutup</span>
                <button type="button" class="btn btn-primary min-w-32" :disabled="form.processing" @click="submit">
                    {{ editing ? 'Perbarui' : 'Simpan' }}
                </button>
            </div>
        </template>
    </Sheet>
</template>

<style>
@keyframes shake {
    20%,
    60% {
        transform: translateX(-6px);
    }
    40%,
    80% {
        transform: translateX(6px);
    }
}
.calendar-enter-active,
.calendar-leave-active {
    display: grid;
    transition:
        grid-template-rows 0.3s var(--ease-out-soft),
        opacity 0.2s ease;
}
.calendar-enter-active > *,
.calendar-leave-active > * {
    min-height: 0;
    overflow: hidden;
}
.calendar-enter-from,
.calendar-leave-to {
    grid-template-rows: 0fr;
    opacity: 0;
}
.calendar-enter-to,
.calendar-leave-from {
    grid-template-rows: 1fr;
}
@keyframes blink {
    50% {
        opacity: 0;
    }
}
</style>
