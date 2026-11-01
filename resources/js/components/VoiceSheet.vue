<script setup>
import { router, usePage } from '@inertiajs/vue3';
import {
    AlertCircle, ArrowDownLeft, ArrowRightLeft, ArrowUpRight, Info, Mic, Pencil, RotateCcw, Sparkles, Square, X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { route } from 'ziggy-js';
import IconTile from '@/components/ui/IconTile.vue';
import Money from '@/components/ui/Money.vue';
import Sheet from '@/components/ui/Sheet.vue';
import { alertBudgets, snapshotBudgets } from '@/composables/useBudgetAlert';
import { celebrate } from '@/composables/useCelebration';
import { useLedger } from '@/composables/useLedger';
import { openQuickAdd, useQuickAdd } from '@/composables/useQuickAdd';
import { closeVoice, useVoice } from '@/composables/useVoice';
import { dayLabel } from '@/lib/dates';
import { postJson } from '@/lib/http';
import { categoryIcon } from '@/lib/icons';

/* ---------------------------------------------------------------
   Catat lewat suara: bicara (atau ketik) bebas → AI (Gemini/Claude) mengurai jadi
   draf → pengguna meninjau → simpan. Server memvalidasi di kedua langkah.
   --------------------------------------------------------------- */
const state = useVoice();
const quick = useQuickAdd();
const page = usePage();
const { accountById, categoryById, categories } = useLedger();

const phase = ref('input'); // input | loading | review
const text = ref('');
const error = ref('');
const drafts = ref([]);
const unclear = ref('');
const saving = ref(false);
const generalError = ref('');

/* ---- Pengenal suara bawaan browser (Chrome Android, Safari iOS) ---- */
const Recognition = typeof window !== 'undefined' ? window.SpeechRecognition || window.webkitSpeechRecognition : null;
const listening = ref(false);
let recognizer = null;
let base = '';

const SPEECH_ERRORS = {
    'not-allowed': 'Izin mikrofon ditolak. Izinkan di pengaturan browser, atau ketik saja.',
    'service-not-allowed': 'Rekam suara tidak tersedia di sini. Ketik, atau pakai mikrofon di keyboard HP.',
    'no-speech': 'Tidak terdengar suara. Coba lagi lebih dekat ke mikrofon.',
    'audio-capture': 'Mikrofon tidak ditemukan.',
    network: 'Pengenal suara butuh koneksi internet.',
};

function startListening() {
    if (!Recognition || listening.value) return;
    error.value = '';
    // Ucapan baru ditambahkan di belakang teks yang sudah ada (bisa bicara beberapa kali).
    base = text.value.trim() ? `${text.value.trim()} ` : '';
    recognizer = new Recognition();
    recognizer.lang = 'id-ID';
    recognizer.interimResults = true;
    // continuous=false: Chrome Android mengulang hasil bila continuous; berhenti sendiri setelah jeda.
    recognizer.continuous = false;
    recognizer.onresult = (e) => {
        let heard = '';
        for (let i = 0; i < e.results.length; i++) heard += e.results[i][0].transcript;
        text.value = base + heard;
    };
    recognizer.onerror = (e) => {
        if (e.error !== 'aborted') error.value = SPEECH_ERRORS[e.error] ?? 'Rekam suara gagal. Coba lagi atau ketik.';
    };
    recognizer.onend = () => {
        listening.value = false;
        recognizer = null;
    };
    try {
        recognizer.start();
        listening.value = true;
        navigator.vibrate?.(8);
    } catch {
        listening.value = false;
    }
}

function stopListening() {
    recognizer?.stop();
}

onBeforeUnmount(() => recognizer?.abort());

/* ---- Buka / tutup ---- */
watch(
    () => state.nonce,
    () => {
        phase.value = 'input';
        text.value = '';
        error.value = '';
        drafts.value = [];
        unclear.value = '';
        generalError.value = '';
    },
);
watch(
    () => state.open,
    (open) => {
        if (!open) recognizer?.abort();
    },
);

/* ---- Langkah 1: urai lewat Claude ---- */
function lastAccount() {
    try {
        return JSON.parse(localStorage.getItem('sisih:last') || '{}').account ?? null;
    } catch {
        return null;
    }
}

async function process() {
    if (!text.value.trim() || phase.value === 'loading') return;
    // Masih merekam: hentikan lalu beri jeda agar kalimat terakhir sempat jadi hasil final.
    if (listening.value) {
        stopListening();
        await new Promise((r) => setTimeout(r, 500));
    }
    const spoken = text.value.trim();
    if (!navigator.onLine) {
        error.value = 'Sedang offline. Catat lewat suara butuh internet.';
        return;
    }
    error.value = '';
    phase.value = 'loading';

    let response;
    try {
        response = await postJson(route('transactions.parse'), { text: spoken, default_account_id: lastAccount() });
    } catch {
        phase.value = 'input';
        error.value = 'Gagal terhubung ke server. Coba lagi.';
        return;
    }
    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        phase.value = 'input';
        error.value =
            {
                401: 'Sesi habis. Muat ulang halaman lalu masuk lagi.',
                419: 'Sesi habis. Muat ulang halaman lalu coba lagi.',
                429: 'Terlalu sering. Tunggu sebentar lalu coba lagi.',
            }[response.status] ??
            Object.values(body.errors ?? {})[0]?.[0] ??
            body.message ??
            'Gagal memproses. Coba lagi.';
        return;
    }

    drafts.value = (body.items ?? []).map((d, i) => ({ ...d, key: `${Date.now()}-${i}`, serverError: '' }));
    unclear.value = body.unclear ?? '';
    phase.value = drafts.value.length ? 'review' : 'input';
    if (!drafts.value.length) error.value = unclear.value || 'Tidak ada transaksi yang dikenali. Coba sebutkan apa dan berapa.';
    navigator.vibrate?.(10);
}

