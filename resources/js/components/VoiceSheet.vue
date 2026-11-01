<script setup>
import { router, usePage } from '@inertiajs/vue3';
import {
    AlertCircle, Archive, ArchiveRestore, ArrowDownLeft, ArrowRightLeft, ArrowUpRight, Info, Mic, Pencil, PencilLine,
    Plus, RotateCcw, Scale, SlidersHorizontal, Sparkles, Square, Volume2, VolumeX, X,
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
import { primeSpeech, speak, stopSpeaking, toggleSpeech, useSpeech } from '@/composables/useSpeech';
import { closeVoice, useVoice } from '@/composables/useVoice';
import { dayLabel } from '@/lib/dates';
import { postJson } from '@/lib/http';
import { ACCOUNT_TYPES, accountIcon, categoryIcon } from '@/lib/icons';

/* ---------------------------------------------------------------
   Asisten suara: bicara (atau ketik) bebas → AI (Gemini/Claude) mengurai jadi
   jawaban + usulan transaksi + usulan perubahan akun → pengguna meninjau →
   jalankan. Server memvalidasi di kedua langkah; AI tidak pernah menyimpan sendiri.
   --------------------------------------------------------------- */
const state = useVoice();
const quick = useQuickAdd();
const page = usePage();
const { accountById, categoryById, categories } = useLedger();

const phase = ref('input'); // input | loading | review
const text = ref('');
const error = ref('');
const reply = ref('');
const encouragement = ref(''); // semangat/saran untuk diucapkan, tanpa angka
const speech = useSpeech();
const drafts = ref([]); // usulan transaksi
const actions = ref([]); // usulan perubahan akun
const unclear = ref('');
const saving = ref(false);
const generalError = ref('');

const EXAMPLES = [
    'Makan siang 25 ribu pakai cash',
    'Saldo dompet sekarang tinggal 150 ribu',
    'Buat akun Jago, saldo awal 500 ribu',
    'Berapa total saldoku?',
];

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
    stopSpeaking(); // jangan sampai suara asisten ikut terekam
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
function clearResult() {
    reply.value = '';
    encouragement.value = '';
    drafts.value = [];
    actions.value = [];
    unclear.value = '';
    generalError.value = '';
}
watch(
    () => state.nonce,
    () => {
        phase.value = 'input';
        text.value = '';
        error.value = '';
        clearResult();
    },
);
watch(
    () => state.open,
    (open) => {
        if (!open) recognizer?.abort();
    },
);

/* ---- Langkah 1: urai lewat AI ---- */
function lastAccount() {
    try {
        return JSON.parse(localStorage.getItem('sisih:last') || '{}').account ?? null;
    } catch {
        return null;
    }
}

const hasProposals = computed(() => drafts.value.length + actions.value.length > 0);

async function process() {
    if (!text.value.trim() || phase.value === 'loading') return;
    primeSpeech();
    // Masih merekam: hentikan lalu beri jeda agar kalimat terakhir sempat jadi hasil final.
    if (listening.value) {
        stopListening();
        await new Promise((r) => setTimeout(r, 500));
    }
    const spoken = text.value.trim();
    if (!navigator.onLine) {
        error.value = 'Sedang offline. Asisten butuh internet.';
        return;
    }
    error.value = '';
    phase.value = 'loading';

    let response;
    try {
        response = await postJson(route('assistant.parse'), { text: spoken, default_account_id: lastAccount() });
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

    const stamp = Date.now();
    reply.value = body.reply ?? '';
    encouragement.value = body.encouragement ?? '';
    drafts.value = (body.items ?? []).map((d, i) => ({ ...d, key: `t${stamp}-${i}`, serverError: '' }));
    actions.value = (body.account_actions ?? []).map((a, i) => ({ ...a, key: `a${stamp}-${i}`, serverError: '' }));
    unclear.value = body.unclear ?? '';
    if (hasProposals.value || reply.value) {
        phase.value = 'review';
        // Nominal tidak pernah dibacakan: jawaban berangka cukup tampil di layar.
        speak(hasProposals.value ? 'Sudah kusiapkan. Cek dulu ya, lalu tekan jalankan.' : joinSpeech(['Jawabannya ada di layar.', encouragement.value]));
    } else {
        phase.value = 'input';
        error.value = unclear.value || 'Tidak ada yang bisa dikerjakan dari ucapan itu. Coba sebutkan apa dan berapa.';
    }
    navigator.vibrate?.(10);
}

/* ---- Kalimat konfirmasi yang diucapkan (tanpa nominal) ---- */
const joinSpeech = (parts) => parts.filter(Boolean).join(' ');

function confirmation(items, accountActions) {
    const kind = { expense: 'pengeluaran', income: 'pemasukan', transfer: 'transfer' };
    const parts = [];
    if (items.length === 1) parts.push(`${kind[items[0].type]} sudah dicatat`);
    else if (items.length > 1) parts.push(`${items.length} transaksi sudah dicatat`);
    for (const a of accountActions) {
        const name = a.account_name ?? accountById.value[a.account_id]?.name ?? 'akun';
        parts.push({
            create: `akun ${a.name} sudah dibuat`,
            rename: `nama akun sudah diganti jadi ${a.name}`,
            adjust_balance: `saldo ${name} sudah disesuaikan`,
            archive: `akun ${name} sudah diarsipkan`,
            unarchive: `akun ${name} sudah aktif lagi`,
        }[a.action]);
    }
    const sentence = parts.length > 1 ? `${parts.slice(0, -1).join(', ')} dan ${parts.at(-1)}` : parts[0];
    return `Siap, ${sentence}.`;
}

/* ---- Tampilan usulan transaksi ---- */
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

/* ---- Tampilan usulan perubahan akun ---- */
const ACCOUNT_COLORS = { cash: 'moss', bank: 'ink', ewallet: 'iris', other: 'slate' }; // = VoiceAssistant::ACCOUNT_COLORS
const ACTION_ICON = { rename: PencilLine, adjust_balance: Scale, archive: Archive, unarchive: ArchiveRestore };

function actionView(a) {
    const account = accountById.value[a.account_id];
    const name = a.account_name ?? account?.name ?? 'akun';
    return {
        icon: a.action === 'create' ? accountIcon(a.type) ?? Plus : ACTION_ICON[a.action],
        color: a.action === 'create' ? ACCOUNT_COLORS[a.type] : account?.color ?? 'slate',
        title: {
            create: `Buat akun ${a.name ?? '…'}`,
            rename: `Ganti nama ${name}`,
            adjust_balance: `Sesuaikan saldo ${name}`,
            archive: `Arsipkan ${name}`,
            unarchive: `Aktifkan lagi ${name}`,
        }[a.action],
    };
}

const flagged = (x) => x.issues.length || x.serverError;
const blocked = computed(() => drafts.value.some(flagged) || actions.value.some(flagged));
const total = computed(() => drafts.value.reduce((s, d) => s + (d.type === 'expense' ? -d.amount : d.type === 'income' ? d.amount : 0), 0));
const changeCount = computed(() => drafts.value.length + actions.value.length);

function afterRemove() {
    if (!hasProposals.value && !reply.value) phase.value = 'input';
}
function remove(d) {
    drafts.value = drafts.value.filter((x) => x !== d);
    afterRemove();
}
function removeAction(a) {
    actions.value = actions.value.filter((x) => x !== a);
    afterRemove();
}

/* Ubah transaksi lewat form cepat (validasi & isian lengkap ada di sana), lalu kembali ke sini. */
let resume = false;
function edit(d) {
    remove(d);
    resume = hasProposals.value;
    closeVoice();
    openQuickAdd({ template: { ...d, fromVoice: true } });
}
watch(
    () => quick.open,
    (open) => {
        if (open || !resume) return;
        resume = false;
        if (hasProposals.value) {
            phase.value = 'review';
            state.open = true;
        }
    },
);

function again() {
    phase.value = 'input';
    text.value = '';
    clearResult();
}

/* ---- Langkah 2: jalankan semua sekaligus ---- */
function save() {
    if (blocked.value || saving.value || !hasProposals.value) return;
    const items = drafts.value.map(({ type, amount, account_id, to_account_id, category_id, note, occurred_on }) => ({
        type, amount, account_id, to_account_id, category_id, note, occurred_on,
    }));
    const accountActions = actions.value.map(({ action, account_id, name, type, amount, note }) => ({
        action, account_id, name, type, amount, note,
    }));
    const single = items.length === 1 && !accountActions.length ? view(drafts.value[0]) : null;
    const spoken = joinSpeech([confirmation(items, actions.value), encouragement.value]);
    primeSpeech();
    const before = snapshotBudgets(categories.value);
    saving.value = true;
    generalError.value = '';
    drafts.value.forEach((d) => (d.serverError = ''));
    actions.value.forEach((a) => (a.serverError = ''));

    router.post(route('assistant.apply'), { items, account_actions: accountActions }, {
        preserveScroll: true,
        onSuccess: (p) => {
            closeVoice();
            speak(spoken);
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
                const match = key.match(/^(items|account_actions)\.(\d+)\./);
                const list = match && (match[1] === 'items' ? drafts.value : actions.value);
                if (list?.[match[2]]) list[match[2]].serverError = message;
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
    <Sheet :open="state.open && voiceAvailable" title="Asisten" @close="closeVoice">
        <template #header>
            <div class="flex w-full items-center justify-between gap-3">
                <h2 class="flex-1 text-[17px] font-semibold tracking-tight">Asisten</h2>
                <button
                    v-if="speech.supported"
                    type="button"
                    class="icon-btn"
                    aria-label="Atur suara asisten"
                    title="Atur suara asisten"
                    @click="closeVoice(), router.visit(route('settings') + '#suara-asisten')"
                >
                    <SlidersHorizontal class="size-[18px]" />
                </button>
                <button
                    v-if="speech.supported"
                    type="button"
                    class="icon-btn -mr-2"
                    :aria-pressed="speech.enabled.value"
                    :aria-label="speech.enabled.value ? 'Matikan suara asisten' : 'Nyalakan suara asisten'"
                    :title="speech.enabled.value ? 'Suara asisten menyala' : 'Suara asisten mati'"
                    @click="toggleSpeech"
                >
                    <Volume2 v-if="speech.enabled.value" class="size-[18px]" />
                    <VolumeX v-else class="size-[18px]" />
                </button>
            </div>
        </template>
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
                <p class="mt-3 text-center text-sm font-medium" :class="listening ? 'text-accent-text' : 'text-ink-2'">
                    {{ listening ? 'Mendengarkan… ketuk untuk berhenti' : text ? 'Ketuk untuk menambah ucapan' : 'Ketuk lalu bicara: catat, atur akun, atau tanya' }}
                </p>
            </div>

            <label class="field-label" for="voice-text">{{ Recognition ? 'Yang terdengar (bisa diubah)' : 'Tulis perintahmu' }}</label>
            <textarea
                id="voice-text"
                v-model="text"
                rows="3"
                maxlength="1000"
                class="field h-auto resize-none py-3 leading-relaxed"
                placeholder="Misal: tadi makan bakso 25 ribu pakai cash, terus saldo BCA sekarang 2,1 juta"
                :disabled="phase === 'loading'"
            />
            <p v-if="!Recognition" class="mt-2 text-small text-muted">
                Browser ini belum mendukung rekam suara langsung. Pakai tombol mikrofon di keyboard HP-mu untuk mendikte.
            </p>

            <!-- Contoh: ketuk untuk mengisi -->
            <div v-if="!text && phase === 'input'" class="mt-3">
                <p class="eyebrow mb-2">Coba katakan</p>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="example in EXAMPLES"
                        :key="example"
                        type="button"
                        class="rounded-full bg-sunken px-3 py-1.5 text-left text-[13px] text-ink-2 transition active:scale-95"
                        @click="text = example"
                    >
                        {{ example }}
                    </button>
                </div>
            </div>

            <p v-if="error" class="mt-3 flex items-start gap-2 rounded-xl bg-neg/8 px-3 py-2.5 text-small text-neg" role="alert">
                <AlertCircle class="mt-px size-4 shrink-0" /> {{ error }}
            </p>

            <!-- Sedang diurai -->
            <div v-if="phase === 'loading'" class="mt-5" aria-live="polite">
                <p class="mb-3 flex items-center gap-2 text-small font-medium text-accent-text">
                    <Sparkles class="size-4 animate-pulse" /> {{ provider }} sedang membaca permintaanmu…
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
                Teks dikirim ke {{ provider }}<template v-if="providerOwner"> ({{ providerOwner }})</template> bersama daftar akun, saldo &
                kategorimu agar bisa dipahami. Tidak ada yang berubah sebelum kamu menekan tombol konfirmasi.
            </p>
        </div>

        <!-- Langkah 2: jawaban & tinjau usulan -->
        <div v-else class="pb-2">
            <div class="mb-3 flex items-start gap-2 rounded-2xl bg-sunken px-3.5 py-3">
                <p class="min-w-0 flex-1 text-small leading-relaxed text-ink-2 italic">“{{ text.trim() }}”</p>
                <button type="button" class="icon-btn -my-1 -mr-1.5 size-8 shrink-0" aria-label="Ulangi" title="Ulangi" @click="again">
                    <RotateCcw class="size-4" />
                </button>
            </div>

            <div v-if="reply || encouragement" class="mb-3 flex items-start gap-2.5 rounded-2xl border border-accent/15 bg-accent-soft/70 px-3.5 py-3">
                <Sparkles class="mt-0.5 size-4 shrink-0 text-accent-text" />
                <div class="space-y-1.5">
                    <p v-if="reply" class="text-[15px] leading-relaxed text-ink">{{ reply }}</p>
                    <p v-if="encouragement" class="text-small leading-relaxed text-ink-2">{{ encouragement }}</p>
                </div>
            </div>

            <p v-if="unclear" class="mb-3 flex items-start gap-2 text-small text-warn">
                <Info class="mt-px size-4 shrink-0" /> {{ unclear }}
            </p>

            <TransitionGroup tag="ul" name="list" class="relative flex flex-col gap-2">
                <!-- Perubahan akun -->
                <li
                    v-for="a in actions"
                    :key="a.key"
                    class="rounded-2xl border bg-surface p-3"
                    :class="flagged(a) ? 'border-neg/40' : 'border-line'"
                >
                    <div class="flex items-center gap-3">
                        <IconTile :icon="actionView(a).icon" :color="actionView(a).color" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[15px] font-medium">{{ actionView(a).title }}</p>
                            <p class="text-small text-muted">
                                <template v-if="a.action === 'create'">
                                    {{ ACCOUNT_TYPES[a.type]?.label }} · saldo awal <Money :value="a.amount ?? 0" class="text-ink-2 tnum" />
                                </template>
                                <template v-else-if="a.action === 'adjust_balance'">
                                    Tercatat <Money :value="a.current_balance ?? 0" class="tnum" /> → sebenarnya
                                    <Money :value="a.amount ?? 0" class="font-medium text-ink-2 tnum" />
                                </template>
                                <template v-else-if="a.action === 'rename'">Menjadi “{{ a.name }}”</template>
                                <template v-else-if="a.action === 'archive'">Disembunyikan dari pilihan; riwayat tetap ada</template>
                                <template v-else>Muncul lagi di pilihan akun</template>
                            </p>
                        </div>
                    </div>
                    <ul class="mt-2 space-y-1 pl-[52px] text-small">
                        <li v-for="issue in a.issues" :key="issue" class="flex items-center gap-1.5 text-neg">
                            <AlertCircle class="size-3.5 shrink-0" /> {{ issue }}
                        </li>
                        <li v-if="a.serverError" class="flex items-center gap-1.5 text-neg">
                            <AlertCircle class="size-3.5 shrink-0" /> {{ a.serverError }}
                        </li>
                        <li v-for="note in a.notes" :key="note" class="text-muted">{{ note }}</li>
                        <li v-if="a.action === 'adjust_balance'" class="text-muted">Selisihnya dicatat sebagai penyesuaian saldo hari ini</li>
                    </ul>
                    <div class="mt-1 flex justify-end pl-[52px]">
                        <button type="button" class="btn btn-ghost h-9 px-3 text-[13px] text-muted" @click="removeAction(a)">
                            <X class="size-3.5" /> Buang
                        </button>
                    </div>
                </li>

                <!-- Transaksi -->
                <li
                    v-for="d in drafts"
                    :key="d.key"
                    class="rounded-2xl border bg-surface p-3"
                    :class="flagged(d) ? 'border-neg/40' : 'border-line'"
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

                    <ul v-if="flagged(d) || d.notes.length || view(d).uncategorized" class="mt-2 space-y-1 pl-[52px] text-small">
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
            <button v-else-if="!hasProposals" type="button" class="btn btn-quiet h-12 w-full text-[15px]" @click="again">
                <Mic class="size-4" /> Tanya atau perintah lagi
            </button>
            <template v-else>
                <button type="button" class="btn btn-primary h-12 w-full text-[15px]" :disabled="blocked || saving" @click="save">
                    {{
                        saving
                            ? 'Menjalankan…'
                            : changeCount === 1 && drafts.length === 1
                              ? 'Simpan'
                              : changeCount === 1
                                ? 'Jalankan'
                                : `Jalankan ${changeCount} perubahan`
                    }}
                </button>
                <p v-if="blocked" class="mt-2 text-center text-small text-muted">Perbaiki atau buang yang bertanda merah dulu.</p>
            </template>
        </template>
    </Sheet>
</template>
