<script setup>
import { computed, ref } from 'vue';
import { dayLabel, today } from '@/lib/dates';
import Money from '@/components/ui/Money.vue';
import { rupiah } from '@/lib/money';

const props = defineProps({
    month: { type: String, required: true },
    daily: { type: Array, required: true }, // [{ day, income, expense }]
});

const active = ref(null);
const todayIso = today();
const isCurrentMonth = computed(() => todayIso.startsWith(props.month));
const lastDay = computed(() => (isCurrentMonth.value ? Number(todayIso.slice(8)) : props.daily.length));

/** Skala dibulatkan ke angka "bersih" agar garis bantu mudah dibaca. */
const scaleMax = computed(() => {
    const max = Math.max(...props.daily.map((d) => d.expense), 0);
    if (!max) return 100_000;
    const magnitude = 10 ** Math.floor(Math.log10(max));
    const step = [1, 2, 2.5, 5, 10].find((s) => s * magnitude >= max);
    return step * magnitude;
});

const spent = computed(() => props.daily.slice(0, lastDay.value).reduce((s, d) => s + d.expense, 0));
const average = computed(() => (lastDay.value ? Math.round(spent.value / lastDay.value) : 0));

const iso = (day) => `${props.month}-${String(day).padStart(2, '0')}`;
const isToday = (day) => iso(day) === todayIso;
const activeDay = computed(() => props.daily.find((d) => d.day === active.value));

function barStyle(d) {
    const h = (d.expense / scaleMax.value) * 100;
    const highlighted = active.value ? active.value === d.day : isToday(d.day);
    return {
        height: d.expense ? `max(${h}%, 3px)` : '0',
        background: highlighted
            ? 'linear-gradient(to top, var(--bar), color-mix(in oklab, var(--bar) 65%, white))'
            : 'linear-gradient(to top, color-mix(in oklab, var(--bar) 30%, transparent), color-mix(in oklab, var(--bar) 48%, transparent))',
        boxShadow: highlighted ? '0 4px 12px -4px color-mix(in oklab, var(--bar) 70%, transparent)' : undefined,
    };
}

function tooltipLeft(day) {
    const pct = ((day - 0.5) / props.daily.length) * 100;
    return `clamp(4.5rem, ${pct}%, calc(100% - 4.5rem))`;
}
</script>

<template>
    <figure>
        <figcaption class="mb-4 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
            <span class="section-title whitespace-nowrap">Pengeluaran harian</span>
            <span class="text-[13px] text-muted">
                rata-rata <Money :value="average" class="text-ink-2 tnum" />/hari
            </span>
        </figcaption>

        <div class="relative" @mouseleave="active = null">
            <!-- Tooltip -->
            <Transition name="fade">
                <div
                    v-if="activeDay"
                    class="pointer-events-none absolute -top-2 z-10 -translate-x-1/2 -translate-y-full rounded-xl bg-ink px-3 py-2 text-center whitespace-nowrap text-paper shadow-float"
                    :style="{ left: tooltipLeft(activeDay.day) }"
                >
                    <p class="text-[11px] opacity-70 first-letter:uppercase">{{ dayLabel(iso(activeDay.day)) }}</p>
                    <Money :value="activeDay.expense" class="block text-[13px] font-medium tnum" />
                    <p v-if="activeDay.income" class="text-[11px] opacity-70 tnum">masuk <Money :value="activeDay.income" /></p>
                </div>
            </Transition>

            <!-- Garis bantu -->
            <div class="pointer-events-none absolute inset-x-0 top-0 flex items-center gap-2" aria-hidden="true">
                <span class="h-px flex-1 border-t border-dashed border-line" />
                <Money :value="scaleMax" compact class="text-[11px] text-muted tnum" />
            </div>
            <div class="pointer-events-none absolute inset-x-0 top-1/2 flex items-center gap-2" aria-hidden="true">
                <span class="h-px flex-1 border-t border-dashed border-line" />
                <Money :value="scaleMax / 2" compact class="text-[11px] text-muted tnum" />
            </div>

            <div class="flex h-36 items-end gap-[2px] border-b border-line-strong pr-10 md:h-44" aria-hidden="true">
                <button
                    v-for="d in daily"
                    :key="d.day"
                    type="button"
                    tabindex="-1"
                    class="flex h-full flex-1 items-end justify-center"
                    :class="d.day > lastDay && 'pointer-events-none'"
                    @mouseenter="active = d.day"
                    @click="active = active === d.day ? null : d.day"
                >
                    <span
                        class="w-full max-w-6 rounded-t-[5px] rounded-b-[1px] transition-[height] duration-500 ease-out-soft"
                        :style="barStyle(d)"
                    />
                </button>
            </div>

            <div class="mt-2 flex justify-between pr-10 text-[11px] text-muted tnum" aria-hidden="true">
                <span>1</span>
                <span>{{ Math.ceil(daily.length / 2) }}</span>
                <span>{{ daily.length }}</span>
            </div>
        </div>

        <!-- Versi tabel untuk pembaca layar -->
        <table class="sr-only">
            <caption>Pengeluaran per hari</caption>
            <tbody>
                <tr v-for="d in daily.filter((x) => x.expense)" :key="d.day">
                    <th scope="row">{{ dayLabel(iso(d.day)) }}</th>
                    <td>{{ rupiah(d.expense) }}</td>
                </tr>
            </tbody>
        </table>
    </figure>
</template>
