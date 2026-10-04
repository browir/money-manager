import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/** Akses akun & kategori yang dibagikan server ke semua halaman. */
export function useLedger() {
    const page = usePage();

    const accounts = computed(() => page.props.accounts ?? []);
    const activeAccounts = computed(() => accounts.value.filter((a) => !a.archived));
    const categories = computed(() => page.props.categories ?? []);
    const accountById = computed(() => Object.fromEntries(accounts.value.map((a) => [a.id, a])));
    const categoryById = computed(() => Object.fromEntries(categories.value.map((c) => [c.id, c])));
    const netWorth = computed(() => accounts.value.reduce((sum, a) => sum + a.balance, 0));

    return { accounts, activeAccounts, categories, accountById, categoryById, netWorth };
}
