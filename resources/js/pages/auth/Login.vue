<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { Eye, EyeOff } from 'lucide-vue-next';
import { ref } from 'vue';
import { route } from 'ziggy-js';

const form = useForm({ email: '', password: '' });
const reveal = ref(false);

function submit() {
    form.post(route('login.store'), { onFinish: () => form.reset('password') });
}

// Potongan "buku kas" dekoratif di sisi kiri (desktop).
const ledger = [
    ['Kopi susu', '28.000', 'expense'],
    ['Gaji Oktober', '8.500.000', 'income'],
    ['KRL', '6.000', 'expense'],
    ['Belanja mingguan', '312.500', 'expense'],
    ['Isi saldo e-wallet', '500.000', 'transfer'],
];
</script>

<template>
    <Head title="Masuk" />

    <div class="grid min-h-dvh lg:grid-cols-[1.1fr_1fr]">
        <!-- Sisi kiri: pernyataan + buku kas -->
        <aside class="relative hidden flex-col justify-between overflow-hidden border-r border-line p-12 lg:flex">
            <div class="flex items-center gap-2.5">
                <span class="grid size-7 place-items-center rounded-lg bg-accent text-accent-fg">
                    <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                        <path d="M6 8.5c0-1.4 1.6-2.5 6-2.5s6 1.1 6 2.5S16.4 11 12 12s-6 2.1-6 3.5S7.6 18 12 18s6-1.1 6-2.5" />
                    </svg>
                </span>
                <span class="text-[17px] font-semibold tracking-tight">Saku</span>
            </div>

            <div>
                <p class="max-w-md text-[44px] leading-[1.05] font-semibold tracking-[-0.04em]">
                    Setiap rupiah,<br />
                    <span class="text-muted">tercatat dengan tenang.</span>
                </p>

                <ul class="mt-12 max-w-md">
                    <li
                        v-for="([label, amount, type], i) in ledger"
                        :key="label"
                        class="ledger-row flex items-baseline gap-3 border-b border-dashed border-line py-3 text-[15px]"
                        :style="{ animationDelay: `${120 + i * 90}ms` }"
                    >
                        <span class="text-ink-2">{{ label }}</span>
                        <span class="flex-1" />
                        <span class="tnum" :class="type === 'income' ? 'text-pos' : type === 'transfer' ? 'text-muted' : ''">
                            {{ type === 'income' ? '+' : type === 'expense' ? '−' : '' }}Rp {{ amount }}
                        </span>
                    </li>
                </ul>
            </div>

            <p class="text-[13px] text-muted">Buku kas pribadi.</p>
        </aside>

        <!-- Form -->
        <main class="flex items-center justify-center px-6 py-12">
            <form class="w-full max-w-[360px]" @submit.prevent="submit">
                <div class="mb-10 flex items-center gap-2.5 lg:hidden">
                    <span class="grid size-8 place-items-center rounded-lg bg-accent text-accent-fg">
                        <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                            <path d="M6 8.5c0-1.4 1.6-2.5 6-2.5s6 1.1 6 2.5S16.4 11 12 12s-6 2.1-6 3.5S7.6 18 12 18s6-1.1 6-2.5" />
                        </svg>
                    </span>
                    <span class="text-[19px] font-semibold tracking-tight">Saku</span>
                </div>

                <h1 class="mb-1.5 text-[26px] font-semibold tracking-[-0.025em]">Masuk</h1>
                <p class="mb-8 text-[15px] text-muted">Lanjutkan mencatat keuanganmu.</p>

                <div class="mb-4">
                    <label class="field-label" for="email">Email</label>
                    <input id="email" v-model="form.email" type="email" class="field h-12" autocomplete="email" autofocus required />
                    <p v-if="form.errors.email" class="field-error">{{ form.errors.email }}</p>
                </div>

                <div class="mb-8">
                    <label class="field-label" for="password">Kata sandi</label>
                    <div class="relative">
                        <input
                            id="password"
                            v-model="form.password"
                            :type="reveal ? 'text' : 'password'"
                            class="field h-12 pr-12"
                            autocomplete="current-password"
                            required
                        />
                        <button
                            type="button"
                            class="icon-btn absolute top-1/2 right-1.5 -translate-y-1/2"
                            :aria-label="reveal ? 'Sembunyikan' : 'Tampilkan'"
                            @click="reveal = !reveal"
                        >
                            <EyeOff v-if="reveal" class="size-[18px]" />
                            <Eye v-else class="size-[18px]" />
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary h-12 w-full text-[15px]" :disabled="form.processing">
                    {{ form.processing ? 'Memeriksa…' : 'Masuk' }}
                </button>
            </form>
        </main>
    </div>
</template>

<style scoped>
.ledger-row {
    animation: rise 0.5s var(--ease-out-soft) both;
}
</style>
