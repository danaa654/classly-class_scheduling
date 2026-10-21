<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import Swal from 'sweetalert2';
import AppLayout from '@/Layouts/AppLayout.vue';
import KpiCard from '@/Components/Dashboard/KpiCard.vue';
import SchedulingProgressCard from '@/Components/Dashboard/SchedulingProgressCard.vue';
import ConflictPanel from '@/Components/Dashboard/ConflictPanel.vue';
import QuickActions from '@/Components/Dashboard/QuickActions.vue';
import InfoPopover from '@/Components/InfoPopover.vue';
import LiveClock from '@/Components/Dashboard/LiveClock.vue';
import RoomOccupancyCard from '@/Components/Dashboard/RoomOccupancyCard.vue';
import FacultyTodayCard from '@/Components/Dashboard/FacultyTodayCard.vue';

const props = defineProps({
    roles: {
        type: Array,
        default: () => [],
    },
    overview: {
        type: Object,
        required: true,
        // { scope, kpis, progress, conflicts } — see DashboardService::overview()
    },
});

// Day/moment currently shown by the Room Occupancy card; feeds the Faculty Today sidebar.
const occupancy = ref({ day: null, minute: 0, live: true, rooms: [], loading: true });

const page = usePage();
const user = page.props.auth.user;

// SCHOOL BRANDING — shared globally by HandleInertiaRequests from
// Settings → General (single source of truth).
const schoolBranding = computed(() => page.props.schoolBranding ?? { name: null, logoUrl: null });

/**
 * An Administrator required this user to change their password (User
 * Management > "Require password change on next login"). Rather than
 * relying only on the middleware's forced redirect, the Dashboard
 * also greets them with a blocking prompt on load pointing straight
 * at the right Manage Account tab — Administrators manage their own
 * account from User Management, everyone else from Settings (see
 * UsersController::updateAccount()).
 */
function promptPasswordChangeIfRequired() {
    if (!user?.must_change_password) return;

    const isAdministrator = props.roles.includes('Administrator');
    const target = isAdministrator ? route('users', { tab: 'account' }) : route('settings', { tab: 'account' });

    Swal.fire({
        icon: 'warning',
        title: 'Password Change Required',
        text: 'An Administrator requires you to change your password before continuing.',
        confirmButtonText: 'Change Password',
        confirmButtonColor: '#2563EB',
        allowOutsideClick: false,
        allowEscapeKey: false,
    }).then((result) => {
        if (result.isConfirmed) {
            router.visit(target);
        }
    });
}

// The live clock is its own component (LiveClock) so its per-second
// tick never re-renders the whole Dashboard.
onMounted(() => {
    promptPasswordChangeIfRequired();
});

// Shared on every page by HandleInertiaRequests — the Academic Term
// currently marked Active, if any. Scheduling can't run without one,
// so the Dashboard nudges Admins/Registrars to set it up.
const activeAcademicTerm = computed(() => page.props.activeAcademicTerm);
const activeAcademicTermLabel = computed(() => {
    const term = activeAcademicTerm.value;
    if (!term) return null;

    return [term.school_year?.name, term.semester?.name].filter(Boolean).join(' • ');
});

const kpis = computed(() => props.overview.kpis);
const progress = computed(() => props.overview.progress);
const conflicts = computed(() => props.overview.conflicts);
const scope = computed(() => props.overview.scope);

const roleLabel = computed(() => props.roles.join(', ') || 'No role assigned');

// HERO COLOUR — follows the user's scope. A Dean/OIC (college scope) gets a
// gradient built from their College's colour (Settings -> College Colors);
// Administrator, Registrar, Assistant Dean and any college without a colour
// use the red default. Text sits on the dark left side of the gradient, so the
// stops are shaded toward black to keep white text readable even on yellow.
const DEFAULT_HERO_COLOR = '#E11D2E';
const HEX = /^#[0-9a-f]{6}$/i;
const heroBase = computed(() => {
    const collegeId = scope.value?.college_id;
    const hex = collegeId != null ? page.props.collegeColors?.[collegeId] : null;
    return HEX.test(hex ?? '') ? hex : DEFAULT_HERO_COLOR;
});
const shade = (hex, amount) => {
    const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
    const mixed = [r, g, b].map((c) => Math.round(c * (1 - amount)));
    return `rgb(${mixed[0]}, ${mixed[1]}, ${mixed[2]})`;
};
const heroGradient = computed(
    () => `linear-gradient(120deg, ${shade(heroBase.value, 0.64)} 0%, ${shade(heroBase.value, 0.34)} 50%, ${shade(heroBase.value, 0.04)} 100%)`,
);

