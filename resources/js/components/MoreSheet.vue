<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronRight, EyeOff, Gauge, Layers, LogOut, Mic, Repeat, Settings } from 'lucide-vue-next';
import { computed } from 'vue';
import { route } from 'ziggy-js';
import Segmented from '@/components/ui/Segmented.vue';
import Sheet from '@/components/ui/Sheet.vue';
import { togglePrivacy, usePrivacy } from '@/composables/usePrivacy';
import { setTheme, useTheme } from '@/composables/useTheme';
import { openVoice } from '@/composables/useVoice';

/* Menu "Lainnya" di navigasi bawah (HP): halaman jarang dipakai + tema & privasi. */
const open = defineModel('open', { default: false });
const page = usePage();
const theme = useTheme();
const hidden = usePrivacy();

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

const links = computed(() => [
    { label: 'Kategori & anggaran', route: 'categories.index', icon: Layers },
    { label: 'Transaksi berulang', route: 'recurring.index', icon: Repeat },
    ...(page.props.features?.voice ? [{ label: 'Pemakaian AI', route: 'settings.ai', icon: Gauge }] : []),
    { label: 'Pengaturan', route: 'settings', icon: Settings },
]);

function voice() {
    open.value = false;
    openVoice();
}
</script>

<template>
    <Sheet :open="open" title="Lainnya" @close="open = false">
        <div class="mb-4 flex items-center gap-3">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-accent-soft text-[15px] font-semibold text-accent-text">
                {{ initials || 'S' }}
            </span>
            <div class="min-w-0">
                <p class="truncate text-[15px] font-semibold">{{ user?.name }}</p>
                <p class="truncate text-small text-muted">{{ user?.email }}</p>
            </div>
        </div>

        <nav class="mb-4 divide-y divide-line overflow-hidden rounded-2xl border border-line">
            <button
                v-if="page.props.features?.voice"
                type="button"
                class="flex w-full items-center gap-3 px-4 py-3 text-left active:bg-sunken"
                @click="voice"
            >
                <span class="flex size-9 items-center justify-center rounded-xl bg-accent-soft text-accent-text"><Mic class="size-[18px]" /></span>
                <span class="flex-1 text-[15px] font-medium">Panggil LAX</span>
                <ChevronRight class="size-4 text-muted" />
            </button>
            <Link
                v-for="item in links"
                :key="item.route"
                :href="route(item.route)"
                class="flex items-center gap-3 px-4 py-3 active:bg-sunken"
                @click.capture="open = false"
            >
                <span class="flex size-9 items-center justify-center rounded-xl bg-accent-soft text-accent-text">
                    <component :is="item.icon" class="size-[18px]" />
                </span>
                <span class="flex-1 text-[15px] font-medium">{{ item.label }}</span>
                <ChevronRight class="size-4 text-muted" />
            </Link>
        </nav>

        <p class="eyebrow mb-2">Tampilan</p>
        <Segmented
            class="mb-3"
            :model-value="theme"
            :options="[
                { value: 'system', label: 'Ikuti sistem' },
                { value: 'light', label: 'Terang' },
                { value: 'dark', label: 'Gelap' },
            ]"
            @update:model-value="setTheme"
        />

        <button
            type="button"
            role="switch"
            :aria-checked="hidden"
            class="mb-4 flex w-full items-center gap-3 rounded-2xl border border-line px-4 py-3 text-left active:bg-sunken"
            @click="togglePrivacy"
        >
            <EyeOff class="size-[18px] text-ink-2" />
            <span class="flex-1">
                <span class="block text-[15px] font-medium">Sembunyikan nominal</span>
                <span class="block text-small text-muted">Angka diganti titik, untuk di tempat umum</span>
            </span>
            <span class="relative h-7 w-12 shrink-0 rounded-full transition-colors" :class="hidden ? 'bg-accent' : 'bg-line-strong'">
                <span
                    class="absolute top-1 left-1 size-5 rounded-full bg-surface shadow transition-transform duration-200 ease-out-soft"
                    :class="hidden && 'translate-x-5'"
                />
            </span>
        </button>

        <Link
            :href="route('logout')"
            method="post"
            as="button"
            class="mb-2 flex h-12 w-full items-center justify-center gap-2 rounded-2xl text-sm font-medium text-neg active:bg-sunken"
            @click.capture="open = false"
        >
            <LogOut class="size-4" /> Keluar
        </Link>
    </Sheet>
</template>
