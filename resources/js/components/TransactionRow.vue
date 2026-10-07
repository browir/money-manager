<script setup>
import { router } from '@inertiajs/vue3';
import { ArrowRightLeft, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { route } from 'ziggy-js';
import IconTile from '@/components/ui/IconTile.vue';
import Money from '@/components/ui/Money.vue';
import { useHighlighted } from '@/composables/useCelebration';
import { openQuickAdd } from '@/composables/useQuickAdd';
import { useLedger } from '@/composables/useLedger';
import { categoryIcon } from '@/lib/icons';

const props = defineProps({
    transaction: { type: Object, required: true },
    showDate: { type: String, default: '' },
});

const { accountById, categoryById } = useLedger();
const highlighted = useHighlighted();
const t = computed(() => props.transaction);
const category = computed(() => categoryById.value[t.value.category_id]);
const account = computed(() => accountById.value[t.value.account_id]);
const toAccount = computed(() => accountById.value[t.value.to_account_id]);

const title = computed(() => {
    if (t.value.note) return t.value.note;
    if (t.value.type === 'transfer') return 'Transfer';
    return category.value?.name ?? 'Tanpa kategori';
});

const subtitle = computed(() => {
    if (t.value.type === 'transfer') return `${account.value?.name ?? '?'} → ${toAccount.value?.name ?? '?'}`;
    const parts = [];
    if (t.value.note) parts.push(category.value?.name ?? 'Tanpa kategori');
    parts.push(account.value?.name);
    return parts.filter(Boolean).join(' · ');
});

const signed = computed(() => {
    if (t.value.type === 'income') return t.value.amount;
    if (t.value.type === 'expense') return -t.value.amount;
    return t.value.amount;
});

function destroy() {
    router.delete(route('transactions.destroy', t.value.id), { preserveScroll: true });
}

/* ---- Geser ke kiri untuk hapus (layar sentuh) ---- */
const REVEAL = 84;
const offset = ref(0);
const animating = ref(false);
let start = null;
let moved = false;

function down(e) {
    if (e.pointerType === 'mouse') return;
    start = { x: e.clientX, y: e.clientY, base: offset.value, locked: null };
    moved = false;
}

function move(e) {
    if (!start) return;
    const dx = e.clientX - start.x;
    const dy = e.clientY - start.y;
    if (start.locked === null && Math.hypot(dx, dy) > 8) start.locked = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y';
    if (start.locked !== 'x') return;
    moved = true;
    animating.value = false;
    const next = start.base + dx;
    offset.value = next > 0 ? next / 5 : next < -REVEAL ? -REVEAL + (next + REVEAL) / 2.2 : next;
}

function up() {
    if (!start) return;
    animating.value = true;
    if (offset.value < -REVEAL - 70) {
        navigator.vibrate?.(14);
        offset.value = -window.innerWidth;
        setTimeout(destroy, 160);
    } else {
        offset.value = offset.value < -REVEAL / 2 ? -REVEAL : 0;
    }
    start = null;
}

function open() {
    if (moved) {
        moved = false;
        return;
    }
    if (offset.value !== 0) {
        animating.value = true;
        offset.value = 0;
        return;
    }
    openQuickAdd({ transaction: t.value });
}
</script>

<template>
    <div class="relative overflow-hidden">
        <!-- Aksi di balik baris -->
        <button
            type="button"
            class="absolute inset-y-0 right-0 flex w-[84px] items-center justify-center bg-neg text-white"
            :class="offset === 0 && 'invisible'"
            aria-label="Hapus"
            tabindex="-1"
            @click="destroy"
        >
            <Trash2 class="size-5" />
        </button>

        <div
            role="button"
            tabindex="0"
            class="group relative flex touch-pan-y items-center gap-3 bg-surface py-3 outline-none select-none focus-visible:bg-sunken md:rounded-xl md:px-2 md:hover:bg-sunken/70"
            :class="highlighted === t.id && 'row-flash'"
            :style="{
                transform: offset ? `translateX(${offset}px)` : undefined,
                transition: animating ? 'transform 0.32s var(--ease-sheet)' : undefined,
            }"
            @pointerdown="down"
            @pointermove="move"
            @pointerup="up"
            @pointercancel="up"
            @click="open"
            @keydown.enter="open"
            @keydown.delete="destroy"
        >
            <IconTile
                v-if="t.type === 'transfer'"
                :icon="ArrowRightLeft"
                color="slate"
            />
            <IconTile v-else :icon="categoryIcon(category?.icon)" :color="category?.color ?? 'slate'" />

            <div class="min-w-0 flex-1">
                <p class="truncate text-[15px] leading-tight font-medium">{{ title }}</p>
                <p class="mt-1 truncate text-[13px] leading-tight text-muted">
                    <span v-if="showDate">{{ showDate }} · </span>{{ subtitle }}
                </p>
            </div>

            <button
                type="button"
                class="icon-btn hidden size-8 opacity-0 transition-opacity group-hover:opacity-100 focus-visible:opacity-100 pointer:inline-flex"
                aria-label="Hapus transaksi"
                title="Hapus (Del)"
                @click.stop="destroy"
            >
                <Trash2 class="size-4" />
            </button>

            <Money
                :value="signed"
                :sign="t.type === 'income'"
                class="text-[15px] font-medium tnum"
                :class="{ 'text-pos': t.type === 'income', 'text-ink-2': t.type === 'transfer' }"
            />
        </div>
    </div>
</template>

<style scoped>
/* Sorotan baris yang baru disimpan: menyala lembut lalu memudar. */
.row-flash {
    animation: row-flash 2s 0.9s cubic-bezier(0.22, 1, 0.36, 1) both;
}
@keyframes row-flash {
    0%,
    25% {
        background-color: var(--accent-soft);
        box-shadow: inset 3px 0 0 var(--accent-text);
    }
    100% {
        background-color: var(--surface);
        box-shadow: inset 3px 0 0 transparent;
    }
}
</style>
