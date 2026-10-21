<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import InputNumber from 'primevue/inputnumber';
import Button from 'primevue/button';
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';
import InfoPopover from '@/Components/InfoPopover.vue';
import RankedColumnChart from '@/Components/Dashboard/RankedColumnChart.vue';
import ViewToggle from '@/Components/Dashboard/ViewToggle.vue';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');
// This page uses a flat, white-card style (like a typical analytics
// dashboard) instead of the app's usual neumorphic "neu-card" system —
// scoped to this page only, per an explicit design request, so other
// pages are untouched.
const flatCard = computed(() => (isDark.value ? 'bg-slate-800 border border-white/5' : 'bg-white border border-slate-100'));
const flatTrack = computed(() => (isDark.value ? 'bg-white/10' : 'bg-slate-100'));

const page = usePage();
const toast = useToast();
const canChangeMaxLoad = computed(() => !!page.props.auth?.can?.changeFacultyMaxLoad);

const props = defineProps({
    activeTerm: { type: Object, default: null },
    stats: { type: Object, required: true },
    alerts: { type: Object, required: true },
    collegeProgress: { type: Array, default: () => [] },
    facultyUtilization: { type: Array, default: () => [] },
    roomUtilization: { type: Array, default: () => [] },
    recentActivity: { type: Array, default: () => [] },
});

const termLabel = computed(() => {
    if (!props.activeTerm) return 'No Active Academic Term';
    return `${props.activeTerm.school_year} | ${props.activeTerm.semester}`;
});

// ADD UNITS — quick edit of a faculty member's load ceiling right from
// the Faculty Utilization widget, instead of navigating to their full
// Faculty Details page just to change one number. Posts to the same
// changeMaxLoad-gated endpoint the Faculty Details page uses, so the
// Registrar sees the exact same cap/authorization rules here.
const addUnitsDialogVisible = ref(false);
const addUnitsTarget = ref(null);
const addUnitsForm = useForm({ value: 0 });

const openAddUnits = (faculty) => {
    addUnitsTarget.value = faculty;
    addUnitsForm.clearErrors();
    addUnitsForm.value = faculty.max;
    addUnitsDialogVisible.value = true;
};

const closeAddUnits = () => {
    addUnitsDialogVisible.value = false;
    addUnitsTarget.value = null;
    addUnitsForm.clearErrors();
};

const submitAddUnits = () => {
    if (!addUnitsTarget.value) return;

    addUnitsForm.patch(route('scheduling.faculty.max-load.update', addUnitsTarget.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            toast.add({ severity: 'success', summary: 'Updated', detail: `${addUnitsTarget.value.name}'s maximum load was updated.`, life: 2500 });
            closeAddUnits();
        },
        onError: () => {
            toast.add({ severity: 'warn', summary: 'Could not update', detail: 'Please check the value and try again.', life: 3000 });
        },
    });
};

const alertItems = computed(() => {
    const items = [];
    if (props.alerts.no_faculty > 0) {
        items.push({
            text: `${props.alerts.no_faculty} subject${props.alerts.no_faculty === 1 ? '' : 's'} have no faculty assigned.`,
            href: route('scheduling.sections'),
        });
    }
    if (props.alerts.no_room > 0) {
        items.push({
            text: `${props.alerts.no_room} subject${props.alerts.no_room === 1 ? '' : 's'} have no room assigned.`,
            href: route('scheduling.sections'),
        });
    }
    if (props.alerts.faculty_overload > 0) {
        items.push({
            text: `${props.alerts.faculty_overload} faculty overload${props.alerts.faculty_overload === 1 ? '' : 's'} detected.`,
            href: route('scheduling.faculty'),
        });
    }
    if (props.alerts.room_conflicts > 0) {
        items.push({
            text: `${props.alerts.room_conflicts} room conflict${props.alerts.room_conflicts === 1 ? '' : 's'} found.`,
            href: route('scheduling.rooms'),
        });
    }
    if (props.alerts.sections_needing_scheduling > 0) {
        items.push({
            text: `${props.alerts.sections_needing_scheduling} section${props.alerts.sections_needing_scheduling === 1 ? '' : 's'} still require manual scheduling.`,
            href: route('scheduling.sections'),
        });
    }
    return items;
});

// Scheduling Alerts container — turns red whenever there is at least one
// alert so it reads as "needs action" at a glance, and falls back to the
// regular flat card (with the green all-clear message) when there is none.
const alertCard = computed(() => {
    if (alertItems.value.length === 0) return flatCard.value;
    return isDark.value ? 'bg-red-950/40 border border-red-500/30' : 'bg-red-50 border border-red-200';
});

