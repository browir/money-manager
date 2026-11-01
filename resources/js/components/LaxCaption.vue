<script setup>
import { computed } from 'vue';
import LaxOrb from '@/components/LaxOrb.vue';
import { stopSpeaking, useSpeech } from '@/composables/useSpeech';
import { useVoice } from '@/composables/useVoice';
import { LAX } from '@/lib/lax';

/* Teks yang sedang diucapkan LAX, melayang di atas layar setelah sheet asisten ditutup
   (mis. konfirmasi "Beres! Pengeluaran sudah dicatat"). Ketuk untuk menghentikan suara. */
const { speaking, caption } = useSpeech();
const voice = useVoice();
const show = computed(() => speaking.value && caption.value && !voice.open);
</script>

<template>
    <Teleport to="body">
        <Transition name="lax-caption">
            <div v-if="show" class="pointer-events-none fixed inset-x-0 top-[max(12px,env(safe-area-inset-top))] z-[70] flex justify-center px-4">
                <button
                    type="button"
                    class="pointer-events-auto flex max-w-md items-start gap-2.5 rounded-[20px] border border-line bg-surface/95 py-2.5 pr-4 pl-2.5 text-left shadow-lift backdrop-blur-xl"
                    aria-label="Hentikan suara LAX"
                    @click="stopSpeaking"
                >
                    <LaxOrb state="speaking" :size="28" />
                    <p class="pt-0.5 text-[13px] leading-snug text-ink-2" aria-live="polite">
                        <span class="font-semibold text-ink">{{ LAX.name }}</span> · {{ caption }}
                    </p>
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
