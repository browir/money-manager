import { reactive } from 'vue';

/** Status global sheet "catat lewat suara", bisa dibuka dari beranda, form cepat atau palet. */
const state = reactive({ open: false, nonce: 0 });

export function openVoice() {
    state.open = true;
    state.nonce++;
}

export function closeVoice() {
    state.open = false;
}

export function useVoice() {
    return state;
}
