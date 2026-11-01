<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import LaxOrb from '@/components/LaxOrb.vue';
import { openVoice, useVoice } from '@/composables/useVoice';
import { useWakeWord } from '@/composables/useWakeWord';
import { LAX } from '@/lib/lax';

/* Penanda bahwa LAX siaga mendengar "Hei LAX" (mikrofon aktif). Ketuk untuk langsung membuka LAX. */
const page = usePage();
const voice = useVoice();
const { enabled, listening, needsTap, blocked } = useWakeWord();

const show = computed(() => page.props.features?.voice && enabled.value && !voice.open);
const label = computed(() =>
    blocked.value ? 'Mikrofon ditolak' : needsTap.value ? 'Ketuk untuk siaga' : listening.value ? `Hei ${LAX.name}` : 'Menyiapkan…',
);
</script>

<template>
    <Transition name="fade">
        <button
            v-if="show"
            type="button"
            class="fixed right-4 bottom-[calc(92px+env(safe-area-inset-bottom))] z-30 flex h-9 items-center gap-1.5 rounded-full border border-line bg-surface/90 pr-3 pl-1.5 text-tiny font-semibold text-ink-2 shadow-lift backdrop-blur-xl transition active:scale-95 md:top-5 md:right-6 md:bottom-auto"
            :title="listening ? `${LAX.name} siaga: ucapkan “Hei ${LAX.name}”` : label"
            :aria-label="`Buka ${LAX.name}`"
            @click="openVoice()"
        >
            <LaxOrb :state="listening ? 'listening' : 'idle'" :size="22" :class="!listening && 'opacity-50 grayscale'" />
            <span :class="blocked && 'text-neg'">{{ label }}</span>
        </button>
    </Transition>
</template>
