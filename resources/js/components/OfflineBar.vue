<script setup>
import { useOnline } from '@vueuse/core';
import { ChevronDown, CloudUpload, TriangleAlert, WifiOff } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import Money from '@/components/ui/Money.vue';
import { flushOutbox, removeFromOutbox, useOutbox } from '@/composables/useOutbox';
import { openQuickAdd } from '@/composables/useQuickAdd';

/** Status offline + antrean transaksi yang belum terkirim. Tidak tampil saat semuanya beres. */
const online = useOnline();
const { items, sending, needsLogin } = useOutbox();
const expanded = ref(false);

const failed = computed(() => items.value.filter((i) => i.error));
const visible = computed(() => !online.value || items.value.length > 0);

const message = computed(() => {
    const n = items.value.length;
    if (!n) return 'Offline · menampilkan data terakhir yang tersimpan';
    if (failed.value.length) return `${failed.value.length} transaksi offline perlu diperbaiki`;
    if (needsLogin.value) return `${n} transaksi menunggu · masuk lagi untuk mengirim`;
    if (!online.value) return `Offline · ${n} transaksi menunggu dikirim`;
    return sending.value ? `Mengirim ${n} transaksi…` : `${n} transaksi menunggu dikirim`;
});

// Ditolak server (mis. akun sudah dihapus): buka lagi di form untuk diperbaiki.
function fix(item) {
    removeFromOutbox(item.client_id);
    openQuickAdd({ template: item.payload });
}
</script>

<template>
    <Transition name="offline-bar">
        <div v-if="visible" class="mb-5 overflow-hidden rounded-2xl border border-line bg-surface text-[13px]">
            <div class="flex min-h-11 items-center gap-2.5 px-3.5 py-2">
                <TriangleAlert v-if="failed.length" class="size-4 shrink-0 text-neg" />
                <WifiOff v-else-if="!online" class="size-4 shrink-0 text-muted" />
                <CloudUpload v-else class="size-4 shrink-0 text-accent-text" :class="sending && 'animate-pulse'" />
                <span class="min-w-0 flex-1 text-ink-2">{{ message }}</span>
                <button
                    v-if="online && items.length && !sending && !failed.length"
                    type="button"
                    class="rounded-full px-2.5 py-1 font-medium text-accent-text hover:bg-sunken"
                    @click="flushOutbox"
                >
                    Kirim
                </button>
                <button
                    v-if="items.length"
                    type="button"
                    class="icon-btn -mr-1.5 size-8"
                    :aria-expanded="expanded"
                    aria-label="Lihat antrean"
                    @click="expanded = !expanded"
                >
                    <ChevronDown class="size-4 transition-transform" :class="expanded && 'rotate-180'" />
                </button>
            </div>
            <ul v-if="expanded && items.length" class="border-t border-line px-3.5 py-1">
                <li v-for="item in items" :key="item.client_id" class="flex items-center gap-3 py-2">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-ink">{{ item.summary.title }}</span>
                        <span v-if="item.error" class="block text-[12px] text-neg">{{ item.error }}</span>
                    </span>
                    <Money
                        :value="item.summary.type === 'expense' ? -item.summary.amount : item.summary.amount"
                        :sign="item.summary.type === 'income'"
                        class="tnum"
                    />
                    <template v-if="item.error">
                        <button type="button" class="font-medium text-accent-text" @click="fix(item)">Perbaiki</button>
                        <button type="button" class="text-muted hover:text-neg" @click="removeFromOutbox(item.client_id)">Hapus</button>
                    </template>
                </li>
            </ul>
        </div>
    </Transition>
</template>

<style scoped>
.offline-bar-enter-active,
.offline-bar-leave-active {
    transition:
        opacity 0.25s ease,
        transform 0.3s var(--ease-out-soft);
}
.offline-bar-enter-from,
.offline-bar-leave-to {
    opacity: 0;
    transform: translateY(-6px);
}
</style>
