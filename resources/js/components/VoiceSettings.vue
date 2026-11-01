<script setup>
import { usePage } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { ChevronRight, Gauge, Play } from 'lucide-vue-next';
import { route } from 'ziggy-js';
import Segmented from '@/components/ui/Segmented.vue';
import { previewVoice, saveVoiceSettings, toggleSpeech, useSpeech, voiceLabel } from '@/composables/useSpeech';
import { guessNick, laxNick, setLaxNick } from '@/lib/lax';
import { setWakeWord, useWakeWord } from '@/composables/useWakeWord';

/* Pengaturan suara asisten: suara perangkat yang dipakai, kecepatan, nada. */
const { enabled, settings, indonesianVoices, currentVoice, cloudVoices, cloudVoiceTraits, usesCloud, deviceSupported } = useSpeech();
const page = usePage();
const guessed = guessNick(page.props.auth.user?.name ?? '');

function chooseCloud(name) {
    saveVoiceSettings({ cloudVoice: name });
    previewVoice();
}
const wake = useWakeWord();
const isApple = typeof navigator !== 'undefined' && /iPhone|iPad|Mac/.test(navigator.userAgent);

function pick(voiceURI) {
    saveVoiceSettings({ voiceURI: voiceURI || null });
    previewVoice();
}
</script>

