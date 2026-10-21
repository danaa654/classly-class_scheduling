<script setup>
/**
 * Shared page header (replaces the hand-copied "neu-spotlight" title banner).
 *
 * Usage:
 *   <PageHeader title="Subjects" subtitle="Manage the master list…" icon="pi pi-bookmark" tone="blue"
 *               :count="subjects.total" count-label="subjects">
 *       <template #info><InfoPopover … /></template>   <!-- optional help popover -->
 *       <template #actions>…buttons…</template>        <!-- optional, right side -->
 *   </PageHeader>
 *
 * Slimmer than the old banner: icon tile + title/subtitle on the left,
 * optional count pill and actions on the right.
 */
import { computed } from 'vue';
import { useTheme } from '@/composables/useTheme';

const props = defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    // PrimeIcons class, e.g. "pi pi-bookmark".
    icon: { type: String, default: 'pi pi-th-large' },
    // blue | green | violet | amber | teal | rose
    tone: { type: String, default: 'blue' },
    // Optional "46 subjects" pill. Pass null/undefined to hide.
    count: { type: [Number, String], default: null },
    countLabel: { type: String, default: '' },
});

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

// Full literal class strings so Tailwind keeps them.
const TONES = {
    blue: {
        tile: 'from-blue-500 to-blue-600',
        wash: 'from-blue-50 via-white/0 to-transparent',
        washDark: 'from-blue-500/15 via-transparent to-transparent',
        bar: 'bg-blue-500',
        pill: 'bg-blue-100 text-blue-700',
        pillDark: 'bg-blue-500/20 text-blue-300',
    },
    green: {
        tile: 'from-emerald-500 to-emerald-600',
        wash: 'from-emerald-50 via-white/0 to-transparent',
        washDark: 'from-emerald-500/15 via-transparent to-transparent',
        bar: 'bg-emerald-500',
        pill: 'bg-emerald-100 text-emerald-700',
        pillDark: 'bg-emerald-500/20 text-emerald-300',
    },
    violet: {
        tile: 'from-violet-500 to-violet-600',
        wash: 'from-violet-50 via-white/0 to-transparent',
        washDark: 'from-violet-500/15 via-transparent to-transparent',
        bar: 'bg-violet-500',
        pill: 'bg-violet-100 text-violet-700',
        pillDark: 'bg-violet-500/20 text-violet-300',
    },
    amber: {
        tile: 'from-amber-500 to-orange-500',
        wash: 'from-amber-50 via-white/0 to-transparent',
        washDark: 'from-amber-500/15 via-transparent to-transparent',
        bar: 'bg-amber-500',
        pill: 'bg-amber-100 text-amber-800',
        pillDark: 'bg-amber-500/20 text-amber-300',
    },
    teal: {
        tile: 'from-teal-500 to-teal-600',
        wash: 'from-teal-50 via-white/0 to-transparent',
        washDark: 'from-teal-500/15 via-transparent to-transparent',
        bar: 'bg-teal-500',
        pill: 'bg-teal-100 text-teal-700',
        pillDark: 'bg-teal-500/20 text-teal-300',
    },
    rose: {
        tile: 'from-rose-500 to-rose-600',
        wash: 'from-rose-50 via-white/0 to-transparent',
        washDark: 'from-rose-500/15 via-transparent to-transparent',
        bar: 'bg-rose-500',
        pill: 'bg-rose-100 text-rose-700',
        pillDark: 'bg-rose-500/20 text-rose-300',
    },
};

const t = computed(() => TONES[props.tone] ?? TONES.blue);
const hasCount = computed(() => props.count !== null && props.count !== undefined && props.count !== '');
</script>

<template>
    <div class="neu-card relative mb-6 overflow-hidden rounded-2xl transition-colors duration-300">
        <!-- soft tone wash + accent bar -->
        <div class="pointer-events-none absolute inset-0">
            <div class="absolute inset-0 bg-gradient-to-r" :class="isDark ? t.washDark : t.wash"></div>
            <div class="absolute inset-y-0 left-0 w-1" :class="t.bar"></div>
            <div
                class="absolute -right-12 -top-16 h-40 w-40 rounded-full bg-gradient-to-br to-transparent"
                :class="isDark ? 'from-white/5' : 'from-slate-200/60'"
            ></div>
        </div>

        <div class="relative flex flex-wrap items-center gap-x-4 gap-y-3 px-5 py-4 sm:px-6">
            <span
                class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br text-white shadow-md"
                :class="t.tile"
            >
                <i :class="icon" class="text-lg"></i>
            </span>

            <div class="min-w-0 flex-1 basis-72">
                <h1
                    class="flex items-center gap-2 text-xl font-bold tracking-tight sm:text-2xl"
                    :class="isDark ? 'text-white' : 'text-[#1E293B]'"
                >
                    {{ title }}
                    <slot name="info" />
                </h1>
                <p v-if="subtitle" class="mt-0.5 text-sm" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                    {{ subtitle }}
                </p>
            </div>

            <div v-if="hasCount || $slots.actions" class="flex flex-wrap items-center gap-2">
                <span
                    v-if="hasCount"
                    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-3 py-1 text-xs font-semibold"
                    :class="isDark ? t.pillDark : t.pill"
                >
                    <span class="text-sm font-bold">{{ count }}</span>{{ countLabel }}
                </span>
                <slot name="actions" />
            </div>
        </div>
    </div>
</template>