/* ---- Tampilan draf ---- */
const TYPE_LABEL = { expense: 'Pengeluaran', income: 'Pemasukan', transfer: 'Transfer' };
const TYPE_ICON = { expense: ArrowUpRight, income: ArrowDownLeft, transfer: ArrowRightLeft };

function view(d) {
    const category = categoryById.value[d.category_id];
    const from = accountById.value[d.account_id]?.name;
    const to = accountById.value[d.to_account_id]?.name;
    return {
        icon: category ? categoryIcon(category.icon) : TYPE_ICON[d.type],
        color: category?.color ?? 'slate',
        title: d.note || category?.name || TYPE_LABEL[d.type],
        meta: [
            d.note && category?.name,
            d.type === 'transfer' ? `${from ?? '?'} → ${to ?? '?'}` : from,
            dayLabel(d.occurred_on),
        ].filter(Boolean),
        uncategorized: d.type !== 'transfer' && !category,
    };
}

const blocked = computed(() => drafts.value.some((d) => d.issues.length || d.serverError));
const total = computed(() => drafts.value.reduce((s, d) => s + (d.type === 'expense' ? -d.amount : d.type === 'income' ? d.amount : 0), 0));

function remove(d) {
    drafts.value = drafts.value.filter((x) => x !== d);
    if (!drafts.value.length) phase.value = 'input';
}

/* Ubah lewat form cepat (semua validasi & isian lengkap ada di sana), lalu kembali ke sini. */
let resume = false;
function edit(d) {
    remove(d);
    resume = drafts.value.length > 0;
    closeVoice();
    openQuickAdd({ template: { ...d, fromVoice: true } });
}
watch(
    () => quick.open,
    (open) => {
        if (open || !resume) return;
        resume = false;
        if (drafts.value.length) {
            phase.value = 'review';
            state.open = true;
        }
    },
);

function again() {
    phase.value = 'input';
    drafts.value = [];
    unclear.value = '';
    generalError.value = '';
}

/* ---- Langkah 2: simpan semua sekaligus ---- */
function save() {
    if (blocked.value || saving.value || !drafts.value.length) return;
    const items = drafts.value.map(({ type, amount, account_id, to_account_id, category_id, note, occurred_on }) => ({
        type, amount, account_id, to_account_id, category_id, note, occurred_on,
    }));
    const single = items.length === 1 ? view(drafts.value[0]) : null;
    const before = snapshotBudgets(categories.value);
    saving.value = true;
    generalError.value = '';

    router.post(route('transactions.storeMany'), { items }, {
        preserveScroll: true,
        onSuccess: (p) => {
            closeVoice();
            if (single) {
                celebrate({
                    type: items[0].type,
                    amount: items[0].amount,
                    label: { expense: 'Pengeluaran tercatat', income: 'Pemasukan tercatat', transfer: 'Transfer tercatat' }[items[0].type],
                    subtitle: single.meta.slice(0, -1).join(' · ') || single.title,
                    icon: single.icon,
                    color: single.color,
                });
            }
            alertBudgets(before, p.props.categories);
        },
        onError: (errors) => {
            for (const [key, message] of Object.entries(errors)) {
                const match = key.match(/^items\.(\d+)\./);
                if (match && drafts.value[match[1]]) drafts.value[match[1]].serverError = message;
                else generalError.value = message;
            }
        },
        onFinish: () => (saving.value = false),
    });
}

