import { reactive } from 'vue';

/** Status global sheet "catat lewat suara", bisa dibuka dari beranda, form cepat atau palet. */
// wake = dibuka lewat kata panggil "Hei LAX"; prefill = ucapan setelah kata panggil.
const state = reactive({ open: false, nonce: 0, wake: false, prefill: '' });

export function openVoice({ wake = false, prefill = '' } = {}) {
    state.wake = wake;
    state.prefill = prefill;
    state.open = true;
    state.nonce++;
}

export function closeVoice() {
    state.open = false;
}

export function useVoice() {
    return state;
}
