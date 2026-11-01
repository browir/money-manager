<script setup>
import { computed } from 'vue';
import LaxOrb from '@/components/LaxOrb.vue';
import { stopSpeaking, useSpeech } from '@/composables/useSpeech';
import { useVoice } from '@/composables/useVoice';
import { LAX } from '@/lib/lax';

/* Penanda kecil "LAX berbicara" saat LAX bersuara setelah sheet ditutup (mis. konfirmasi).
   Isi ucapan sengaja tidak ditulis (permintaan pemilik: jangan dobel suara + teks). Ketuk = diam. */
const { speaking } = useSpeech();
const voice = useVoice();
const show = computed(() => speaking.value && !voice.open);
</script>

<template>
    <Teleport to="body">
        <Transition name="lax-caption">
            <div v-if="show" class="pointer-events-none fixed inset-x-0 top-[max(12px,env(safe-area-inset-top))] z-[70] flex justify-center px-4">
                <button
                    type="button"
                    class="pointer-events-auto flex items-center gap-2 rounded-full border border-line bg-surface/95 py-1.5 pr-3.5 pl-1.5 text-left shadow-lift backdrop-blur-xl"
                    aria-label="Hentikan suara LAX"
                    @click="stopSpeaking"
                >
                    <LaxOrb state="speaking" :size="24" />
                    <span class="text-tiny font-semibold text-ink-2">{{ LAX.name }} berbicara · ketuk untuk diam</span>
                </button>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.lax-caption-enter-active {
    transition:
        opacity 0.3s ease,
        transform 0.45s var(--ease-sheet);
}
.lax-caption-leave-active {
    transition:
        opacity 0.25s ease,
        transform 0.25s ease;
}
.lax-caption-enter-from,
.lax-caption-leave-to {
    opacity: 0;
    transform: translateY(-16px) scale(0.97);
}
</style>
