<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { AlertTriangle, ChevronLeft, ExternalLink, Mic, Sparkles } from 'lucide-vue-next';
import { computed, reactive } from 'vue';
import { route } from 'ziggy-js';

/* Pemakaian AI (Gemini/Claude) yang dicatat Sisih + perkiraan sisa kuota harian dari batas yang diisi pengguna. */
const props = defineProps({
    provider: String,
    ready: Boolean,
    today: Object,
    models: Array,
    days: Array,
    features: Object,
    month: Object,
    resetAt: String,
    recent: Array,
});

const num = (n) => new Intl.NumberFormat('id-ID').format(n ?? 0);
const FEATURE = { assistant: { label: 'Asisten LAX', icon: Sparkles }, voice: { label: 'Suara LAX', icon: Mic } };
const STATUS = { ok: 'berhasil', rate_limited: 'kena batas', error: 'gagal' };

/* Batas harian (permintaan per hari) per model: diisi dari AI Studio, disimpan di perangkat ini. */
const LIMITS_KEY = 'sisih:ai-limits';
const limits = reactive((() => {
    try {
        return JSON.parse(localStorage.getItem(LIMITS_KEY) || '{}');
    } catch {
        return {};
    }
})());
function setLimit(model, value) {
    const n = Math.max(0, Math.round(Number(value) || 0));
    if (n) limits[model] = n;
    else delete limits[model];
    try {
        localStorage.setItem(LIMITS_KEY, JSON.stringify(limits));
    } catch {
        // abaikan
    }
}

const reset = computed(() => {
    const at = new Date(props.resetAt);
    const hours = Math.max(0, (at - Date.now()) / 36e5);
    return {
        time: new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit' }).format(at),
        left: hours >= 1 ? `${Math.floor(hours)} jam lagi` : `${Math.max(1, Math.round(hours * 60))} menit lagi`,
    };
});

const maxDay = computed(() => Math.max(1, ...props.days.map((d) => d.requests)));
const dayName = (iso) => new Intl.DateTimeFormat('id-ID', { weekday: 'short' }).format(new Date(`${iso}T12:00:00`));
const clock = (iso) => new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
</script>