// Summary stat cards — icon, accent color, and glow per card. Kept
// inline (rather than the shared KpiCard, which only defines 4 accent
// colors) since this dashboard needs seven distinct accents at a glance.
//
// NOTE: 'remaining_subjects' links to scheduling.sections (the
// sidebar's actively-maintained Sections list — Type/Prospectus/
// Scheduling-status columns), NOT scheduling.section-subjects (an
// older, thinner list page with the same rows but fewer columns).
// Both pages exist and both eventually reach the same section detail
// view, but sending Dashboard traffic to the stale one is why it used
// to look like a "different" Sections page depending on how you got
// there — same fix applied to every alert href above.
const statCards = computed(() => {
    const totalSubjects = props.stats.total_subjects || 1;
    return [
        { key: 'total_sections', label: 'Sections', value: props.stats.total_sections, icon: 'pi-th-large', color: isDark.value ? '#5B9CFF' : '#2563EB', glow: isDark.value ? 'rgba(91, 156, 255, 0.3)' : 'rgba(37, 99, 235, 0.25)' },
        { key: 'total_subjects', label: 'Subjects', value: props.stats.total_subjects, icon: 'pi-book', color: isDark.value ? '#5B9CFF' : '#2563EB', glow: isDark.value ? 'rgba(91, 156, 255, 0.3)' : 'rgba(37, 99, 235, 0.25)' },
        { key: 'scheduled_subjects', label: 'Successfully Scheduled', value: props.stats.scheduled_subjects, icon: 'pi-check-circle', color: isDark.value ? '#34D399' : '#059669', glow: isDark.value ? 'rgba(52, 211, 153, 0.3)' : 'rgba(5, 150, 105, 0.25)', share: Math.min(100, (props.stats.scheduled_subjects / totalSubjects) * 100) },
        { key: 'remaining_subjects', label: 'Needs Scheduling', value: props.stats.remaining_subjects, icon: 'pi-exclamation-circle', color: isDark.value ? '#FBBF24' : '#D97706', glow: isDark.value ? 'rgba(251, 191, 36, 0.3)' : 'rgba(217, 119, 6, 0.25)', href: route('scheduling.sections'), share: Math.min(100, (props.stats.remaining_subjects / totalSubjects) * 100) },
        { key: 'completion', label: 'Complete', value: `${props.stats.completion}%`, icon: 'pi-percentage', color: isDark.value ? '#5B9CFF' : '#2563EB', glow: isDark.value ? 'rgba(91, 156, 255, 0.3)' : 'rgba(37, 99, 235, 0.25)' },
        { key: 'active_rooms', label: 'Rooms Used', value: props.stats.active_rooms, icon: 'pi-building', color: isDark.value ? '#C4B5FD' : '#7C3AED', glow: isDark.value ? 'rgba(196, 181, 253, 0.3)' : 'rgba(124, 58, 237, 0.25)' },
        { key: 'active_faculty', label: 'Faculty Assigned', value: props.stats.active_faculty, icon: 'pi-users', color: isDark.value ? '#C4B5FD' : '#7C3AED', glow: isDark.value ? 'rgba(196, 181, 253, 0.3)' : 'rgba(124, 58, 237, 0.25)' },
    ];
});

// Scheduling Progress widget — ring color and status line react to how
// close the term is to fully scheduled, using the same three-tier
// thresholds as the faculty load bars below (comfortable / watch /
// needs attention) so the dashboard reads consistently at a glance.
const progressRingColor = computed(() => {
    if (props.stats.completion >= 90) return isDark.value ? '#34D399' : '#059669';
    if (props.stats.completion >= 70) return isDark.value ? '#5B9CFF' : '#2563EB';
    return isDark.value ? '#FBBF24' : '#D97706';
});

const progressStatus = computed(() => {
    const pct = props.stats.completion;
    if (pct >= 90) {
        return { label: 'Almost there', icon: 'pi-check-circle', textClass: isDark.value ? 'text-emerald-400' : 'text-emerald-600' };
    }
    if (pct >= 70) {
        return { label: 'On track', icon: 'pi-arrow-up-right', textClass: isDark.value ? 'text-[#5B9CFF]' : 'text-[#2563EB]' };
    }
    return { label: 'Needs attention', icon: 'pi-exclamation-triangle', textClass: isDark.value ? 'text-amber-400' : 'text-amber-600' };
});

// Faculty load status — one scale drives the bar color, the status chip
// and the "left" text so they can never disagree with each other.
//   over  : more than the ceiling        (red)
//   near  : 85%+ of the ceiling          (amber)
//   good  : comfortably loaded           (green)
//   low   : under 40% — has room to take more (blue chip)
//   idle  : nothing assigned yet — still listed, at the bottom (gray)
const facultyStatus = (units, max) => {
    if (!units) return { key: 'idle', label: 'Unassigned', bar: 'from-slate-300 to-slate-300', chip: isDark.value ? 'bg-slate-500/20 text-slate-300' : 'bg-slate-100 text-slate-500' };
    if (!max) return { key: 'none', label: 'No limit set', bar: 'from-slate-400 to-slate-300', chip: isDark.value ? 'bg-slate-500/20 text-slate-300' : 'bg-slate-100 text-slate-500' };
    const ratio = units / max;
    if (ratio > 1) return { key: 'over', label: 'Over limit', bar: isDark.value ? 'from-red-500 to-rose-400' : 'from-red-600 to-red-400', chip: isDark.value ? 'bg-red-500/20 text-red-300' : 'bg-red-50 text-red-600' };
    if (ratio >= 0.85) return { key: 'near', label: 'Near limit', bar: isDark.value ? 'from-amber-500 to-orange-400' : 'from-amber-600 to-amber-400', chip: isDark.value ? 'bg-amber-500/20 text-amber-300' : 'bg-amber-50 text-amber-700' };
    if (ratio < 0.4) return { key: 'low', label: 'Available', bar: isDark.value ? 'from-blue-500 to-blue-400' : 'from-blue-600 to-blue-400', chip: isDark.value ? 'bg-sky-500/20 text-sky-300' : 'bg-sky-50 text-sky-700' };
    return { key: 'good', label: 'Healthy', bar: isDark.value ? 'from-emerald-500 to-emerald-400' : 'from-emerald-600 to-emerald-400', chip: isDark.value ? 'bg-emerald-500/20 text-emerald-300' : 'bg-emerald-50 text-emerald-700' };
};

const facultySummary = computed(() => {
    const counts = { over: 0, near: 0, assigned: 0, unassigned: 0 };
    props.facultyUtilization.forEach((f) => {
        const key = facultyStatus(f.units, f.max).key;
        if (key in counts) counts[key] += 1;
        if (key === 'idle') counts.unassigned += 1;
        else counts.assigned += 1;
    });
    return counts;
});

const unitsLeftLabel = (f) => {
    if (!f.max) return '';
    const left = f.max - f.units;
    if (left < 0) return `${Math.abs(left)} over`;
    return `${left} left`;
};

