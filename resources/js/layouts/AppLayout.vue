<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { Home, Layers, List, LogOut, Moon, MoreHorizontal, Plus, Search, Settings, Sun, Wallet } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { route } from 'ziggy-js';
import CommandPalette from '@/components/CommandPalette.vue';
import TransactionSheet from '@/components/TransactionSheet.vue';
import Toaster from '@/components/ui/Toaster.vue';
import { openQuickAdd } from '@/composables/useQuickAdd';
import { useShortcuts } from '@/composables/useShortcuts';
import { toggleTheme } from '@/composables/useTheme';

const page = usePage();
const paletteOpen = ref(false);
const user = computed(() => page.props.auth.user);

const nav = [
    { label: 'Beranda', route: 'dashboard', icon: Home, match: 'Dashboard' },
    { label: 'Transaksi', route: 'transactions.index', icon: List, match: 'transactions/' },
    { label: 'Akun', route: 'accounts.index', icon: Wallet, match: 'accounts/' },
    { label: 'Kategori', route: 'categories.index', icon: Layers, match: 'categories/' },
    { label: 'Pengaturan', route: 'settings', icon: Settings, match: 'settings/' },
];
const mobileNav = [nav[0], nav[1], null, nav[2], { label: 'Lainnya', route: 'settings', icon: MoreHorizontal, match: ['settings/', 'categories/'] }];

const isActive = (item) => [].concat(item.match).some((m) => page.component.startsWith(m));

// "g" lalu huruf untuk pindah halaman, gaya aplikasi desktop.
let pendingG = 0;
const jump = (name) => () => {
    if (Date.now() - pendingG < 800) router.visit(route(name));
};
useShortcuts({
    'mod+k': () => (paletteOpen.value = !paletteOpen.value),
    n: () => openQuickAdd({ type: 'expense' }),
    g: () => (pendingG = Date.now()),
    b: jump('dashboard'),
    t: jump('transactions.index'),
    a: jump('accounts.index'),
});

const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);
</script>

<template>
    <div class="min-h-dvh md:grid md:grid-cols-[232px_1fr]">
        <!-- Sidebar (desktop) -->
        <aside class="sticky top-0 hidden h-dvh flex-col border-r border-line px-4 py-6 md:flex">
            <Link :href="route('dashboard')" class="mb-8 flex items-center gap-2.5 px-2">
                <span class="grid size-7 place-items-center rounded-lg bg-accent text-accent-fg">
                    <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                        <path d="M6 8.5c0-1.4 1.6-2.5 6-2.5s6 1.1 6 2.5S16.4 11 12 12s-6 2.1-6 3.5S7.6 18 12 18s6-1.1 6-2.5" />
                    </svg>
                </span>
                <span class="text-[17px] font-semibold tracking-tight">Sisih</span>
            </Link>

            <button type="button" class="btn btn-primary mb-3 w-full justify-between pr-3" @click="openQuickAdd()">
                <span class="flex items-center gap-2"><Plus class="size-4" :stroke-width="2.4" /> Catat</span>
                <kbd class="rounded-md bg-white/15 px-1.5 font-mono text-[11px] dark:bg-black/15">N</kbd>
            </button>
            <button
                type="button"
                class="mb-6 flex h-10 w-full items-center gap-2 rounded-xl border border-line px-3 text-sm text-muted transition-colors hover:border-line-strong hover:text-ink-2"
                @click="paletteOpen = true"
            >
                <Search class="size-4" />
                <span class="flex-1 text-left">Cari…</span>
                <kbd class="font-mono text-[11px]">{{ isMac ? '⌘' : 'Ctrl' }} K</kbd>
            </button>

            <nav class="flex flex-col gap-0.5">
                <Link
                    v-for="item in nav"
                    :key="item.route"
                    :href="route(item.route)"
                    class="relative flex h-10 items-center gap-3 rounded-xl px-3 text-[14px] transition-colors"
                    :class="isActive(item) ? 'bg-sunken font-medium text-ink' : 'text-ink-2 hover:bg-sunken/60 hover:text-ink'"
                >
                    <component :is="item.icon" class="size-[18px]" :stroke-width="isActive(item) ? 2.1 : 1.8" />
                    {{ item.label }}
                </Link>
            </nav>

            <div class="mt-auto flex items-center gap-2 border-t border-line px-1 pt-4">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium">{{ user?.name }}</p>
                    <p class="truncate text-xs text-muted">{{ user?.email }}</p>
                </div>
                <button type="button" class="icon-btn" aria-label="Ganti tema" title="Ganti tema" @click="toggleTheme">
                    <Sun class="hidden size-[18px] dark:block" />
                    <Moon class="size-[18px] dark:hidden" />
                </button>
                <Link :href="route('logout')" method="post" as="button" class="icon-btn" aria-label="Keluar" title="Keluar">
                    <LogOut class="size-[18px]" />
                </Link>
            </div>
        </aside>

        <!-- Konten -->
        <main class="min-w-0 pb-[calc(96px+env(safe-area-inset-bottom))] md:pb-16">
            <div :key="page.component" class="page-enter mx-auto w-full max-w-[1040px] px-4 pt-[max(1.25rem,env(safe-area-inset-top))] md:px-10 md:pt-10">
                <slot />
            </div>
        </main>

        <!-- Navigasi bawah (HP) -->
        <nav
            class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-paper/85 pb-safe backdrop-blur-xl md:hidden"
            aria-label="Navigasi utama"
        >
            <div class="mx-auto grid h-16 max-w-md grid-cols-5 items-center px-2">
                <template v-for="(item, i) in mobileNav" :key="i">
                    <button
                        v-if="!item"
                        type="button"
                        class="mx-auto grid size-12 place-items-center rounded-2xl bg-accent text-accent-fg shadow-[0_6px_16px_-6px_var(--accent)] transition active:scale-90"
                        aria-label="Catat transaksi"
                        @click="openQuickAdd()"
                    >
                        <Plus class="size-6" :stroke-width="2.4" />
                    </button>
                    <Link
                        v-else
                        :href="route(item.route)"
                        class="flex flex-col items-center gap-1 text-[11px] transition-colors"
                        :class="isActive(item) ? 'font-medium text-ink' : 'text-muted'"
                    >
                        <component :is="item.icon" class="size-[22px]" :stroke-width="isActive(item) ? 2.1 : 1.7" />
                        {{ item.label }}
                    </Link>
                </template>
            </div>
        </nav>

        <TransactionSheet />
        <CommandPalette v-model:open="paletteOpen" />
        <Toaster />
    </div>
</template>