<template>
    <Head title="Pemakaian AI" />

    <Link :href="route('settings')" class="-ml-1 mb-2 inline-flex items-center gap-1 text-[13px] text-muted md:hidden">
        <ChevronLeft class="size-4" /> Pengaturan
    </Link>
    <h1 class="page-title">Pemakaian AI</h1>
    <p class="mt-1 mb-6 text-small text-muted">
        {{ provider ? `Penyedia: ${provider}` : 'Belum ada kunci AI' }} · kuota harian Google direset pukul {{ reset.time }} waktumu
        ({{ reset.left }}; mengikuti tengah malam waktu Pasifik)
    </p>

    <div class="flex max-w-xl flex-col gap-5">
        <p v-if="!ready" class="card flex items-start gap-2 p-4 text-small text-warn">
            <AlertTriangle class="mt-px size-4 shrink-0" /> Tabel pencatatan belum dibuat. Jalankan migrasi database terlebih dahulu.
        </p>

        <!-- Ringkasan hari ini -->
        <section class="grid grid-cols-2 gap-3">
            <div class="card p-4">
                <p class="text-small text-muted">Permintaan hari ini</p>
                <p class="mt-1 text-[26px] font-semibold tracking-tight tnum">{{ num(today.requests) }}</p>
            </div>
            <div class="card p-4">
                <p class="text-small text-muted">Token hari ini</p>
                <p class="mt-1 text-[26px] font-semibold tracking-tight tnum">{{ num(today.tokens) }}</p>
            </div>
            <p v-if="today.limited" class="col-span-2 -mt-1 flex items-center gap-2 text-small text-neg">
                <AlertTriangle class="size-4" /> {{ num(today.limited) }}× kena batas kuota hari ini (LAX otomatis mencoba model lain)
            </p>
        </section>

        <!-- Kuota per model -->
        <section class="card p-4 md:p-5">
            <h2 class="section-title mb-1">Kuota per model · hari ini</h2>
            <p class="mb-4 text-small text-muted">Isi “batas/hari” dari halaman Rate limit di AI Studio agar sisa bisa diperkirakan.</p>
            <p v-if="!models.length" class="text-small text-muted">Belum ada model Gemini yang dipakai.</p>
            <ul class="flex flex-col gap-4">
                <li v-for="m in models" :key="m.model">
                    <div class="flex items-center gap-2">
                        <component :is="FEATURE[m.feature].icon" class="size-4 shrink-0 text-accent-text" />
                        <p class="min-w-0 flex-1 truncate text-[14px] font-medium">{{ m.model }}</p>
                        <label class="flex items-center gap-1.5 text-tiny text-muted">
                            batas/hari
                            <input
                                type="number"
                                inputmode="numeric"
                                min="0"
                                class="h-8 w-20 rounded-lg border border-line bg-surface px-2 text-right text-small text-ink tnum outline-none focus:border-accent-text"
                                :value="limits[m.model] ?? ''"
                                placeholder="—"
                                @change="setLimit(m.model, $event.target.value)"
                            />
                        </label>
                    </div>
                    <template v-if="limits[m.model]">
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-sunken">
                            <div
                                class="h-full rounded-full transition-[width] duration-500"
                                :class="m.requests / limits[m.model] >= 0.9 ? 'bg-neg' : m.requests / limits[m.model] >= 0.7 ? 'bg-warn' : 'bg-bar'"
                                :style="{ width: `${Math.min(100, (m.requests / limits[m.model]) * 100)}%` }"
                            />
                        </div>
                        <p class="mt-1.5 flex justify-between text-small text-muted tnum">
                            <span>{{ num(m.requests) }} / {{ num(limits[m.model]) }} permintaan</span>
                            <span :class="m.requests >= limits[m.model] && 'font-medium text-neg'">sisa ±{{ num(Math.max(0, limits[m.model] - m.requests)) }}</span>
                        </p>
                    </template>
                    <p v-else class="mt-1 text-small text-muted tnum">{{ num(m.requests) }} permintaan · {{ num(m.tokens) }} token</p>
                    <p v-if="m.limited" class="mt-0.5 text-small text-neg">Kena batas {{ num(m.limited) }}×</p>
                </li>
            </ul>
        </section>

        <!-- 7 hari -->
        <section class="card p-4 md:p-5">
            <h2 class="section-title mb-4">7 hari terakhir</h2>
            <div class="flex h-32 items-end gap-2">
                <div v-for="d in days" :key="d.date" class="flex h-full flex-1 flex-col items-center justify-end gap-1.5">
                    <span class="text-tiny text-muted tnum">{{ d.requests || '' }}</span>
                    <div class="w-full max-w-9 rounded-t-md bg-bar/70" :style="{ height: `${Math.max(d.requests ? 6 : 2, (d.requests / maxDay) * 100)}%` }" />
                    <span class="text-tiny text-muted">{{ dayName(d.date) }}</span>
                </div>
            </div>
        </section>

        <!-- 30 hari per fitur -->
        <section class="card p-4 md:p-5">
            <h2 class="section-title mb-3">30 hari terakhir</h2>
            <ul class="divide-y divide-line">
                <li v-for="(f, key) in features" :key="key" class="flex items-center gap-3 py-2.5">
                    <component :is="FEATURE[key].icon" class="size-4 text-accent-text" />
                    <span class="flex-1 text-[14px]">{{ FEATURE[key].label }}</span>
                    <span class="text-small text-muted tnum">{{ num(f.requests) }} permintaan · {{ num(f.tokens) }} token</span>
                </li>
                <li class="flex items-center justify-between py-2.5 text-[14px] font-medium">
                    <span>Total</span>
                    <span class="tnum">{{ num(month.requests) }} permintaan · {{ num(month.tokens) }} token</span>
                </li>
            </ul>
        </section>

        <!-- Riwayat terakhir -->
        <section v-if="recent.length" class="card p-4 md:p-5">
            <h2 class="section-title mb-2">Panggilan terakhir</h2>
            <ul class="divide-y divide-line">
                <li v-for="(r, i) in recent" :key="i" class="flex items-center gap-3 py-2.5">
                    <component :is="FEATURE[r.feature]?.icon ?? Sparkles" class="size-4 shrink-0 text-muted" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[13px] text-ink-2">{{ r.model }}</p>
                        <p class="text-tiny text-muted">
                            {{ clock(r.at) }}<span v-if="r.status !== 'ok'" class="text-neg"> · {{ STATUS[r.status] ?? r.status }}</span>
                        </p>
                    </div>
                    <span class="shrink-0 text-small text-muted tnum">{{ num(r.tokens) }} token</span>
                </li>
            </ul>
        </section>

        <section class="card p-4 text-small leading-relaxed text-muted md:p-5">
            <p>
                Google tidak menyediakan cara membaca sisa kuota lewat API, jadi angka di sini adalah pemakaian yang dicatat Sisih sendiri;
                “sisa” adalah perkiraan dari batas yang kamu isi. Pemakaian dari aplikasi lain dengan kunci yang sama tidak terhitung.
                Suara yang sama diputar ulang dari simpanan sementara, jadi tidak selalu memakai kuota.
            </p>
            <a
                href="https://aistudio.google.com/usage"
                target="_blank"
                rel="noopener"
                class="mt-3 inline-flex items-center gap-1.5 font-medium text-accent-text"
            >
                Lihat angka resmi di Google AI Studio <ExternalLink class="size-3.5" />
            </a>
        </section>
    </div>
</template>
