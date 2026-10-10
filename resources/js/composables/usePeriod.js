import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { periodOf, periodRange, periodRangeLabel, today } from '@/lib/dates';

/** Periode bulanan pengguna (awal = tanggal gajian dari pengaturan). */
export function usePeriod() {
    const page = usePage();
    const startDay = computed(() => page.props.period?.startDay ?? 1);
    // Dihitung di klien agar sejalan dengan today() di komponen lain.
    const current = computed(() => periodOf(today(), startDay.value));

    // "bulan ini" untuk bulan kalender, "periode ini" bila mulai di tanggal gajian.
    const thisLabel = computed(() => (startDay.value > 1 ? 'periode ini' : 'bulan ini'));

    return {
        startDay,
        current,
        thisLabel,
        range: (key) => periodRange(key, startDay.value),
        of: (iso) => periodOf(iso, startDay.value),
        rangeLabel: (key) => periodRangeLabel(key, startDay.value),
    };
}
