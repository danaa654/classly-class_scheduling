<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    progress: {
        type: Object,
        required: true,
        // { overall_percent, sections_scheduled, sections_total,
        //   faculty_assigned, rooms_assigned, total_subjects }
    },
    // Number of fully scheduled subjects (Dashboard passes kpis.scheduled_subjects).
    // Optional: without it the card just doesn't show the "x of y" / "left" wording.
    scheduled: { type: Number, default: null },
    isDark: { type: Boolean, default: false },
});

// Same 90/70 banding the spec calls for. Kept local to this widget
// since it's describing overall scheduling completion, not Faculty
// Workload status (which uses FacultyWorkloadService's own 85/100
// thresholds — a different question with different colors).
const tone = computed(() => {
    const percent = props.progress.overall_percent;

    if (percent >= 90) return { stroke: '#10b981', text: props.isDark ? 'text-emerald-400' : 'text-emerald-600' };
    if (percent >= 70) return { stroke: '#f59e0b', text: props.isDark ? 'text-amber-400' : 'text-amber-600' };

    return { stroke: '#ef4444', text: props.isDark ? 'text-red-400' : 'text-red-600' };
});

const remaining = computed(() => (props.scheduled === null ? null : Math.max(0, props.progress.total_subjects - props.scheduled)));

/* ---------- ring (the page's one load-in moment) ---------- */
// Fills from 0 to the real percent and counts the number up once on mount.
// Skipped entirely when the user prefers reduced motion.
const R = 52;
const C = 2 * Math.PI * R;
const target = computed(() => Math.min(Math.max(props.progress.overall_percent ?? 0, 0), 100));
const shown = ref(0);
let frame = null;

onMounted(() => {
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
        shown.value = target.value;
        return;
    }
    const start = performance.now();
    const duration = 1000;
    const tick = (now) => {
        const t = Math.min((now - start) / duration, 1);
        const eased = 1 - Math.pow(1 - t, 3);
        shown.value = target.value * eased;
        if (t < 1) frame = requestAnimationFrame(tick);
    };
    frame = requestAnimationFrame(tick);
});
onBeforeUnmount(() => frame && cancelAnimationFrame(frame));

const dashOffset = computed(() => C * (1 - shown.value / 100));

const stats = computed(() => [
    { label: 'Sections scheduled', value: props.progress.sections_scheduled, total: props.progress.sections_total },
    { label: 'Faculty assigned', value: props.progress.faculty_assigned, total: props.progress.total_subjects },
    { label: 'Rooms assigned', value: props.progress.rooms_assigned, total: props.progress.total_subjects },
]);
</script>

<template>
    <div class="neu-card flex h-full flex-col rounded-2xl p-6 transition-colors duration-300">
        <h2 class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Scheduling Progress</h2>

        <div class="mt-4 flex flex-1 flex-col items-center gap-6 sm:flex-row">
            <!-- Ring -->
            <div class="relative h-40 w-40 flex-none">
                <svg viewBox="0 0 120 120" class="h-full w-full -rotate-90" role="img" :aria-label="`${progress.overall_percent}% of subjects scheduled`">
                    <circle cx="60" cy="60" :r="R" fill="none" stroke-width="10" :stroke="isDark ? 'rgba(255,255,255,0.1)' : 'rgba(15,23,42,0.08)'" />
                    <circle
                        cx="60"
                        cy="60"
                        :r="R"
                        fill="none"
                        stroke-width="10"
                        stroke-linecap="round"
                        :stroke="tone.stroke"
                        :stroke-dasharray="C"
                        :stroke-dashoffset="dashOffset"
                    />
                </svg>
                <div class="absolute inset-0 flex flex-col items-center justify-center">
                    <span class="text-4xl font-bold tabular-nums" :class="tone.text">{{ Math.round(shown) }}<span class="text-xl">%</span></span>
                    <span class="text-xs" :class="isDark ? 'text-slate-400' : 'text-slate-500'">scheduled</span>
                </div>
            </div>

            <!-- Headline + action -->
            <div class="min-w-0 flex-1 text-center sm:text-left">
                <template v-if="progress.total_subjects > 0">
                    <p class="text-2xl font-bold tabular-nums" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                        <template v-if="scheduled !== null">{{ scheduled }} <span class="text-base font-medium" :class="isDark ? 'text-slate-400' : 'text-slate-500'">of {{ progress.total_subjects }} subjects</span></template>
                        <template v-else>{{ progress.overall_percent }}% <span class="text-base font-medium" :class="isDark ? 'text-slate-400' : 'text-slate-500'">of subjects scheduled</span></template>
                    </p>
                    <p v-if="remaining === 0" class="mt-1 text-sm font-medium" :class="isDark ? 'text-emerald-300' : 'text-emerald-700'">Every subject has a day and time.</p>
                    <template v-else-if="remaining !== null">
                        <p class="mt-1 text-sm" :class="isDark ? 'text-slate-300' : 'text-slate-600'">
                            {{ remaining }} {{ remaining === 1 ? 'subject is' : 'subjects are' }} still waiting for a day and time.
                        </p>
                        <Link
                            :href="route('scheduling.section-subjects')"
                            class="mt-3 inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-md shadow-blue-600/25 transition hover:bg-blue-700"
                        >
                            Open Section Subjects
                            <i class="pi pi-arrow-right text-xs"></i>
                        </Link>
                    </template>
                </template>
                <p v-else class="text-sm" :class="isDark ? 'text-slate-400' : 'text-slate-500'">No subjects to schedule yet.</p>
            </div>
        </div>

        <div class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div v-for="stat in stats" :key="stat.label" class="neu-inset rounded-xl p-4">
                <p class="text-xs font-medium" :class="isDark ? 'text-slate-400' : 'text-slate-500'">{{ stat.label }}</p>
                <p class="mt-1 text-xl font-bold tabular-nums" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                    {{ stat.value }} <span class="text-sm font-medium" :class="isDark ? 'text-slate-500' : 'text-slate-400'">/ {{ stat.total }}</span>
                </p>
            </div>
        </div>
    </div>
</template>