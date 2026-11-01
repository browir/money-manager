<script setup>
import { Play } from 'lucide-vue-next';
import { previewVoice, saveVoiceSettings, toggleSpeech, useSpeech, voiceLabel } from '@/composables/useSpeech';
import { setWakeWord, useWakeWord } from '@/composables/useWakeWord';

/* Pengaturan suara asisten: suara perangkat yang dipakai, kecepatan, nada. */
const { enabled, settings, indonesianVoices, currentVoice } = useSpeech();
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

        <div class="flex flex-col gap-4" :class="!enabled && 'opacity-60'">
            <label class="block">
                <span class="field-label">Suara</span>
                <select class="field" :value="settings.voiceURI ?? ''" @change="pick($event.target.value)">
                    <option value="">Otomatis{{ currentVoice() && !settings.voiceURI ? ` (${voiceLabel(currentVoice())})` : '' }}</option>
                    <option v-for="v in indonesianVoices" :key="v.voiceURI" :value="v.voiceURI">
                        {{ voiceLabel(v) }}{{ v.localService ? '' : ' · online' }}
                    </option>
                </select>
            </label>
            <p v-if="!indonesianVoices.length" class="-mt-2 text-small text-warn">
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
            <label class="block">
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

            <p class="text-small leading-relaxed text-muted">
                Ingin suara yang lebih natural? Pasang suara Bahasa Indonesia berkualitas tinggi di HP, lalu pilih di atas.
                <template v-if="isApple">iPhone: Pengaturan → Aksesibilitas → Konten Lisan → Suara → Bahasa Indonesia, unduh versi "Ditingkatkan".</template>
                <template v-else>Android: Pengaturan → Text-to-speech → Mesin Google → Bahasa Indonesia, unduh suara lalu pilih variannya.</template>
                Di Chrome/Edge komputer, suara bertanda "online" biasanya paling halus.
            </p>
        </div>
    </section>
</template>
