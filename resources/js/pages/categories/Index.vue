<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { ChevronRight, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { route } from 'ziggy-js';
import ColorPicker from '@/components/ui/ColorPicker.vue';
import IconTile from '@/components/ui/IconTile.vue';
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
const form = useForm({ name: '', type: 'expense', icon: 'shapes', color: 'teal' });

function edit(category = null) {
    editing.value = category;
    confirmDelete.value = false;
    form.clearErrors();
    form.name = category?.name ?? '';
    form.type = category?.type ?? tab.value;
    form.icon = category?.icon ?? 'shapes';
    form.color = category?.color ?? 'teal';
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
        <h1 class="text-[26px] font-semibold tracking-[-0.025em] md:text-[30px]">Kategori</h1>
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

    <TransitionGroup tag="ul" name="list" class="relative grid gap-x-6 md:grid-cols-2">
        <li v-for="category in list" :key="category.id" class="border-b border-line">
            <button type="button" class="flex w-full items-center gap-3 py-3 text-left transition-opacity hover:opacity-80" @click="edit(category)">
                <IconTile :icon="categoryIcon(category.icon)" :color="category.color" />
                <span class="flex-1 truncate text-[15px]">{{ category.name }}</span>
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
