<script setup>
/**
 * Wajah LAX: bola cahaya yang berubah menurut keadaan.
 * idle = bernapas pelan · listening = gelombang keluar · thinking = warna berputar · speaking = berdenyut.
 * Animasi otomatis mati bila pengguna memilih "kurangi gerakan" (aturan global di app.css).
 */
defineProps({
    state: { type: String, default: 'idle' }, // idle | listening | thinking | speaking
    size: { type: Number, default: 80 },
});
</script>

<template>
    <span class="lax-orb" :data-state="state" :style="{ '--orb': `${size}px` }" aria-hidden="true">
        <span v-for="n in 3" :key="n" class="lax-ring" :style="{ animationDelay: `${(n - 1) * 0.45}s` }" />
        <span class="lax-core" />
        <span class="lax-shine" />
    </span>
</template>

<style>
@property --lax-spin {
    syntax: '<angle>';
    inherits: false;
    initial-value: 0deg;
}

.lax-orb {
    position: relative;
    isolation: isolate;
    display: inline-block;
    flex-shrink: 0;
    width: var(--orb);
    height: var(--orb);
}
/* Cahaya lembut di belakang bola. */
.lax-orb::before {
    content: '';
    position: absolute;
    inset: -18%;
    z-index: -1;
    border-radius: 50%;
    background: radial-gradient(circle, color-mix(in oklab, var(--hero-to) 55%, transparent), transparent 68%);
    filter: blur(calc(var(--orb) * 0.08));
    opacity: 0.75;
    transition: opacity 0.3s ease;
}
.lax-core {
    position: absolute;
    inset: 0;
    border-radius: 50%;
    background:
        radial-gradient(circle at 68% 74%, color-mix(in oklab, var(--c-iris) 85%, transparent), transparent 55%),
        radial-gradient(circle at 25% 80%, color-mix(in oklab, var(--c-teal) 70%, transparent), transparent 50%),
        conic-gradient(from var(--lax-spin), var(--hero-from), var(--hero-to), var(--c-teal), var(--c-iris), var(--hero-from));
    box-shadow:
        inset 0 calc(var(--orb) * -0.08) calc(var(--orb) * 0.18) rgb(0 0 0 / 0.28),
        inset 0 calc(var(--orb) * 0.04) calc(var(--orb) * 0.1) rgb(255 255 255 / 0.25),
        0 calc(var(--orb) * 0.14) calc(var(--orb) * 0.36) calc(var(--orb) * -0.14) var(--hero-from);
    animation:
        lax-breathe 4.2s ease-in-out infinite,
        lax-turn 14s linear infinite;
}
/* Kilau kaca di kiri atas. */
.lax-shine {
    position: absolute;
    top: 12%;
    left: 18%;
    width: 34%;
    height: 24%;
    border-radius: 50%;
    background: radial-gradient(ellipse at center, rgb(255 255 255 / 0.75), transparent 70%);
    filter: blur(calc(var(--orb) * 0.02));
    transform: rotate(-24deg);
    pointer-events: none;
}
.lax-ring {
    position: absolute;
    inset: 0;
    border-radius: 50%;
    border: 2px solid color-mix(in oklab, var(--hero-to) 70%, var(--c-teal));
    opacity: 0;
}

.lax-orb[data-state='listening'] .lax-ring {
    animation: lax-ripple 1.5s ease-out infinite;
}
.lax-orb[data-state='listening'] .lax-core {
    animation:
        lax-listen 0.9s ease-in-out infinite alternate,
        lax-turn 6s linear infinite;
}
.lax-orb[data-state='thinking'] .lax-core {
    animation:
        lax-breathe 1.4s ease-in-out infinite,
        lax-turn 1.6s linear infinite;
}
.lax-orb[data-state='speaking'] .lax-core {
    animation:
        lax-talk 0.32s ease-in-out infinite alternate,
        lax-turn 5s linear infinite;
}
.lax-orb[data-state='speaking']::before,
.lax-orb[data-state='listening']::before {
    opacity: 1;
}

@keyframes lax-turn {
    to {
        --lax-spin: 360deg;
    }
}
@keyframes lax-breathe {
    50% {
        transform: scale(1.035);
    }
}
@keyframes lax-listen {
    to {
        transform: scale(1.07);
    }
}
@keyframes lax-talk {
    from {
        transform: scale(0.98);
    }
    to {
        transform: scale(1.06);
    }
}
@keyframes lax-ripple {
    from {
        opacity: 0.7;
        transform: scale(1);
    }
    to {
        opacity: 0;
        transform: scale(1.6);
    }
}
</style>
