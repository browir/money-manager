<script setup>
import { Link, router } from '@inertiajs/vue3';
import { ArrowRightLeft } from 'lucide-vue-next';
import { ref } from 'vue';
import { route } from 'ziggy-js';
import IconTile from '@/components/ui/IconTile.vue';
import Money from '@/components/ui/Money.vue';
import { openQuickAdd } from '@/composables/useQuickAdd';
import { useLedger } from '@/composables/useLedger';
import { categoryIcon } from '@/lib/icons';
import { daysUntil, dueLabel } from '@/lib/recurring';

/** Jadwal berulang yang jatuh tempo: catat sekali ketuk, atau ketuk baris untuk menyesuaikan dulu. */
defineProps({
    items: { type: Array, required: true },
});

const { accountById, categoryById } = useLedger();
const busy = ref(null);

function title(r) {
    if (r.note) return r.note;
    if (r.type === 'transfer') return 'Transfer';
    return categoryById.value[r.category_id]?.name ?? 'Tanpa kategori';
}

function act(r, name) {
    busy.value = r.id;
    router.post(route(name, r.id), {}, { preserveScroll: true, onFinish: () => (busy.value = null) });
}

// Nominal berubah-ubah (listrik, pulsa)? Buka form terisi; tersimpan → jadwal ikut maju.
function adjust(r) {
    openQuickAdd({ template: { ...r, occurred_on: r.next_due, recurring_id: r.id } });
}
</script>

<template>
    <section class="rounded-[22px] border border-accent/25 bg-accent-soft/50 p-4 md:p-5">
        <div class="mb-2 flex items-baseline justify-between">
            <h2 class="text-[15px] font-medium">Jatuh tempo <span class="text-muted">· {{ items.length }}</span></h2>
            <Link :href="route('recurring.index')" class="text-[13px] text-accent-text hover:underline">Kelola</Link>
        </div>
        <TransitionGroup tag="ul" name="list" class="relative flex flex-col">
            <li v-for="r in items" :key="r.id" class="flex items-center gap-3 py-2.5">
                <button type="button" class="flex min-w-0 flex-1 items-center gap-3 text-left" @click="adjust(r)">
                    <IconTile v-if="r.type === 'transfer'" :icon="ArrowRightLeft" color="slate" size="sm" />
                    <IconTile v-else :icon="categoryIcon(categoryById[r.category_id]?.icon)" :color="categoryById[r.category_id]?.color ?? 'slate'" size="sm" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[14px] leading-tight font-medium">{{ title(r) }}</span>
                        <span class="mt-0.5 block truncate text-[12px] leading-tight text-muted">
                            <span :class="daysUntil(r.next_due) < 0 && 'text-neg'">{{ dueLabel(r.next_due) }}</span>
                            · <Money :value="r.amount" class="tnum" /> · {{ accountById[r.account_id]?.name }}
                        </span>
                    </span>
                </button>
                <button
                    type="button"
                    class="h-8 shrink-0 rounded-full px-2.5 text-[13px] text-muted transition-colors hover:text-ink disabled:opacity-50"
                    :disabled="busy === r.id"
                    @click="act(r, 'recurring.skip')"
                >
                    Lewati
                </button>
                <button
                    type="button"
                    class="h-8 shrink-0 rounded-full bg-accent px-3.5 text-[13px] font-medium text-accent-fg transition active:scale-95 disabled:opacity-50"
                    :disabled="busy === r.id"
                    @click="act(r, 'recurring.record')"
                >
                    Catat
                </button>
            </li>
        </TransitionGroup>
    </section>
</template>
