<script setup>
import { router } from '@inertiajs/vue3';
import {
    ArrowDownLeft, ArrowRightLeft, ArrowUpRight, Home, Layers, List, Moon, Search, Settings, Wallet,
} from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';
import { route } from 'ziggy-js';
import { openQuickAdd } from '@/composables/useQuickAdd';
import { toggleTheme } from '@/composables/useTheme';

const open = defineModel('open', { default: false });
const query = ref('');
const active = ref(0);
const input = ref(null);

const go = (name, params) => () => router.visit(route(name, params));

const commands = [
    { group: 'Catat', label: 'Catat pengeluaran', icon: ArrowUpRight, hint: 'N', run: () => openQuickAdd({ type: 'expense' }) },
    { group: 'Catat', label: 'Catat pemasukan', icon: ArrowDownLeft, run: () => openQuickAdd({ type: 'income' }) },
    { group: 'Catat', label: 'Transfer antar akun', icon: ArrowRightLeft, run: () => openQuickAdd({ type: 'transfer' }) },
    { group: 'Buka', label: 'Beranda', icon: Home, hint: 'G B', run: go('dashboard') },
    { group: 'Buka', label: 'Transaksi', icon: List, hint: 'G T', run: go('transactions.index') },
    { group: 'Buka', label: 'Akun', icon: Wallet, hint: 'G A', run: go('accounts.index') },
    { group: 'Buka', label: 'Kategori', icon: Layers, run: go('categories.index') },
    { group: 'Buka', label: 'Pengaturan', icon: Settings, run: go('settings') },
    { group: 'Lainnya', label: 'Ganti tema terang/gelap', icon: Moon, run: toggleTheme },
];

const results = computed(() => {
    const q = query.value.trim().toLowerCase();
    const list = q ? commands.filter((c) => c.label.toLowerCase().includes(q)) : commands;
    if (!q) return list;
    return [
        ...list,
        {
            group: 'Cari',
            label: `Cari transaksi “${query.value.trim()}”`,
            icon: Search,
            run: () => router.visit(route('transactions.index', { q: query.value.trim() })),
        },
    ];
});

watch(open, async (value) => {
    if (!value) return;
    query.value = '';
    active.value = 0;
    await nextTick();
    input.value?.focus();
});
watch(query, () => (active.value = 0));

function run(command) {
    open.value = false;
    command.run();
}

function onKey(e) {
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        active.value = (active.value + 1) % results.value.length;
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        active.value = (active.value - 1 + results.value.length) % results.value.length;
    } else if (e.key === 'Enter' && results.value[active.value]) {
        e.preventDefault();
        run(results.value[active.value]);
    } else if (e.key === 'Escape') {
        open.value = false;
    }
}
</script>

<template>
    <Teleport to="body">
        <Transition name="fade">
            <div v-if="open" class="fixed inset-0 z-[70] bg-scrim" @click="open = false" />
        </Transition>
        <Transition name="palette">
            <div
                v-if="open"
                data-modal-open
                role="dialog"
                aria-label="Perintah"
                class="fixed inset-x-3 top-[12vh] z-[71] mx-auto max-w-[540px] overflow-hidden rounded-2xl border border-line bg-surface shadow-float"
            >
                <div class="flex items-center gap-3 border-b border-line px-4">
                    <Search class="size-[18px] text-muted" />
                    <input
                        ref="input"
                        v-model="query"
                        type="text"
                        class="h-14 flex-1 bg-transparent text-[15px] outline-none placeholder:text-muted"
                        placeholder="Ketik perintah atau cari catatan…"
                        @keydown="onKey"
                    />
                    <kbd class="rounded-md border border-line px-1.5 py-0.5 font-mono text-[11px] text-muted">Esc</kbd>
                </div>
                <ul class="max-h-[50vh] overflow-y-auto p-2" role="listbox">
                    <template v-for="(command, i) in results" :key="command.label">
                        <li
                            v-if="i === 0 || results[i - 1].group !== command.group"
                            class="px-3 pt-2.5 pb-1.5 text-[11px] font-medium tracking-[0.08em] text-muted uppercase"
                        >
                            {{ command.group }}
                        </li>
                        <li
                            role="option"
                            :aria-selected="i === active"
                            class="flex h-11 cursor-pointer items-center gap-3 rounded-xl px-3 text-[14px]"
                            :class="i === active ? 'bg-sunken text-ink' : 'text-ink-2'"
                            @mousemove="active = i"
                            @click="run(command)"
                        >
                            <component :is="command.icon" class="size-4 shrink-0" :stroke-width="1.9" />
                            <span class="flex-1 truncate">{{ command.label }}</span>
                            <kbd v-if="command.hint" class="font-mono text-[11px] text-muted">{{ command.hint }}</kbd>
                        </li>
                    </template>
                </ul>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.palette-enter-active {
    transition:
        opacity 0.18s ease,
        transform 0.28s var(--ease-sheet);
}
.palette-leave-active {
    transition:
        opacity 0.12s ease,
        transform 0.12s ease;
}
.palette-enter-from,
.palette-leave-to {
    opacity: 0;
    transform: scale(0.97) translateY(-6px);
}
</style>