// Avatar — initials on a color picked deterministically from the name, so
// the same person always gets the same color across reloads.
const AVATAR_COLORS = ['#2563EB', '#7C3AED', '#0D9488', '#DB2777', '#EA580C', '#4F46E5', '#0891B2', '#65A30D'];
const initialsOf = (name) => {
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    if (parts.length === 1) return parts[0].charAt(0).toUpperCase();
    return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
};
const avatarColor = (name) => {
    let hash = 0;
    for (const ch of String(name || '')) hash = (hash * 31 + ch.charCodeAt(0)) >>> 0;
    return AVATAR_COLORS[hash % AVATAR_COLORS.length];
};

// Room tier — drives the bar color and the chip. Rooms with no bookings at
// all stay in the list at 0% ("Unused") so the ranking covers every room.
const roomTier = (percent) => {
    if (percent >= 75) return { label: 'Busy', color: isDark.value ? '#5B9CFF' : '#2563EB', chip: isDark.value ? 'bg-blue-500/20 text-blue-300' : 'bg-blue-50 text-blue-700' };
    if (percent >= 40) return { label: 'Moderate', color: isDark.value ? '#22D3EE' : '#0891B2', chip: isDark.value ? 'bg-cyan-500/20 text-cyan-300' : 'bg-cyan-50 text-cyan-700' };
    if (percent > 0) return { label: 'Light', color: isDark.value ? '#818CF8' : '#6366F1', chip: isDark.value ? 'bg-indigo-500/20 text-indigo-300' : 'bg-indigo-50 text-indigo-600' };
    return { label: 'Unused', color: isDark.value ? '#64748B' : '#CBD5E1', chip: isDark.value ? 'bg-slate-500/20 text-slate-300' : 'bg-slate-100 text-slate-500' };
};

// Bars/segments start empty and fill in once mounted, so the Meter view
// animates in a single time on load (skipped for reduced-motion — see
// the scoped style at the bottom). The Bars view animates itself.
const barsReady = ref(false);
onMounted(() => nextTick(() => requestAnimationFrame(() => { barsReady.value = true; })));

// Room meter — a row of SEGMENTS lit in proportion to utilization, like
// a signal/battery meter.
const ROOM_SEGMENTS = 20;
const litSegments = (percent) => {
    if (!barsReady.value) return 0;
    const pct = Math.max(0, Math.min(100, percent));
    // Any real usage lights at least one segment so it never looks empty.
    return pct > 0 ? Math.max(1, Math.round((pct / 100) * ROOM_SEGMENTS)) : 0;
};

// Meter | Bars — each utilization card has its own switch, remembered per
// browser so the choice survives a reload. Storage can be blocked (private
// mode), so every access is guarded and falls back to Bars.
const VIEW_STORAGE_KEY = 'classly.dashboard.view.';
const readView = (name) => {
    try {
        return window.localStorage.getItem(VIEW_STORAGE_KEY + name) === 'meter' ? 'meter' : 'bars';
    } catch {
        return 'bars';
    }
};
const writeView = (name, value) => {
    try {
        window.localStorage.setItem(VIEW_STORAGE_KEY + name, value);
    } catch {
        // ignore — the switch still works for this visit
    }
};
const facultyView = ref(readView('faculty'));
const roomView = ref(readView('room'));
watch(facultyView, (value) => writeView('faculty', value));
watch(roomView, (value) => writeView('room', value));

// Faculty column color — same tiers as facultyStatus(), as a flat color
// for the column chart.
const facultyBarColor = (units, max) => {
    const dark = isDark.value;
    return {
        idle: dark ? '#475569' : '#CBD5E1',
        none: dark ? '#64748B' : '#94A3B8',
        low: dark ? '#5B9CFF' : '#2563EB',
        good: dark ? '#34D399' : '#059669',
        near: dark ? '#FBBF24' : '#D97706',
        over: dark ? '#F87171' : '#DC2626',
    }[facultyStatus(units, max).key];
};

const facultyChartItems = computed(() => props.facultyUtilization.map((f) => ({
    key: f.id,
    label: f.name,
    valueLabel: f.max ? `${f.units}/${f.max}` : `${f.units}`,
    percent: f.max ? (f.units / f.max) * 100 : 0,
    zero: f.units === 0,
    color: facultyBarColor(f.units, f.max),
    title: f.max
        ? `${f.name} — ${f.units} of ${f.max} ${f.unit_label.toLowerCase()} (${f.percent}%)`
        : `${f.name} — ${f.units} ${f.unit_label.toLowerCase()}`,
})));

const roomChartItems = computed(() => props.roomUtilization.map((r) => ({
    key: r.id,
    label: r.name,
    valueLabel: `${r.occupancy}%`,
    percent: r.occupancy,
    zero: r.occupancy === 0,
    color: roomTier(r.occupancy).color,
    title: `${r.name} — ${r.occupancy}% utilized`,
})));

// Click a faculty column to see their load and (if allowed) Add Units.
const selectedFacultyId = ref(null);
const selectedFaculty = computed(() => props.facultyUtilization.find((f) => f.id === selectedFacultyId.value) ?? null);

const collegeBarClass = (percent) => {
    if (percent >= 90) return isDark.value ? 'bg-emerald-400' : 'bg-emerald-600';
    if (percent >= 50) return isDark.value ? 'bg-[#5B9CFF]' : 'bg-teal-600';
    return isDark.value ? 'bg-amber-400' : 'bg-amber-500';
};

const activityStatusSeverity = (status) => {
    if (status === 'Scheduled') return 'success';
    if (status === 'Conflict') return 'danger';
    return 'warn';
};

