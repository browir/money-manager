<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { Home, Layers, List, LogOut, Moon, MoreHorizontal, Plus, Repeat, Search, Settings, Sun, Wallet } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { route } from 'ziggy-js';
import CommandPalette from '@/components/CommandPalette.vue';
import OfflineBar from '@/components/OfflineBar.vue';
import PullToRefresh from '@/components/PullToRefresh.vue';
import SaveCelebration from '@/components/SaveCelebration.vue';
import TransactionSheet from '@/components/TransactionSheet.vue';
import Toaster from '@/components/ui/Toaster.vue';
import { openQuickAdd } from '@/composables/useQuickAdd';
import { useShortcuts } from '@/composables/useShortcuts';
import { toggleTheme } from '@/composables/useTheme';
import SisihMark from '@/components/ui/SisihMark.vue';

const page = usePage();
const paletteOpen = ref(false);
const user = computed(() => page.props.auth.user);
const initials = computed(() =>
    (user.value?.name ?? '')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0])
        .join('')
        .toUpperCase(),
);

const nav = [
    { label: 'Beranda', route: 'dashboard', icon: Home, match: 'Dashboard' },
    { label: 'Transaksi', route: 'transactions.index', icon: List, match: 'transactions/' },
    { label: 'Akun', route: 'accounts.index', icon: Wallet, match: 'accounts/' },
    { label: 'Kategori', route: 'categories.index', icon: Layers, match: 'categories/' },
    { label: 'Berulang', route: 'recurring.index', icon: Repeat, match: 'recurring/' },
    { label: 'Pengaturan', route: 'settings', icon: Settings, match: 'settings/' },
];
const mobileNav = [nav[0], nav[1], null, nav[2], { label: 'Lainnya', route: 'settings', icon: MoreHorizontal, match: ['settings/', 'categories/', 'recurring/'] }];

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

// Pintasan ikon PWA (manifest "shortcuts"): /?catat=pengeluaran|pemasukan|transfer.
const SHORTCUT_TYPES = { pengeluaran: 'expense', pemasukan: 'income', transfer: 'transfer' };
onMounted(() => {
    const url = new URL(window.location.href);
    const type = SHORTCUT_TYPES[url.searchParams.get('catat')];
    if (!type) return;
    url.searchParams.delete('catat');
    history.replaceState(history.state, '', url);
    openQuickAdd({ type });
});

const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);
</script>