const heroPhoto = computed(() => user?.profile_photo_url || schoolBranding.value.logoUrl || null);
const userInitial = computed(() => (user?.name || '?').trim().charAt(0).toUpperCase());

const hour = new Date().getHours();
const greeting = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout v-slot="{ isDark }">
        <!-- No Active Academic Term — scheduling can't run yet -->
        <Link
            v-if="!activeAcademicTerm"
            :href="route('academic-calendar')"
            class="mb-6 flex items-start gap-4 rounded-2xl border px-5 py-4 transition-colors group"
            :class="isDark
                ? 'border-amber-400/25 bg-amber-500/10 hover:bg-amber-500/15'
                : 'border-amber-200 bg-amber-50 hover:bg-amber-100'"
        >
            <span
                class="flex h-10 w-10 flex-none items-center justify-center rounded-full"
                :class="isDark ? 'bg-amber-400/15 text-amber-400 group-hover:bg-amber-400/25' : 'bg-amber-100 text-amber-600 group-hover:bg-amber-200'"
            >
                <i class="pi pi-exclamation-triangle text-lg"></i>
            </span>
            <div class="flex-1">
                <p class="font-semibold" :class="isDark ? 'text-amber-300' : 'text-amber-900'">No Active Academic Term</p>
                <p class="mt-0.5 text-sm" :class="isDark ? 'text-amber-200/80' : 'text-amber-800'">
                    Admin and Registrar need to set the Active Academic Term (School Year, Semester, and Scheduling Preferences)
                    before scheduling can start.
                </p>
            </div>
            <span
                class="flex-none self-center text-sm font-semibold whitespace-nowrap"
                :class="isDark ? 'text-amber-300 group-hover:text-amber-200' : 'text-amber-700 group-hover:text-amber-900'"
            >
                Set Academic Term
                <i class="pi pi-arrow-right ml-1 text-xs"></i>
            </span>
        </Link>

        <!-- BACKDROP — same idea as My Schedule: campus photo (night photo in dark mode)
             under a colour wash, fixed behind the page so the cards scroll over it. -->
        <div
            class="pointer-events-none fixed inset-0 -z-10 overflow-hidden bg-gradient-to-br"
            :class="isDark ? 'from-[#0C2340] via-[#0B1B33] to-[#0B1220]' : 'from-[#7DD3FC] via-[#38BDF8] to-[#0284C7]'"
            aria-hidden="true"
        >
            <img :src="isDark ? '/bg-dark.png' : '/bg.png'" alt="" class="h-full w-full scale-105 object-cover object-[right_bottom] blur-[3px]" />
            <div
                class="absolute inset-0 bg-gradient-to-r"
                :class="isDark ? 'from-[#0B1220]/85 via-[#0B1220]/55 to-[#0B1220]/25' : 'from-[#1D9BF0]/60 via-[#38BDF8]/25 to-transparent'"
            ></div>
        </div>

        <div class="dash-scope">
        <!-- 1. Header (hero) -->
        <section
            class="relative mb-6 overflow-hidden rounded-3xl p-6 text-white shadow-xl ring-1 ring-white/30 sm:p-7"
            :style="{ background: heroGradient }"
        >
            <div class="pointer-events-none absolute -right-16 -top-20 h-64 w-64 rounded-full bg-white/15 blur-3xl"></div>
            <div class="pointer-events-none absolute -bottom-24 left-1/3 h-56 w-56 rounded-full bg-white/10 blur-3xl"></div>

            <div class="relative flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-5">
                    <!-- The signed-in user's own profile photo; the school logo is only the fallback
                         for accounts that haven't uploaded one, then their initial. -->
                    <img
                        v-if="heroPhoto"
                        :src="heroPhoto"
                        alt=""
                        class="hidden h-24 w-24 flex-none rounded-full bg-white/10 object-cover ring-4 ring-white/30 sm:block"
                        @error="$event.target.style.display = 'none'"
                    />
                    <span
                        v-else
                        class="hidden h-24 w-24 flex-none items-center justify-center rounded-full bg-white/15 text-4xl font-bold ring-4 ring-white/30 sm:flex"
                    >{{ userInitial }}</span>
                    <div class="min-w-0">
                        <p v-if="schoolBranding.name" class="truncate text-sm font-medium text-white/75">{{ schoolBranding.name }}</p>
                        <h1 class="mt-1 flex items-center gap-2 text-2xl font-bold tracking-tight sm:text-3xl">
                            {{ greeting }}, {{ user.name }}!
                            <InfoPopover
                                title="Dashboard"
                                :paragraphs="[
                                    'A quick overview of scheduling status scoped to your role — summary counts, scheduling progress, and any conflicts that need attention.',
                                ]"
                                :bullets="[
                                    'The KPI cards reflect the active academic term shown at the top right.',
                                    'Conflicts listed here are visual indicators only — the server always re-validates before anything is saved.',
                                    'What you see is scoped to your role: a Dean/OIC sees only their college\'s data.',
                                ]"
                            />
                        </h1>
                        <p class="mt-1 text-sm text-white/80">
                            <span class="font-semibold text-white">{{ roleLabel }}</span>
                            <span v-if="scope.label"> — {{ scope.label }}</span>
                        </p>
                        <p class="mt-2 flex flex-wrap items-center gap-2 text-sm text-white/70">
                            <i class="pi pi-clock text-white/60"></i>
                            <LiveClock :is-dark="true" />
                        </p>
                    </div>
                </div>

                <div
                    v-if="activeAcademicTermLabel"
                    class="flex items-center gap-2 self-start rounded-full bg-black/25 px-4 py-2 ring-1 ring-white/25 backdrop-blur sm:self-auto"
                >
                    <span class="relative flex h-2.5 w-2.5">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-300 opacity-75"></span>
                        <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
                    </span>
                    <span class="text-sm font-semibold text-emerald-200">Active</span>
                    <span class="h-3 w-px bg-white/30"></span>
                    <i class="pi pi-calendar text-white/70"></i>
                    <span class="text-sm font-semibold">{{ activeAcademicTermLabel }}</span>
                </div>
            </div>
        </section>

        <!-- 2. Summary Cards -->
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <KpiCard
                label="Active Sections"
                :value="kpis.active_sections"
                icon="pi-th-large"
                color="blue"
                href="scheduling.sections"
                :is-dark="isDark"
            />
            <KpiCard
                label="Faculty Members"
                :value="kpis.faculty_members"
                icon="pi-users"
                color="emerald"
                href="scheduling.faculty"
                :is-dark="isDark"
            />
            <KpiCard
                label="Available Rooms"
                :value="kpis.rooms"
                icon="pi-building"
                color="violet"
                href="scheduling.rooms"
                :is-dark="isDark"
            />
            <KpiCard
                label="Scheduled Subjects"
                :value="kpis.scheduled_subjects"
                :value-suffix="`/ ${kpis.total_subjects}`"
                icon="pi-calendar-plus"
                color="amber"
                href="scheduling.section-subjects"
                :is-dark="isDark"
            />
        </div>

        <!-- 3. Scheduling Progress + 4. Conflict Detection -->
        <div class="mb-6 grid grid-cols-1 gap-4 xl:grid-cols-2">
            <SchedulingProgressCard :progress="progress" :scheduled="kpis.scheduled_subjects" :is-dark="isDark" />
            <ConflictPanel :conflicts="conflicts" :roles="roles" :is-dark="isDark" />
        </div>

        <!-- 5. Room Occupancy — which rooms have a class at the chosen day/time (defaults to right now) -->
        <!-- + Faculty Today sidebar: who has a class on the selected day -->
        <div class="mb-6 grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_21rem]">
            <RoomOccupancyCard :is-dark="isDark" :accent="heroBase" @snapshot="occupancy = $event" />
            <FacultyTodayCard
                :is-dark="isDark"
                :accent="heroBase"
                :day="occupancy.day"
                :minute="occupancy.minute"
                :live="occupancy.live"
                :rooms="occupancy.rooms"
                :loading="occupancy.loading"
            />
        </div>

        <!-- 9. Quick Actions -->
        <div class="mb-6">
            <QuickActions :is-dark="isDark" />
        </div>
        </div>
    </AppLayout>
</template>

<style>
/* Frosted-glass cards on the Dashboard only (scoped by .dash-scope) so the
   campus-photo backdrop shows through, like My Schedule. Dialogs/popovers are
   teleported to <body>, so they keep their normal solid look. */
.dash-scope .neu-card {
    background: rgba(255, 255, 255, 0.74);
    border-color: rgba(255, 255, 255, 0.75);
    box-shadow: 0 10px 30px -12px rgba(2, 132, 199, 0.28);
    -webkit-backdrop-filter: blur(14px);
    backdrop-filter: blur(14px);
}
.dark .dash-scope .neu-card {
    background: rgba(11, 18, 32, 0.6);
    border-color: rgba(255, 255, 255, 0.1);
    box-shadow: 0 10px 30px -12px rgba(0, 0, 0, 0.55);
}
.dash-scope .neu-card .neu-card {
    /* Quick-action tiles sit inside a glass card; keep them a touch lighter so they read as buttons. */
    background: rgba(255, 255, 255, 0.55);
    box-shadow: none;
}
.dark .dash-scope .neu-card .neu-card {
    background: rgba(255, 255, 255, 0.06);
}
</style>