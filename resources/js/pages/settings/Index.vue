<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ChevronRight, Layers, LogOut, Repeat } from 'lucide-vue-next';
import { computed } from 'vue';
import { route } from 'ziggy-js';
import Segmented from '@/components/ui/Segmented.vue';
import { setTheme, useTheme } from '@/composables/useTheme';
import { periodOf, periodRangeLabel, today } from '@/lib/dates';

const page = usePage();
const theme = useTheme();

const profile = useForm({
    name: page.props.auth.user.name,
    email: page.props.auth.user.email,
});
/* ---- Awal periode (tanggal gajian) ---- */
const periodForm = useForm({ period_start: page.props.period?.startDay ?? 1 });
const periodPreview = computed(() => {
    const day = Number(periodForm.period_start);
    return periodRangeLabel(periodOf(today(), day), day);
});
function savePeriod() {
    periodForm.put(route('settings.period'), { preserveScroll: true, onSuccess: () => periodForm.defaults() });
}

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
    ['← →', 'Ganti periode'],
    ['H', 'Sembunyikan / tampilkan nominal'],
    ['G lalu B / T / A', 'Ke Beranda / Transaksi / Akun'],
    ['Enter', 'Simpan form'],
    ['Esc', 'Tutup'],
];
</script>

<template>
    <Head title="Pengaturan" />

    <h1 class="page-title mb-6">Pengaturan</h1>

    <div class="flex max-w-xl flex-col gap-5">
        <!-- Pintasan untuk HP (di desktop sudah ada di sidebar) -->
        <nav class="card divide-y divide-line overflow-hidden md:hidden">
            <Link :href="route('categories.index')" class="flex items-center gap-3 px-4 py-3.5 active:bg-sunken">
                <span class="grid size-9 place-items-center rounded-xl bg-accent-soft text-accent-text"><Layers class="size-[18px]" /></span>
                <span class="flex-1 text-[15px] font-medium">Kategori</span>
                <ChevronRight class="size-4 text-muted" />
            </Link>
            <Link :href="route('recurring.index')" class="flex items-center gap-3 px-4 py-3.5 active:bg-sunken">
                <span class="grid size-9 place-items-center rounded-xl bg-accent-soft text-accent-text"><Repeat class="size-[18px]" /></span>
                <span class="flex-1 text-[15px] font-medium">Transaksi berulang</span>
                <ChevronRight class="size-4 text-muted" />
            </Link>
        </nav>

        <section class="card p-5">
            <h2 class="section-title mb-4">Tampilan</h2>
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

        <section class="card p-5">
            <h2 class="section-title mb-1">Awal periode</h2>
            <p class="mb-4 text-small text-muted">
                Tanggal gajian. Ringkasan bulanan, anggaran dan jatah harian dihitung dari tanggal ini sampai sehari sebelum tanggal yang sama bulan berikutnya.
            </p>
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="savePeriod">
                <label class="block">
                    <span class="field-label">Mulai tanggal</span>
                    <select v-model.number="periodForm.period_start" class="field w-36">
                        <option v-for="d in 28" :key="d" :value="d">{{ d === 1 ? '1 (awal bulan)' : d }}</option>
                    </select>
                </label>
                <button type="submit" class="btn btn-primary h-11" :disabled="periodForm.processing || !periodForm.isDirty">Simpan</button>
            </form>
            <p class="mt-2.5 text-small text-muted">
                Periode sekarang: <span class="font-medium text-ink-2 tnum">{{ periodPreview }}</span>
            </p>
            <p v-if="periodForm.errors.period_start" class="field-error">{{ periodForm.errors.period_start }}</p>
        </section>

        <section class="card p-5">
            <h2 class="section-title mb-4">Profil</h2>
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

        <section class="card p-5">
            <h2 class="section-title mb-4">Kata sandi</h2>
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

        <section class="card hidden p-5 pointer:block">
            <h2 class="section-title mb-4">Pintasan keyboard</h2>
            <dl class="-mx-5 -mb-5 divide-y divide-line border-t border-line text-sm">
                <div v-for="[keys, label] in shortcuts" :key="keys" class="flex items-center justify-between px-4 py-2.5">
                    <dt class="text-ink-2">{{ label }}</dt>
                    <dd><kbd class="rounded-md border border-line bg-sunken px-1.5 py-0.5 font-mono text-small">{{ keys }}</kbd></dd>
                </div>
            </dl>
        </section>

        <Link :href="route('logout')" method="post" as="button" class="card flex h-12 items-center justify-center gap-2 text-sm font-medium text-neg transition-colors hover:bg-sunken">
            <LogOut class="size-4" /> Keluar
        </Link>
    </div>
</template>