<template>
    <div class="min-h-dvh md:grid md:grid-cols-[248px_1fr]">
        <!-- Cahaya lembut di atas halaman: memberi kedalaman tanpa mengganggu isi. -->
        <div
            class="pointer-events-none fixed inset-x-0 top-0 -z-10 h-[420px]"
            style="background: radial-gradient(70% 100% at 50% -20%, var(--glow), transparent 70%)"
            aria-hidden="true"
        />

        <!-- Sidebar (desktop) -->
        <aside class="sticky top-0 hidden h-dvh flex-col border-r border-line bg-surface/55 px-4 py-6 backdrop-blur-xl md:flex">
            <Link :href="route('dashboard')" class="mb-8 flex items-center gap-2.5 px-2">
                <span class="grid size-8 place-items-center rounded-[10px] bg-gradient-to-br from-(--hero-from) to-(--hero-to) text-white shadow-[0_6px_14px_-6px_var(--hero-from)]">
                    <SisihMark class="size-5" />
                </span>
                <span class="text-[18px] font-semibold tracking-tight">Sisih</span>
            </Link>

            <button
                type="button"
                class="btn btn-primary mb-3 h-11 w-full justify-between pr-3 shadow-[0_8px_18px_-10px_var(--accent)]"
                @click="openQuickAdd()"
            >
                <span class="flex items-center gap-2"><Plus class="size-4" :stroke-width="2.4" /> Catat</span>
                <kbd class="rounded-md bg-white/15 px-1.5 font-mono text-tiny dark:bg-black/15">N</kbd>
            </button>
            <button
                type="button"
                class="mb-7 flex h-10 w-full items-center gap-2 rounded-xl border border-line bg-surface px-3 text-sm text-muted transition-colors hover:border-line-strong hover:text-ink-2"
                @click="paletteOpen = true"
            >
                <Search class="size-4" />
                <span class="flex-1 text-left">Cari…</span>
                <kbd class="font-mono text-tiny">{{ isMac ? '⌘' : 'Ctrl' }} K</kbd>
            </button>

            <p class="eyebrow mb-2 px-3">Menu</p>
            <nav class="flex flex-col gap-0.5">
                <Link
                    v-for="item in nav"
                    :key="item.route"
                    :href="route(item.route)"
                    class="relative flex h-10 items-center gap-3 rounded-xl px-3 text-[14px] transition-colors"
                    :class="isActive(item) ? 'bg-accent-soft font-medium text-accent-text' : 'text-ink-2 hover:bg-sunken/70 hover:text-ink'"
                >
                    <span
                        v-if="isActive(item)"
                        class="absolute top-2 bottom-2 -left-4 w-[3px] rounded-r-full bg-accent-text"
                        aria-hidden="true"
                    />
                    <component :is="item.icon" class="size-[18px]" :stroke-width="isActive(item) ? 2.1 : 1.8" />
                    {{ item.label }}
                </Link>
            </nav>

            <div class="mt-auto flex items-center gap-2.5 rounded-2xl border border-line bg-surface p-2.5 shadow-card">
                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-accent-soft text-[13px] font-semibold text-accent-text">
                    {{ initials || 'S' }}
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-[13px] font-medium">{{ user?.name }}</p>
                    <p class="truncate text-tiny text-muted">{{ user?.email }}</p>
                </div>
                <button type="button" class="icon-btn size-8" aria-label="Ganti tema" title="Ganti tema" @click="toggleTheme">
                    <Sun class="hidden size-4 dark:block" />
                    <Moon class="size-4 dark:hidden" />
                </button>
                <Link :href="route('logout')" method="post" as="button" class="icon-btn size-8" aria-label="Keluar" title="Keluar">
                    <LogOut class="size-4" />
                </Link>
            </div>
        </aside>

        <!-- Konten -->
        <main class="min-w-0 pb-[calc(112px+env(safe-area-inset-bottom))] md:pb-16">
            <div :key="page.component" class="page-enter mx-auto w-full max-w-[1080px] px-4 pt-[max(1.25rem,env(safe-area-inset-top))] md:px-10 md:pt-10">
                <OfflineBar />
                <slot />
            </div>
        </main>

        <!-- Navigasi bawah (HP): pil mengambang -->
        <nav
            class="fixed inset-x-3 bottom-[max(12px,env(safe-area-inset-bottom))] z-30 mx-auto max-w-md rounded-[26px] border border-line bg-surface/85 shadow-lift backdrop-blur-xl md:hidden"
            aria-label="Navigasi utama"
        >
            <div class="grid h-16 grid-cols-5 items-center px-1.5">
                <template v-for="(item, i) in mobileNav" :key="i">
                    <button
                        v-if="!item"
                        type="button"
                        class="mx-auto flex size-12 -translate-y-3 items-center justify-center rounded-[18px] bg-gradient-to-br from-(--hero-from) to-(--hero-to) text-white shadow-[0_10px_20px_-8px_var(--hero-from)] ring-4 ring-paper transition active:scale-90"
                        aria-label="Catat transaksi"
                        @click="openQuickAdd()"
                    >
                        <Plus class="size-6" :stroke-width="2.4" />
                    </button>
                    <Link
                        v-else
                        :href="route(item.route)"
                        class="flex flex-col items-center gap-0.5 text-2xs transition-colors"
                        :class="isActive(item) ? 'font-semibold text-accent-text' : 'text-muted'"
                        :aria-current="isActive(item) ? 'page' : undefined"
                    >
                        <span
                            class="grid h-7 w-12 place-items-center rounded-full transition-colors duration-200"
                            :class="isActive(item) && 'bg-accent-soft'"
                        >
                            <component :is="item.icon" class="size-[20px]" :stroke-width="isActive(item) ? 2.2 : 1.7" />
                        </span>
                        {{ item.label }}
                    </Link>
                </template>
            </div>
        </nav>

        <TransactionSheet />
        <CommandPalette v-model:open="paletteOpen" />
        <Toaster />
        <SaveCelebration />
        <PullToRefresh />
    </div>
</template>