const voiceAvailable = computed(() => Boolean(page.props.features?.voice));
const provider = computed(() => page.props.features?.voiceProvider ?? 'AI');
const providerOwner = computed(() => ({ Gemini: 'Google', Claude: 'Anthropic' })[provider.value]);
</script>

<template>
    <Sheet :open="state.open && voiceAvailable" title="Catat lewat suara" @close="closeVoice">
        <!-- Langkah 1: bicara / ketik -->
        <div v-if="phase !== 'review'" class="pb-2">
            <div v-if="Recognition" class="flex flex-col items-center pt-2 pb-5">
                <button
                    type="button"
                    class="relative flex size-20 items-center justify-center rounded-full bg-gradient-to-br from-(--hero-from) to-(--hero-to) text-white shadow-[0_14px_30px_-12px_var(--hero-from)] transition active:scale-95 disabled:opacity-50"
                    :aria-label="listening ? 'Berhenti merekam' : 'Mulai bicara'"
                    :aria-pressed="listening"
                    :disabled="phase === 'loading'"
                    @click="listening ? stopListening() : startListening()"
                >
                    <span v-if="listening" class="absolute inset-0 animate-ping rounded-full bg-(--hero-to) opacity-40" aria-hidden="true" />
                    <Square v-if="listening" class="relative size-6 fill-current" />
                    <Mic v-else class="relative size-8" :stroke-width="2" />
                </button>
                <p class="mt-3 text-sm font-medium" :class="listening ? 'text-accent-text' : 'text-ink-2'">
                    {{ listening ? 'Mendengarkan… ketuk untuk berhenti' : text ? 'Ketuk untuk menambah ucapan' : 'Ketuk lalu ceritakan transaksimu' }}
                </p>
            </div>

            <label class="field-label" for="voice-text">{{ Recognition ? 'Yang terdengar (bisa diubah)' : 'Ceritakan transaksimu' }}</label>
            <textarea
                id="voice-text"
                v-model="text"
                rows="3"
                maxlength="1000"
                class="field h-auto resize-none py-3 leading-relaxed"
                placeholder="Misal: tadi makan bakso 25 ribu pakai cash, terus isi bensin 50rb"
                :disabled="phase === 'loading'"
            />
            <p v-if="!Recognition" class="mt-2 text-small text-muted">
                Browser ini belum mendukung rekam suara langsung. Pakai tombol mikrofon di keyboard HP-mu untuk mendikte.
            </p>

            <p v-if="error" class="mt-3 flex items-start gap-2 rounded-xl bg-neg/8 px-3 py-2.5 text-small text-neg" role="alert">
                <AlertCircle class="mt-px size-4 shrink-0" /> {{ error }}
            </p>

            <!-- Sedang diurai -->
            <div v-if="phase === 'loading'" class="mt-5" aria-live="polite">
                <p class="mb-3 flex items-center gap-2 text-small font-medium text-accent-text">
                    <Sparkles class="size-4 animate-pulse" /> {{ provider }} sedang membaca catatanmu…
                </p>
                <div v-for="n in 2" :key="n" class="mb-2 flex animate-pulse items-center gap-3 rounded-2xl border border-line p-3">
                    <span class="size-10 rounded-xl bg-sunken" />
                    <span class="flex-1 space-y-2">
                        <span class="block h-3 w-2/5 rounded bg-sunken" />
                        <span class="block h-2.5 w-3/5 rounded bg-sunken" />
                    </span>
                    <span class="h-3.5 w-16 rounded bg-sunken" />
                </div>
            </div>

            <p v-else class="mt-4 text-tiny leading-relaxed text-muted">
                Teks dikirim ke {{ provider }}<template v-if="providerOwner"> ({{ providerOwner }})</template> untuk diurai jadi draf.
                Tidak ada yang disimpan sebelum kamu menekan Simpan.
            </p>
        </div>

        <!-- Langkah 2: tinjau draf -->
        <div v-else class="pb-2">
            <div class="mb-3 flex items-start gap-2 rounded-2xl bg-sunken px-3.5 py-3">
                <p class="min-w-0 flex-1 text-small leading-relaxed text-ink-2 italic">“{{ text.trim() }}”</p>
                <button type="button" class="icon-btn -my-1 -mr-1.5 size-8 shrink-0" aria-label="Ulangi" title="Ulangi" @click="again">
                    <RotateCcw class="size-4" />
                </button>
            </div>

            <p v-if="unclear" class="mb-3 flex items-start gap-2 text-small text-warn">
                <Info class="mt-px size-4 shrink-0" /> {{ unclear }}
            </p>

            <TransitionGroup tag="ul" name="list" class="relative flex flex-col gap-2">
                <li
                    v-for="d in drafts"
                    :key="d.key"
                    class="rounded-2xl border bg-surface p-3"
                    :class="d.issues.length || d.serverError ? 'border-neg/40' : 'border-line'"
                >
                    <div class="flex items-center gap-3">
                        <IconTile :icon="view(d).icon" :color="view(d).color" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[15px] font-medium">{{ view(d).title }}</p>
                            <p class="truncate text-small text-muted first-letter:uppercase">{{ view(d).meta.join(' · ') }}</p>
                        </div>
                        <Money
                            :value="d.type === 'expense' ? -d.amount : d.amount"
                            :sign="d.type !== 'transfer'"
                            class="shrink-0 text-[15px] font-semibold tnum"
                            :class="d.type === 'income' ? 'text-pos' : d.type === 'transfer' ? 'text-ink-2' : ''"
                        />
                    </div>

                    <ul v-if="d.issues.length || d.serverError || d.notes.length || view(d).uncategorized" class="mt-2 space-y-1 pl-[52px] text-small">
                        <li v-for="issue in d.issues" :key="issue" class="flex items-center gap-1.5 text-neg">
                            <AlertCircle class="size-3.5 shrink-0" /> {{ issue }} — ketuk Ubah
                        </li>
                        <li v-if="d.serverError" class="flex items-center gap-1.5 text-neg">
                            <AlertCircle class="size-3.5 shrink-0" /> {{ d.serverError }}
                        </li>
                        <li v-for="note in d.notes" :key="note" class="text-muted">{{ note }}</li>
                        <li v-if="view(d).uncategorized" class="text-muted">Tanpa kategori</li>
                    </ul>

                    <div class="mt-2 flex justify-end gap-1 pl-[52px]">
                        <button type="button" class="btn btn-ghost h-9 px-3 text-[13px]" @click="edit(d)">
                            <Pencil class="size-3.5" /> Ubah
                        </button>
                        <button type="button" class="btn btn-ghost h-9 px-3 text-[13px] text-muted" @click="remove(d)">
                            <X class="size-3.5" /> Buang
                        </button>
                    </div>
                </li>
            </TransitionGroup>

            <p v-if="drafts.length > 1" class="mt-3 flex justify-between px-1 text-small text-muted">
                <span>{{ drafts.length }} transaksi</span>
                <span>Selisih <Money :value="total" sign class="font-medium" :class="total >= 0 ? 'text-pos' : 'text-neg'" /></span>
            </p>
            <p v-if="generalError" class="field-error">{{ generalError }}</p>
        </div>

        <template #footer>
            <button
                v-if="phase !== 'review'"
                type="button"
                class="btn btn-primary h-12 w-full text-[15px]"
                :disabled="!text.trim() || phase === 'loading'"
                @click="process"
            >
                <Sparkles class="size-4" />
                {{ phase === 'loading' ? 'Memproses…' : 'Proses' }}
            </button>
            <button
                v-else
                type="button"
                class="btn btn-primary h-12 w-full text-[15px]"
                :disabled="blocked || saving"
                @click="save"
            >
                {{ saving ? 'Menyimpan…' : drafts.length > 1 ? `Simpan ${drafts.length} transaksi` : 'Simpan' }}
            </button>
            <p v-if="phase === 'review' && blocked" class="mt-2 text-center text-small text-muted">Perbaiki yang bertanda merah dulu.</p>
        </template>
    </Sheet>
</template>