const quickActions = [
    { label: 'Generate All Schedules', icon: 'pi-bolt', href: 'scheduling.sections', accent: true },
    { label: 'Validate Schedules', icon: 'pi-check-square', href: 'scheduling.sections' },
    { label: 'Detect Conflicts', icon: 'pi-search', href: 'scheduling.sections' },
    // Reuses the existing Master Schedule report (Reports > Scheduling)
    // rather than a separate export — passing report_type in the query
    // makes ReportsController generate it immediately on load, so this
    // lands straight on the ready-to-print/export table.
    { label: 'Export Master Schedule', icon: 'pi-download', href: 'reports', params: { report_type: 'master_schedule' } },
];
</script>

<template>
    <Head title="Scheduling Dashboard" />

    <AppLayout>
        <template #header>
            <span class="text-lg font-semibold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Scheduling Dashboard</span>
        </template>

        <div class="w-full">
            <!-- Page Title -->
            <div class="relative mb-3 overflow-hidden rounded-2xl shadow-sm" :class="flatCard">
                <div class="relative flex flex-wrap items-start justify-between gap-3 rounded-2xl px-4 py-3 transition-colors duration-300">
                    <div>
                        <h1 class="text-xl font-bold tracking-tight flex items-center gap-2" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                            Scheduling Dashboard
                            <InfoPopover
                                title="Scheduling Dashboard"
                                :paragraphs="[
                                    'A live overview of scheduling progress, conflicts, and workload for the active academic term.',
                                ]"
                                :bullets="[
                                    'Stat cards and progress reflect the active academic term shown at the top right.',
                                    'Alerts link straight to the relevant workspace so you can act on them immediately.',
                                ]"
                            />
                        </h1>
                        <p class="mt-0.5 max-w-3xl text-sm" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                            Monitor scheduling progress, detect conflicts, validate schedules, and manage the overall
                            scheduling process for the active semester.
                        </p>
                    </div>
                    <span
                        class="flex items-center gap-2 self-start rounded-full px-3 py-1.5 text-sm font-semibold"
                        :class="isDark ? 'bg-[#5B9CFF]/10 text-[#5B9CFF]' : 'bg-blue-50 text-[#2563EB]'"
                    >
                        <i class="pi pi-calendar"></i>
                        {{ termLabel }}
                    </span>
                </div>
            </div>

            <!-- Summary Stat Cards -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-7">
                <component
                    :is="card.href ? Link : 'div'"
                    v-for="card in statCards"
                    :key="card.key"
                    :href="card.href"
                    class="group relative overflow-hidden rounded-xl p-3 shadow-sm transition-all duration-300"
                    :class="[flatCard, card.href ? 'cursor-pointer hover:-translate-y-0.5 hover:shadow-lg' : 'hover:-translate-y-0.5 hover:shadow-md']"
                >
                    <!-- Colored top edge instead of a plain white card, so
                    each stat carries its own identity at a glance even
                    before you read the number. -->
                    <span class="absolute inset-x-0 top-0 h-1" :style="{ backgroundColor: card.color }"></span>

                    <!-- Oversized faint icon in the corner as a watermark —
                    decorative, doesn't compete with the real number. -->
                    <i
                        class="pi absolute -right-2 -top-1 text-5xl opacity-[0.07] transition-transform duration-300 group-hover:scale-110"
                        :class="card.icon"
                        :style="{ color: card.color }"
                    ></i>

                    <div class="relative">
                        <span
                            class="flex h-8 w-8 items-center justify-center rounded-lg"
                            :style="{ backgroundColor: card.color + '1A' }"
                        >
                            <i class="pi text-sm" :class="[card.icon]" :style="{ color: card.color }"></i>
                        </span>
                        <p class="mt-2 text-xl font-bold tabular-nums leading-none" :class="isDark ? 'text-white' : 'text-[#1E293B]'">{{ card.value }}</p>
                        <p class="mt-1 text-[11px] font-semibold uppercase leading-tight tracking-wide" :class="isDark ? 'text-slate-400' : 'text-slate-400'">{{ card.label }}</p>

                        <!-- Scheduled / Needs Scheduling carry the same
                        subjects total, so a tiny share-of-total bar makes
                        each number legible as a proportion, not just a
                        count sitting next to six other counts. -->
                        <div v-if="card.share !== undefined" class="mt-1.5 h-1 w-full overflow-hidden rounded-full" :class="isDark ? 'bg-white/10' : 'bg-slate-100'">
                            <div class="h-full rounded-full transition-all duration-700 ease-out" :style="{ width: card.share + '%', backgroundColor: card.color }"></div>
                        </div>
                    </div>
                </component>
            </div>

            <!-- Scheduling Progress -->
            <div class="relative mt-3 overflow-hidden rounded-2xl p-4 shadow-sm transition-colors duration-300" :class="flatCard">
                <div class="relative flex flex-col gap-4 md:flex-row md:items-center">
                    <!-- Progress ring -->
                    <div class="flex flex-none items-center gap-4">
                        <div class="relative h-24 w-24 flex-none">
                            <svg viewBox="0 0 120 120" class="h-24 w-24 -rotate-90">
                                <circle cx="60" cy="60" r="52" fill="none" stroke-width="12" :stroke="isDark ? 'rgba(148,163,184,0.15)' : 'rgba(100,116,139,0.12)'" />
                                <circle
                                    cx="60" cy="60" r="52" fill="none" stroke-width="12" stroke-linecap="round"
                                    :stroke="progressRingColor"
                                    stroke-dasharray="326.7"
                                    :stroke-dashoffset="326.7 - (326.7 * Math.min(100, stats.completion)) / 100"
                                    class="transition-all duration-700 ease-out"
                                />
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <span class="text-2xl font-bold tabular-nums leading-none" :class="isDark ? 'text-white' : 'text-[#1E293B]'">{{ stats.completion }}%</span>
                                <span class="text-[10px] font-medium" :class="isDark ? 'text-slate-400' : 'text-slate-500'">complete</span>
                            </div>
                        </div>

                        <div>
                            <p class="text-base font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Scheduling Progress</p>
                            <p class="mt-0.5 flex items-center gap-1.5 text-sm font-medium" :class="progressStatus.textClass">
                                <i class="pi text-xs" :class="progressStatus.icon"></i>
                                {{ progressStatus.label }}
                            </p>
                            <p class="mt-1 text-sm" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                                {{ stats.scheduled_subjects }} of {{ stats.total_subjects }} subjects scheduled this term.
                            </p>
                        </div>
                    </div>

                    <!-- Breakdown -->
                    <div class="grid flex-1 grid-cols-2 gap-3 md:pl-4 md:border-l" :class="isDark ? 'md:border-white/10' : 'md:border-slate-200'">
                        <div class="flex items-center gap-3 rounded-xl px-3 py-2" :class="flatTrack">
                            <span class="flex h-8 w-8 flex-none items-center justify-center rounded-lg" :class="isDark ? 'bg-emerald-400/20' : 'bg-emerald-100'">
                                <i class="pi pi-check-circle text-sm" :class="isDark ? 'text-emerald-400' : 'text-emerald-600'"></i>
                            </span>
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wide" :class="isDark ? 'text-slate-400' : 'text-slate-400'">Scheduled</span>
                                <p class="text-xl font-bold leading-tight" :class="isDark ? 'text-white' : 'text-[#1E293B]'">{{ stats.scheduled_subjects }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 rounded-xl px-3 py-2" :class="flatTrack">
                            <span class="flex h-8 w-8 flex-none items-center justify-center rounded-lg" :class="isDark ? 'bg-amber-400/20' : 'bg-amber-100'">
                                <i class="pi pi-exclamation-circle text-sm" :class="isDark ? 'text-amber-400' : 'text-amber-600'"></i>
                            </span>
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wide" :class="isDark ? 'text-slate-400' : 'text-slate-400'">Remaining</span>
                                <p class="text-xl font-bold leading-tight" :class="isDark ? 'text-white' : 'text-[#1E293B]'">{{ stats.remaining_subjects }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-2">
                <!-- College Progress -->
                <div class="rounded-2xl p-4 shadow-sm transition-colors duration-300" :class="flatCard">
                    <p class="text-base font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">College Progress</p>
                    <div v-if="collegeProgress.length === 0" class="mt-3 text-sm" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                        No sections found for the active term.
                    </div>
                    <!-- A handful of colleges compare well as a bar
                    chart; the faculty/room lists below stay as
                    horizontal bars since a dozen+ items reads better
                    as a scannable list than clustered columns. -->
                    <div v-else class="relative mt-3 flex h-36 items-stretch gap-4 pl-8">
                        <!-- Y-axis gridlines / labels -->
                        <div class="absolute inset-y-0 left-0 flex w-8 flex-col justify-between pb-6 text-[11px]" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                            <span>100%</span>
                            <span>50%</span>
                            <span>0%</span>
                        </div>
                        <div class="absolute inset-y-0 left-8 right-0 flex flex-col justify-between pb-6">
                            <div class="border-t border-dashed" :class="isDark ? 'border-white/10' : 'border-slate-200'"></div>
                            <div class="border-t border-dashed" :class="isDark ? 'border-white/10' : 'border-slate-200'"></div>
                            <div class="border-t border-dashed" :class="isDark ? 'border-white/10' : 'border-slate-200'"></div>
                        </div>

                        <!-- Bars -->
                        <div class="relative flex flex-1 items-end justify-around gap-4 pl-1">
                            <div v-for="college in collegeProgress" :key="college.id" class="group flex h-full flex-1 flex-col items-center justify-end">
                                <span class="mb-1 text-xs font-semibold opacity-0 transition-opacity group-hover:opacity-100" :class="isDark ? 'text-white' : 'text-[#1E293B]'">{{ college.percent }}%</span>
                                <div
                                    class="w-full max-w-[3.5rem] rounded-t-lg transition-all duration-700 ease-out"
                                    :class="collegeBarClass(college.percent)"
                                    :style="{ height: Math.max(4, college.percent) + '%' }"
                                ></div>
                                <span class="mt-2 max-w-[5.5rem] truncate text-[11px] font-medium" :class="isDark ? 'text-slate-400' : 'text-slate-500'" :title="college.name">{{ college.name }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Scheduling Alerts -->
                <div class="rounded-2xl p-4 shadow-sm transition-colors duration-300" :class="alertCard">
                    <div class="flex items-center justify-between gap-2">
                        <p class="flex items-center gap-2 text-base font-bold" :class="alertItems.length ? (isDark ? 'text-red-200' : 'text-red-700') : (isDark ? 'text-white' : 'text-[#1E293B]')">
                            <i v-if="alertItems.length" class="pi pi-exclamation-circle"></i>
                            Scheduling Alerts
                        </p>
                        <span
                            v-if="alertItems.length"
                            class="rounded-full px-2.5 py-0.5 text-xs font-bold"
                            :class="isDark ? 'bg-red-500/25 text-red-200' : 'bg-red-600 text-white'"
                        >{{ alertItems.length }} {{ alertItems.length === 1 ? 'issue' : 'issues' }}</span>
                    </div>
                    <div
                        v-if="alertItems.length === 0"
                        class="mt-3 flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-medium"
                        :class="isDark ? 'bg-emerald-400/10 text-emerald-300' : 'bg-emerald-50 text-emerald-700'"
                    >
                        <i class="pi pi-check-circle"></i>
                        No scheduling conflicts detected.
                    </div>
                    <Link
                        v-for="(alert, idx) in alertItems"
                        :key="idx"
                        :href="alert.href"
                        class="mt-2 flex items-center gap-2 rounded-xl border-l-4 px-3 py-2 text-sm font-medium transition-colors first:mt-3 hover:brightness-95"
                        :class="isDark ? 'border-red-400 bg-red-500/10 text-red-200' : 'border-red-500 bg-white text-red-800 shadow-sm'"
                    >
                        <i class="pi pi-exclamation-triangle" :class="isDark ? 'text-red-400' : 'text-red-500'"></i>
                        {{ alert.text }}
                    </Link>
                </div>
            </div>

            <div class="mt-3 flex flex-col gap-3">
                <!-- Faculty Utilization — every faculty member, ranked by load -->
                <div class="rounded-2xl p-4 shadow-sm transition-colors duration-300" :class="flatCard">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-base font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Faculty Utilization</p>
                        <div class="flex flex-wrap items-center gap-2">
                            <div v-if="facultyUtilization.length" class="flex flex-wrap items-center gap-1.5 text-xs font-semibold">
                                <span v-if="facultySummary.over" class="rounded-full px-2 py-0.5" :class="isDark ? 'bg-red-500/20 text-red-300' : 'bg-red-50 text-red-600'">{{ facultySummary.over }} over</span>
                                <span v-if="facultySummary.near" class="rounded-full px-2 py-0.5" :class="isDark ? 'bg-amber-500/20 text-amber-300' : 'bg-amber-50 text-amber-700'">{{ facultySummary.near }} near limit</span>
                                <span class="rounded-full px-2 py-0.5" :class="isDark ? 'bg-sky-500/20 text-sky-300' : 'bg-sky-50 text-sky-700'">{{ facultySummary.assigned }} assigned</span>
                                <span class="rounded-full px-2 py-0.5" :class="isDark ? 'bg-slate-500/20 text-slate-300' : 'bg-slate-100 text-slate-500'">{{ facultySummary.unassigned }} unassigned</span>
                            </div>
                            <ViewToggle v-model="facultyView" :is-dark="isDark" label="Faculty utilization view" />
                        </div>
                    </div>
                    <div v-if="facultyUtilization.length === 0" class="mt-3 text-sm" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                        No faculty found.
                    </div>
                    <template v-else>
                        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-slate-300"></span>Unassigned</span>
                            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-blue-600"></span>Available</span>
                            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-emerald-600"></span>Healthy</span>
                            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-amber-600"></span>Near limit</span>
                            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-red-600"></span>Over limit</span>
                        </div>

                        <!-- BARS view: ranked columns; click one for details + Add Units -->
                        <template v-if="facultyView === 'bars'">
                            <RankedColumnChart
                                class="mt-3"
                                :items="facultyChartItems"
                                :is-dark="isDark"
                                selectable
                                :selected-key="selectedFacultyId"
                                @select="(id) => (selectedFacultyId = selectedFacultyId === id ? null : id)"
                            />

                            <div class="mt-2 flex flex-wrap items-center justify-between gap-3 rounded-xl px-3 py-2 text-sm" :class="flatTrack">
                                <template v-if="selectedFaculty">
                                    <span class="flex min-w-0 items-center gap-2">
                                        <img
                                            v-if="selectedFaculty.photo_url"
                                            :src="selectedFaculty.photo_url"
                                            :alt="selectedFaculty.name"
                                            class="h-8 w-8 flex-none rounded-full object-cover"
                                        />
                                        <span
                                            v-else
                                            class="flex h-8 w-8 flex-none items-center justify-center rounded-full text-xs font-bold text-white"
                                            :style="{ backgroundColor: avatarColor(selectedFaculty.name) }"
                                            aria-hidden="true"
                                        >{{ initialsOf(selectedFaculty.name) }}</span>
                                        <span class="truncate font-medium" :class="isDark ? 'text-slate-200' : 'text-slate-700'">{{ selectedFaculty.name }}</span>
                                        <span class="flex-none rounded-full px-2 py-0.5 text-[11px] font-semibold leading-tight" :class="facultyStatus(selectedFaculty.units, selectedFaculty.max).chip">
                                            {{ facultyStatus(selectedFaculty.units, selectedFaculty.max).label }}
                                        </span>
                                    </span>
                                    <span class="flex flex-none items-center gap-3">
                                        <span class="tabular-nums" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                                            <span class="font-semibold" :class="isDark ? 'text-slate-200' : 'text-slate-700'">{{ selectedFaculty.units }}</span>
                                            / {{ selectedFaculty.max }} {{ selectedFaculty.unit_label.toLowerCase() }} · {{ selectedFaculty.percent }}%<template v-if="selectedFaculty.max"> · {{ unitsLeftLabel(selectedFaculty) }}</template>
                                        </span>
                                        <button
                                            v-if="canChangeMaxLoad"
                                            type="button"
                                            class="flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-semibold transition-colors"
                                            :class="isDark ? 'border-emerald-400/40 text-emerald-300 hover:bg-emerald-400/10' : 'border-emerald-200 text-emerald-600 hover:bg-emerald-50'"
                                            :aria-label="`Add units for ${selectedFaculty.name}`"
                                            @click="openAddUnits(selectedFaculty)"
                                        >
                                            <i class="pi pi-plus text-[10px]"></i>
                                            Add Units
                                        </button>
                                    </span>
                                </template>
                                <span v-else class="text-xs" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Select a bar to see that faculty member's load.</span>
                            </div>

                            <p class="mt-2 text-xs" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                                {{ facultyUtilization.length }} faculty · ranked by load, then name · scroll sideways to see everyone
                            </p>
                        </template>

                        <!-- METER view: one ranked row per faculty member -->
                        <template v-else>
                            <div class="mt-2 max-h-[28rem] overflow-y-auto pr-1">
                                <div
                                    v-for="(f, i) in facultyUtilization"
                                    :key="f.id"
                                    class="util-row group -mx-2 flex items-center gap-3 rounded-xl px-2 py-1.5 transition-colors duration-200"
                                    :class="isDark ? 'hover:bg-white/5' : 'hover:bg-slate-50'"
                                    :title="f.max ? `${f.units} of ${f.max} ${f.unit_label.toLowerCase()} assigned (${unitsLeftLabel(f)})` : `${f.units} ${f.unit_label.toLowerCase()} assigned`"
                                >
                                    <span class="w-6 flex-none text-right text-xs font-semibold tabular-nums" :class="isDark ? 'text-slate-500' : 'text-slate-400'">{{ i + 1 }}</span>
                                    <img
                                        v-if="f.photo_url"
                                        :src="f.photo_url"
                                        :alt="f.name"
                                        class="h-8 w-8 flex-none rounded-full object-cover"
                                    />
                                    <span
                                        v-else
                                        class="flex h-8 w-8 flex-none items-center justify-center rounded-full text-xs font-bold text-white"
                                        :style="{ backgroundColor: avatarColor(f.name) }"
                                        aria-hidden="true"
                                    >{{ initialsOf(f.name) }}</span>
                                    <div class="min-w-0 flex-1">
                                        <div class="mb-1 flex items-center justify-between gap-2 text-sm">
                                            <span class="flex min-w-0 items-center gap-2">
                                                <span class="truncate font-medium" :class="isDark ? 'text-slate-200' : 'text-slate-700'">{{ f.name }}</span>
                                                <span class="flex-none rounded-full px-2 py-0.5 text-[11px] font-semibold leading-tight" :class="facultyStatus(f.units, f.max).chip">
                                                    {{ facultyStatus(f.units, f.max).label }}
                                                </span>
                                            </span>
                                            <span class="flex flex-none items-center gap-2 pl-2">
                                                <button
                                                    v-if="canChangeMaxLoad"
                                                    type="button"
                                                    class="util-action flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-semibold transition-colors"
                                                    :class="isDark ? 'border-emerald-400/40 text-emerald-300 hover:bg-emerald-400/10' : 'border-emerald-200 text-emerald-600 hover:bg-emerald-50'"
                                                    :aria-label="`Add units for ${f.name}`"
                                                    @click="openAddUnits(f)"
                                                >
                                                    <i class="pi pi-plus text-[10px]"></i>
                                                    Add Units
                                                </button>
                                                <span class="tabular-nums" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                                                    <span class="font-semibold" :class="isDark ? 'text-slate-200' : 'text-slate-700'">{{ f.units }}</span> / {{ f.max }}
                                                    <span class="ml-1 inline-block w-10 text-right">{{ f.percent }}%</span>
                                                </span>
                                            </span>
                                        </div>
                                        <div class="h-2.5 w-full overflow-hidden rounded-full" :class="flatTrack">
                                            <div
                                                class="util-fill h-full rounded-full bg-gradient-to-r"
                                                :class="facultyStatus(f.units, f.max).bar"
                                                :style="{ width: (barsReady && f.max ? Math.min(100, (f.units / f.max) * 100) : 0) + '%' }"
                                            ></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <p class="mt-2 border-t pt-2 text-xs" :class="isDark ? 'border-white/10 text-slate-500' : 'border-slate-100 text-slate-400'">
                                {{ facultyUtilization.length }} faculty · ranked by load, then name
                            </p>
                        </template>
                    </template>
                </div>

                <!-- Room Utilization — every room, ranked by use -->
                <div class="rounded-2xl p-4 shadow-sm transition-colors duration-300" :class="flatCard">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-base font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Room Utilization</p>
                        <div class="flex flex-wrap items-center gap-3">
                            <p v-if="roomUtilization.length" class="text-xs font-medium" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Most used rooms first</p>
                            <ViewToggle v-model="roomView" :is-dark="isDark" label="Room utilization view" />
                        </div>
                    </div>
                    <div v-if="roomUtilization.length === 0" class="mt-3 text-sm" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                        No rooms found.
                    </div>
                    <template v-else>
                        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full" :style="{ backgroundColor: roomTier(0).color }"></span>Unused</span>
                            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full" :style="{ backgroundColor: roomTier(10).color }"></span>Light</span>
                            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full" :style="{ backgroundColor: roomTier(50).color }"></span>Moderate</span>
                            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full" :style="{ backgroundColor: roomTier(80).color }"></span>Busy</span>
                        </div>

                        <!-- BARS view -->
                        <template v-if="roomView === 'bars'">
                            <RankedColumnChart class="mt-3" :items="roomChartItems" :is-dark="isDark" />
                            <p class="mt-2 text-xs" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                                {{ roomUtilization.length }} rooms · unused rooms stay at 0% · scroll sideways to see all
                            </p>
                        </template>

                        <!-- METER view: one ranked row per room, segmented like a signal meter -->
                        <template v-else>
                            <div class="mt-2 max-h-[28rem] overflow-y-auto pr-1">
                                <div
                                    v-for="(r, i) in roomUtilization"
                                    :key="r.id"
                                    class="-mx-2 flex items-center gap-3 rounded-xl px-2 py-1.5 transition-colors duration-200"
                                    :class="isDark ? 'hover:bg-white/5' : 'hover:bg-slate-50'"
                                >
                                    <span
                                        class="flex h-8 w-8 flex-none items-center justify-center rounded-lg text-sm font-bold"
                                        :style="{ color: roomTier(r.occupancy).color, backgroundColor: roomTier(r.occupancy).color + '1F' }"
                                        aria-hidden="true"
                                    >{{ i + 1 }}</span>
                                    <div class="min-w-0 flex-1">
                                        <div class="mb-1 flex items-center justify-between gap-2 text-sm">
                                            <span class="flex min-w-0 items-center gap-2">
                                                <span class="truncate font-medium" :class="isDark ? 'text-slate-200' : 'text-slate-700'">{{ r.name }}</span>
                                                <span class="flex-none rounded-full px-2 py-0.5 text-[11px] font-semibold leading-tight" :class="roomTier(r.occupancy).chip">
                                                    {{ roomTier(r.occupancy).label }}
                                                </span>
                                            </span>
                                            <span class="flex-none pl-2 font-semibold tabular-nums" :class="isDark ? 'text-slate-200' : 'text-slate-700'">{{ r.occupancy }}%</span>
                                        </div>
                                        <div class="flex gap-[3px]" role="img" :aria-label="`${r.name} ${r.occupancy}% utilized`">
                                            <span
                                                v-for="n in ROOM_SEGMENTS"
                                                :key="n"
                                                class="room-seg h-2 flex-1 rounded-[3px]"
                                                :class="n > litSegments(r.occupancy) ? flatTrack : ''"
                                                :style="n <= litSegments(r.occupancy) ? { backgroundColor: roomTier(r.occupancy).color, transitionDelay: (n * 25) + 'ms' } : { transitionDelay: '0ms' }"
                                            ></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <p class="mt-2 border-t pt-2 text-xs" :class="isDark ? 'border-white/10 text-slate-500' : 'border-slate-100 text-slate-400'">
                                {{ roomUtilization.length }} rooms · unused rooms stay at 0%
                            </p>
                        </template>
                    </template>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="mt-3 rounded-2xl p-4 shadow-sm transition-colors duration-300" :class="flatCard">
                <p class="text-base font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Quick Actions</p>
                <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <component
                        :is="action.disabled ? 'span' : Link"
                        v-for="action in quickActions"
                        :key="action.label"
                        :href="action.href ? route(action.href, action.params) : undefined"
                        v-tooltip.top="action.disabled ? 'Coming soon' : undefined"
                        class="flex items-center gap-3 rounded-xl border px-3 py-2 text-sm font-semibold transition-colors duration-300"
                        :class="[
                            action.disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer hover:shadow-sm',
                            isDark ? 'border-white/10 text-slate-200' : 'border-slate-100 text-slate-700',
                        ]"
                    >
                        <span
                            class="flex h-8 w-8 flex-none items-center justify-center rounded-full"
                            :class="action.accent ? (isDark ? 'bg-[#5B9CFF]/15' : 'bg-blue-50') : (isDark ? 'bg-white/10' : 'bg-slate-100')"
                        >
                            <i
                                class="pi text-sm"
                                :class="[action.icon, action.accent ? (isDark ? 'text-[#5B9CFF]' : 'text-[#2563EB]') : (isDark ? 'text-slate-300' : 'text-slate-500')]"
                            ></i>
                        </span>
                        {{ action.label }}
                    </component>
                </div>
                <p class="mt-2 text-xs" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                    Generating, validating, and resolving schedules happens on the Section Subjects page, and the
                    printable/exportable Master Schedule lives on Reports — these shortcuts take you straight there.
                </p>
            </div>

            <!-- Recent Scheduling Activity -->
            <div class="mt-3 rounded-2xl p-4 shadow-sm transition-colors duration-300" :class="flatCard">
                <p class="text-base font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Recent Scheduling Activity</p>
                <div v-if="recentActivity.length === 0" class="mt-3 text-sm" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                    No scheduling activity yet.
                </div>
                <div
                    v-for="(activity, idx) in recentActivity"
                    :key="idx"
                    class="mt-2 flex items-center justify-between gap-3 rounded-xl px-3 py-2 text-sm first:mt-3"
                    :class="flatTrack"
                >
                    <span :class="isDark ? 'text-slate-200' : 'text-slate-700'">{{ activity.label }}</span>
                    <div class="flex flex-none items-center gap-3">
                        <Tag :value="activity.status" :severity="activityStatusSeverity(activity.status)" />
                        <span class="w-24 text-right text-xs" :class="isDark ? 'text-slate-500' : 'text-slate-400'">{{ activity.updated_at }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Units dialog -->
        <Dialog
            v-model:visible="addUnitsDialogVisible"
            modal
            :header="addUnitsTarget ? `Update ${addUnitsTarget.name}'s Maximum Load` : 'Update Maximum Load'"
            :style="{ width: '26rem' }"
        >
            <div class="flex flex-col gap-3">
                <p class="text-sm" :class="isDark ? 'text-slate-300' : 'text-slate-600'">
                    Current load: {{ addUnitsTarget?.units }} {{ addUnitsTarget?.unit_label ?? 'Units' }}
                </p>
                <label for="addUnitsValue" class="text-sm font-medium" :class="isDark ? 'text-slate-200' : 'text-[#1E293B]'">
                    Maximum {{ addUnitsTarget?.unit_label ?? 'Units' }}
                </label>
                <InputNumber
                    inputId="addUnitsValue"
                    v-model="addUnitsForm.value"
                    :min="0"
                    showButtons
                    class="w-full"
                    :invalid="!!addUnitsForm.errors.value"
                />
                <small v-if="addUnitsForm.errors.value" class="text-red-500">{{ addUnitsForm.errors.value }}</small>
            </div>
            <template #footer>
                <Button label="Cancel" text severity="secondary" :disabled="addUnitsForm.processing" @click="closeAddUnits" />
                <Button label="Save" icon="pi pi-check" :loading="addUnitsForm.processing" @click="submitAddUnits" />
            </template>
        </Dialog>

        <Toast />
    </AppLayout>
</template>

<style scoped>
.util-fill {
    transition: width 700ms cubic-bezier(0.22, 1, 0.36, 1);
}

.room-seg {
    transition: background-color 300ms ease;
}

/* "Add Units" stays visible on touch screens; on devices with a real
   hover it tucks away until the row is hovered or focused, keeping the
   Meter list clean. */
@media (hover: hover) {
    .util-action {
        opacity: 0;
    }

    .util-row:hover .util-action,
    .util-row:focus-within .util-action {
        opacity: 1;
    }
}

@media (prefers-reduced-motion: reduce) {
    .util-fill,
    .room-seg {
        transition: none;
    }
}
</style>