<template>
    <section id="suara-asisten" class="card scroll-mt-6 p-5">
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <h2 class="section-title">LAX</h2>
                <p class="mt-0.5 text-small text-muted">Ledger Assistant eXtraordinaire · suara balasan tanpa menyebut nominal.</p>
            </div>
            <button
                type="button"
                role="switch"
                :aria-checked="enabled"
                aria-label="Bacakan balasan LAX"
                class="relative h-7 w-12 shrink-0 rounded-full transition-colors"
                :class="enabled ? 'bg-accent' : 'bg-line-strong'"
                @click="toggleSpeech"
            >
                <span class="absolute top-1 left-1 size-5 rounded-full bg-surface shadow transition-transform duration-200 ease-out-soft" :class="enabled && 'translate-x-5'" />
            </button>
        </div>

        <!-- Kata panggil -->
        <div v-if="wake.supported" class="mb-5 rounded-2xl border border-line p-3.5">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-[15px] font-medium">Panggil dengan “Hei LAX”</p>
                    <p class="text-small text-muted">
                        {{ wake.blocked.value || (wake.enabled.value ? (wake.listening.value ? 'Siaga: LAX mendengarkan' : wake.needsTap.value ? 'Ketuk layar sekali agar LAX siaga' : 'Menyiapkan…') : 'Mati') }}
                    </p>
                </div>
                <button
                    type="button"
                    role="switch"
                    :aria-checked="wake.enabled.value"
                    aria-label="Panggil dengan Hei LAX"
                    class="relative h-7 w-12 shrink-0 rounded-full transition-colors"
                    :class="wake.enabled.value ? 'bg-accent' : 'bg-line-strong'"
                    @click="setWakeWord(!wake.enabled.value)"
                >
                    <span class="absolute top-1 left-1 size-5 rounded-full bg-surface shadow transition-transform duration-200 ease-out-soft" :class="wake.enabled.value && 'translate-x-5'" />
                </button>
            </div>
            <p class="mt-2.5 text-small leading-relaxed text-muted">
                Selama Sisih terbuka di layar, mikrofon aktif menunggu kata panggil (berhenti saat aplikasi diminimalkan; ada penanda
                “Hei LAX” di layar). Suara diproses layanan pengenal suara browser, misalnya Google di Chrome. Coba ucapkan
                “Hei LAX” atau langsung “Hei LAX, catat bakso 25 ribu”. Paling andal di Chrome/Edge; di Android mungkin terdengar bunyi
                “bip” saat LAX mulai mendengarkan ulang.
            </p>
        </div>

        <Link
            :href="route('settings.ai')"
            class="mb-5 flex items-center gap-3 rounded-2xl border border-line px-3.5 py-3 transition-colors hover:border-line-strong active:bg-sunken"
        >
            <Gauge class="size-[18px] text-accent-text" />
            <span class="flex-1">
                <span class="block text-[15px] font-medium">Pemakaian AI</span>
                <span class="block text-small text-muted">Permintaan, token & perkiraan sisa kuota gratis Gemini</span>
            </span>
            <ChevronRight class="size-4 text-muted" />
        </Link>

        <!-- Sebutan untukmu -->
        <label class="mb-5 block">
            <span class="field-label">Nama panggilan</span>
            <input
                class="field"
                maxlength="30"
                :value="laxNick"
                :placeholder="guessed || 'mis. Wira'"
                autocomplete="off"
                @change="setLaxNick($event.target.value)"
            />
            <span class="mt-1.5 block text-small text-muted">
                LAX memanggilmu “Tuan”, “Tuan {{ laxNick || guessed || '…' }}”, atau “Tuan Muda”.
            </span>
        </label>

        <div class="flex flex-col gap-4" :class="!enabled && 'opacity-60'">
            <div v-if="cloudVoices">
                <span class="field-label">Sumber suara</span>
                <Segmented
                    :model-value="settings.source"
                    :options="[
                        { value: 'gemini', label: 'Gemini (natural)' },
                        { value: 'device', label: 'Suara HP' },
                    ]"
                    @update:model-value="(v) => (saveVoiceSettings({ source: v }), previewVoice())"
                />
            </div>

            <!-- Suara Gemini: pilih karakter -->
            <div v-if="usesCloud">
                <span class="field-label">Karakter suara</span>
                <span class="-mt-1 mb-2 block text-small text-muted">
                    Semua model suara Gemini dipakai bergantian; bila kuota satu model habis, LAX pindah ke model lain
                    (suaranya bisa sedikit berbeda).
                </span>
                <div class="grid max-h-80 grid-cols-2 gap-2 overflow-y-auto pr-1">
                    <button
                        v-for="name in cloudVoices"
                        :key="name"
                        type="button"
                        class="flex h-12 items-center justify-between rounded-xl border px-3 text-left transition active:scale-[0.98]"
                        :class="settings.cloudVoice === name ? 'border-accent bg-accent-soft' : 'border-line hover:border-line-strong'"
                        :aria-pressed="settings.cloudVoice === name"
                        @click="chooseCloud(name)"
                    >
                        <span class="text-[14px] font-medium">{{ name }}</span>
                        <span class="text-small text-muted">{{ cloudVoiceTraits[name] }}</span>
                    </button>
                </div>
            </div>

            <label v-if="!usesCloud && deviceSupported" class="block">
                <span class="field-label">Suara</span>
                <select class="field" :value="settings.voiceURI ?? ''" @change="pick($event.target.value)">
                    <option value="">Otomatis{{ currentVoice() && !settings.voiceURI ? ` (${voiceLabel(currentVoice())})` : '' }}</option>
                    <option v-for="v in indonesianVoices" :key="v.voiceURI" :value="v.voiceURI">
                        {{ voiceLabel(v) }}{{ v.localService ? '' : ' · online' }}
                    </option>
                </select>
            </label>
            <p v-if="!usesCloud && !indonesianVoices.length" class="-mt-2 text-small text-warn">
                Perangkat ini belum punya suara Bahasa Indonesia, jadi dibacakan dengan suara lain. Pasang lewat petunjuk di bawah.
            </p>

            <label class="block">
                <span class="field-label flex justify-between">Kecepatan <span class="font-normal text-muted tnum">{{ settings.rate.toFixed(1) }}×</span></span>
                <input
                    type="range"
                    min="0.7"
                    max="1.5"
                    step="0.1"
                    class="w-full accent-(--accent)"
                    :value="settings.rate"
                    @change="saveVoiceSettings({ rate: Number($event.target.value) }), previewVoice()"
                />
            </label>
            <label v-if="!usesCloud" class="block">
                <span class="field-label flex justify-between">Nada <span class="font-normal text-muted">{{ settings.pitch < 0.95 ? 'lebih berat' : settings.pitch > 1.05 ? 'lebih tinggi' : 'normal' }}</span></span>
                <input
                    type="range"
                    min="0.6"
                    max="1.4"
                    step="0.1"
                    class="w-full accent-(--accent)"
                    :value="settings.pitch"
                    @change="saveVoiceSettings({ pitch: Number($event.target.value) }), previewVoice()"
                />
            </label>

            <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="btn btn-quiet" @click="previewVoice"><Play class="size-4" /> Coba suara</button>
                <button
                    v-if="settings.voiceURI || settings.rate !== 1 || settings.pitch !== 1"
                    type="button"
                    class="btn btn-ghost"
                    @click="saveVoiceSettings({ voiceURI: null, rate: 1, pitch: 1 })"
                >
                    Kembalikan bawaan
                </button>
            </div>

            <p v-if="usesCloud" class="text-small leading-relaxed text-muted">
                Suara Gemini dibuat lewat internet dan memakai kuota Gemini; kalimat yang sama disimpan sementara agar hemat. Bila gagal
                (offline atau kuota habis), LAX otomatis memakai suara HP. Teks yang dibacakan tidak pernah berisi nominal.
            </p>
            <p v-else class="text-small leading-relaxed text-muted">
                Ingin suara yang lebih natural? Pasang suara Bahasa Indonesia berkualitas tinggi di HP, lalu pilih di atas.
                <template v-if="isApple">iPhone: Pengaturan → Aksesibilitas → Konten Lisan → Suara → Bahasa Indonesia, unduh versi "Ditingkatkan".</template>
                <template v-else>Android: Pengaturan → Text-to-speech → Mesin Google → Bahasa Indonesia, unduh suara lalu pilih variannya.</template>
                Di Chrome/Edge komputer, suara bertanda "online" biasanya paling halus.
            </p>
        </div>
    </section>
</template>
