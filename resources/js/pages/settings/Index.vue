<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ChevronRight, Layers, LogOut } from 'lucide-vue-next';
import { route } from 'ziggy-js';
import Segmented from '@/components/ui/Segmented.vue';
import { setTheme, useTheme } from '@/composables/useTheme';

const page = usePage();
const theme = useTheme();

const profile = useForm({
    name: page.props.auth.user.name,
    email: page.props.auth.user.email,
});
const password = useForm({ current_password: '', password: '', password_confirmation: '' });

function saveProfile() {
    profile.put(route('settings.profile'), { preserveScroll: true });
}
function savePassword() {
    password.put(route('settings.password'), {
        preserveScroll: true,
        onSuccess: () => password.reset(),
    });
}

const shortcuts = [
    ['N', 'Catat transaksi'],
    ['Ctrl K', 'Perintah & pencarian'],
    ['/', 'Cari di halaman transaksi'],
    ['← →', 'Ganti bulan'],
    ['H', 'Sembunyikan / tampilkan nominal'],
    ['G lalu B / T / A', 'Ke Beranda / Transaksi / Akun'],
    ['Enter', 'Simpan form'],
    ['Esc', 'Tutup'],
];
</script>

<template>
    <Head title="Pengaturan" />

    <h1 class="mb-8 text-[26px] font-semibold tracking-[-0.025em] md:text-[30px]">Pengaturan</h1>

    <div class="flex max-w-xl flex-col gap-10">
        <!-- Pintasan untuk HP (di desktop sudah ada di sidebar) -->
        <Link
            :href="route('categories.index')"
            class="flex items-center gap-3 rounded-2xl border border-line bg-surface p-4 md:hidden"
        >
            <Layers class="size-5 text-ink-2" />
            <span class="flex-1 text-[15px]">Kategori</span>
            <ChevronRight class="size-4 text-muted" />
        </Link>

        <section>
            <h2 class="mb-3 text-[15px] font-medium">Tampilan</h2>
            <Segmented
                :model-value="theme"
                :options="[
                    { value: 'system', label: 'Ikuti sistem' },
                    { value: 'light', label: 'Terang' },
                    { value: 'dark', label: 'Gelap' },
                ]"
                @update:model-value="setTheme"
            />
        </section>

        <section>
            <h2 class="mb-3 text-[15px] font-medium">Profil</h2>
            <form class="flex flex-col gap-4" @submit.prevent="saveProfile">
                <div>
                    <label class="field-label" for="name">Nama</label>
                    <input id="name" v-model="profile.name" class="field" autocomplete="name" />
                    <p v-if="profile.errors.name" class="field-error">{{ profile.errors.name }}</p>
                </div>
                <div>
                    <label class="field-label" for="email">Email</label>
                    <input id="email" v-model="profile.email" type="email" class="field" autocomplete="email" />
                    <p v-if="profile.errors.email" class="field-error">{{ profile.errors.email }}</p>
                </div>
                <div>
                    <button class="btn btn-quiet" :disabled="profile.processing || !profile.isDirty">Simpan profil</button>
                </div>
            </form>
        </section>

        <section>
            <h2 class="mb-3 text-[15px] font-medium">Kata sandi</h2>
            <form class="flex flex-col gap-4" @submit.prevent="savePassword">
                <div>
                    <label class="field-label" for="current_password">Kata sandi saat ini</label>
                    <input id="current_password" v-model="password.current_password" type="password" class="field" autocomplete="current-password" />
                    <p v-if="password.errors.current_password" class="field-error">{{ password.errors.current_password }}</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="field-label" for="password">Kata sandi baru</label>
                        <input id="password" v-model="password.password" type="password" class="field" autocomplete="new-password" />
                        <p v-if="password.errors.password" class="field-error">{{ password.errors.password }}</p>
                    </div>
                    <div>
                        <label class="field-label" for="password_confirmation">Ulangi</label>
                        <input id="password_confirmation" v-model="password.password_confirmation" type="password" class="field" autocomplete="new-password" />
                    </div>
                </div>
                <div>
                    <button class="btn btn-quiet" :disabled="password.processing || !password.password">Ganti kata sandi</button>
                </div>
            </form>
        </section>

        <section class="hidden pointer:block">
            <h2 class="mb-3 text-[15px] font-medium">Pintasan keyboard</h2>
            <dl class="divide-y divide-line rounded-2xl border border-line bg-surface text-sm">
                <div v-for="[keys, label] in shortcuts" :key="keys" class="flex items-center justify-between px-4 py-2.5">
                    <dt class="text-ink-2">{{ label }}</dt>
                    <dd><kbd class="rounded-md border border-line bg-sunken px-1.5 py-0.5 font-mono text-[12px]">{{ keys }}</kbd></dd>
                </div>
            </dl>
        </section>

        <Link :href="route('logout')" method="post" as="button" class="btn btn-ghost self-start px-0 text-neg hover:bg-transparent hover:text-neg">
            <LogOut class="size-4" /> Keluar
        </Link>
    </div>
</template>
