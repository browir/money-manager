<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { ChevronRight, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { route } from 'ziggy-js';
import AmountField from '@/components/ui/AmountField.vue';
import ColorPicker from '@/components/ui/ColorPicker.vue';
import IconTile from '@/components/ui/IconTile.vue';
import Money from '@/components/ui/Money.vue';
import Segmented from '@/components/ui/Segmented.vue';
import Sheet from '@/components/ui/Sheet.vue';
import { useLedger } from '@/composables/useLedger';
import { CATEGORY_ICONS, categoryIcon } from '@/lib/icons';
import { color, tint } from '@/lib/palette';

const { categories } = useLedger();
const tab = ref('expense');
const list = computed(() => categories.value.filter((c) => c.type === tab.value));

const sheetOpen = ref(false);
const editing = ref(null);
const confirmDelete = ref(false);
const form = useForm({ name: '', type: 'expense', icon: 'shapes', color: 'teal', budget: 0 });

/** Pemakaian anggaran bulan berjalan ("spent" dari server). */
function budgetUse(category) {
    if (category.type !== 'expense' || !category.budget) return null;
    const ratio = category.spent / category.budget;
    return { ratio, over: ratio > 1, warn: ratio >= 0.85 && ratio <= 1 };
}

function edit(category = null) {
    editing.value = category;
    confirmDelete.value = false;
    form.clearErrors();
    form.name = category?.name ?? '';
    form.type = category?.type ?? tab.value;
    form.icon = category?.icon ?? 'shapes';
    form.color = category?.color ?? 'teal';
    form.budget = category?.budget ?? 0;
    sheetOpen.value = true;
}

function save() {
    const options = { preserveScroll: true, onSuccess: () => (sheetOpen.value = false) };
    if (editing.value) form.put(route('categories.update', editing.value.id), options);
    else form.post(route('categories.store'), options);
}

function destroy() {
    if (!confirmDelete.value) {
        confirmDelete.value = true;
        return;
    }
    router.delete(route('categories.destroy', editing.value.id), {
        preserveScroll: true,
        onSuccess: () => (sheetOpen.value = false),
    });
}
</script>

<template>
    <Head title="Kategori" />

    <header class="mb-6 flex items-center justify-between gap-4">
        <h1 class="page-title">Kategori</h1>
        <button type="button" class="btn btn-primary" @click="edit()"><Plus class="size-4" /> Kategori</button>
    </header>

    <Segmented
        v-model="tab"
        :options="[
            { value: 'expense', label: 'Pengeluaran' },
            { value: 'income', label: 'Pemasukan' },
        ]"
        class="mb-5 max-w-sm"
    />

    <TransitionGroup tag="ul" name="list" class="card relative grid gap-x-6 px-4 md:grid-cols-2 md:px-5">
        <li v-for="category in list" :key="category.id" class="border-b border-line last:border-b-0 md:[&:nth-last-child(2)]:border-b-0">
            <button type="button" class="flex w-full items-center gap-3 py-3 text-left transition-opacity hover:opacity-80" @click="edit(category)">
                <IconTile :icon="categoryIcon(category.icon)" :color="category.color" />
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[15px]">{{ category.name }}</span>
                    <template v-if="budgetUse(category)">
                        <span class="mt-1.5 block h-1.5 overflow-hidden rounded-full bg-sunken">
                            <span
                                class="block h-full rounded-full"
                                :class="budgetUse(category).over ? 'bg-neg' : budgetUse(category).warn ? 'bg-warn' : 'bg-bar'"
                                :style="{ width: `${Math.min(100, budgetUse(category).ratio * 100)}%` }"
                            />
                        </span>
                        <span class="mt-1 block text-[12px] text-muted tnum">
                            <Money :value="category.spent" /> dari <Money :value="category.budget" />
                        </span>
                    </template>
                </span>
                <ChevronRight class="size-4 text-muted" />
            </button>
        </li>
    </TransitionGroup>

    <Sheet :open="sheetOpen" :title="editing ? 'Ubah kategori' : 'Kategori baru'" @close="sheetOpen = false">
        <form id="category-form" class="flex flex-col gap-5 pb-2" @submit.prevent="save">
            <div class="flex items-center gap-3">
                <IconTile :icon="categoryIcon(form.icon)" :color="form.color" size="lg" />
                <div class="flex-1">
                    <input v-model="form.name" data-autofocus class="field" maxlength="40" placeholder="Nama kategori" aria-label="Nama kategori" />
                    <p v-if="form.errors.name" class="field-error">{{ form.errors.name }}</p>
                </div>
            </div>

            <div v-if="!editing">
                <span class="field-label">Jenis</span>
                <Segmented
                    v-model="form.type"
                    :options="[
                        { value: 'expense', label: 'Pengeluaran' },
                        { value: 'income', label: 'Pemasukan' },
                    ]"
                />
            </div>

            <div v-if="form.type === 'expense'">
                <span class="field-label">Anggaran bulanan</span>
                <AmountField v-model="form.budget" />
                <p class="mt-1.5 text-[12px] text-muted">Opsional. Kosongkan jika kategori ini tidak dibatasi.</p>
                <p v-if="form.errors.budget" class="field-error">{{ form.errors.budget }}</p>
            </div>

            <div>
                <span class="field-label">Warna</span>
                <ColorPicker v-model="form.color" />
            </div>

            <div>
                <span class="field-label">Ikon</span>
                <div class="grid grid-cols-7 gap-1.5 sm:grid-cols-8">
                    <button
                        v-for="(icon, name) in CATEGORY_ICONS"
                        :key="name"
                        type="button"
                        class="grid aspect-square place-items-center rounded-xl text-ink-2 transition-[background-color,transform] hover:bg-sunken active:scale-90"
                        :style="form.icon === name ? { background: tint(form.color, 18), color: color(form.color) } : undefined"
                        :aria-label="name"
                        :aria-pressed="form.icon === name"
                        @click="form.icon = name"
                    >
                        <component :is="icon" class="size-[18px]" :stroke-width="1.9" />
                    </button>
                </div>
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
                <button type="submit" form="category-form" class="btn btn-primary h-12 flex-1" :disabled="form.processing">
                    {{ editing ? 'Simpan perubahan' : 'Tambah kategori' }}
                </button>
            </div>
            <p v-if="confirmDelete" class="mt-2 text-[12px] text-muted">Transaksi lama tetap ada, tapi tanpa kategori.</p>
        </template>
    </Sheet>
</template>
