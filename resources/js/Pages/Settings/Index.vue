<script setup>
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from 'primevue/card';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Textarea from 'primevue/textarea';
import Password from 'primevue/password';
import Button from 'primevue/button';
import Divider from 'primevue/divider';
import FloatLabel from 'primevue/floatlabel';
import Toast from 'primevue/toast';
import Tabs from 'primevue/tabs';
import TabList from 'primevue/tablist';
import Tab from 'primevue/tab';
import TabPanels from 'primevue/tabpanels';
import TabPanel from 'primevue/tabpanel';
import ToggleSwitch from 'primevue/toggleswitch';
import Select from 'primevue/select';
import Checkbox from 'primevue/checkbox';
import Tag from 'primevue/tag';
import Message from 'primevue/message';
import DatePicker from 'primevue/datepicker';
import InfoPopover from '@/Components/InfoPopover.vue';
import PasswordRequirementsChecklist from '@/Components/PasswordRequirementsChecklist.vue';
import AccountForm from '@/Components/Account/AccountForm.vue';
import { useTheme } from '@/composables/useTheme';
import { collegeSoftStyle } from '@/composables/useCollegeColors';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

// Colored panel chrome for every Settings tab (header band + accent
// border), so each tab reads as its own section instead of a plain
// white box. Full class strings are written out so Tailwind can see them.
const PANEL_TONES = {
    blue: {
        light: { border: 'border-blue-100 bg-blue-50/70', band: 'bg-gradient-to-r from-blue-100/80 via-cyan-50 to-transparent', icon: 'bg-[#2563EB]' },
        dark: { border: 'border-blue-400/20 bg-blue-500/10', band: 'bg-gradient-to-r from-blue-500/25 via-cyan-500/10 to-transparent', icon: 'bg-blue-500' },
    },
    violet: {
        light: { border: 'border-violet-100 bg-violet-50/70', band: 'bg-gradient-to-r from-violet-100/80 via-fuchsia-50 to-transparent', icon: 'bg-violet-600' },
        dark: { border: 'border-violet-400/20 bg-violet-500/10', band: 'bg-gradient-to-r from-violet-500/25 via-fuchsia-500/10 to-transparent', icon: 'bg-violet-500' },
    },
    emerald: {
        light: { border: 'border-emerald-100 bg-emerald-50/70', band: 'bg-gradient-to-r from-emerald-100/80 via-teal-50 to-transparent', icon: 'bg-emerald-600' },
        dark: { border: 'border-emerald-400/20 bg-emerald-500/10', band: 'bg-gradient-to-r from-emerald-500/25 via-teal-500/10 to-transparent', icon: 'bg-emerald-500' },
    },
    amber: {
        light: { border: 'border-amber-100 bg-amber-50/70', band: 'bg-gradient-to-r from-amber-100/80 via-orange-50 to-transparent', icon: 'bg-amber-500' },
        dark: { border: 'border-amber-400/20 bg-amber-500/10', band: 'bg-gradient-to-r from-amber-500/25 via-orange-500/10 to-transparent', icon: 'bg-amber-500' },
    },
    indigo: {
        light: { border: 'border-indigo-100 bg-indigo-50/70', band: 'bg-gradient-to-r from-indigo-100/80 via-slate-50 to-transparent', icon: 'bg-indigo-600' },
        dark: { border: 'border-indigo-400/20 bg-indigo-500/10', band: 'bg-gradient-to-r from-indigo-500/25 via-slate-500/10 to-transparent', icon: 'bg-indigo-500' },
    },
    rose: {
        light: { border: 'border-rose-100 bg-rose-50/70', band: 'bg-gradient-to-r from-rose-100/80 via-pink-50 to-transparent', icon: 'bg-rose-500' },
        dark: { border: 'border-rose-400/20 bg-rose-500/10', band: 'bg-gradient-to-r from-rose-500/25 via-pink-500/10 to-transparent', icon: 'bg-rose-500' },
    },
};
const panelTone = (name) => PANEL_TONES[name][isDark.value ? 'dark' : 'light'];

const props = defineProps({
    visibleGroups: { type: Array, default: () => [] },
    editableGroups: { type: Array, default: () => [] },
    settings: { type: Object, default: () => ({}) },
    schoolYear: { type: Object, default: null },
    system: { type: Object, default: null },
    passwordPolicy: { type: Object, default: null },
    activeSessions: { type: Array, default: () => [] },
    activityLog: { type: Object, default: () => ({}) },
    colleges: { type: Array, default: () => [] },
    collegeColorPalette: { type: Array, default: () => [] },
});

const toast = useToast();
const page = usePage();

watch(
    () => page.props.flash?.success,
    (message) => { if (message) toast.add({ severity: 'success', summary: 'Success', detail: message, life: 4000 }); },
);
watch(
    () => page.props.flash?.error,
    (message) => { if (message) toast.add({ severity: 'error', summary: 'Error', detail: message, life: 4000 }); },
);

const authRoles = computed(() => page.props.auth?.roles ?? []);
const isAdministrator = computed(() => authRoles.value.includes('Administrator'));

// Lets a notification (e.g. "Password Change Required") deep-link
// straight into the Manage Account tab via ?tab=account instead of
// always landing on whatever the first visible group is.
const requestedTab = new URLSearchParams(window.location.search).get('tab');
const activeTab = ref(requestedTab ?? (props.visibleGroups[0] ?? 'general'));

const canEdit = (group) => props.editableGroups.includes(group);
const has = (group) => props.visibleGroups.includes(group);

// "19:00" -> "7:00 PM" — the Academic tab's Class Hours summary reads
// straight off the Active School Year record (raw 24-hour "H:i"), so it
// needs its own display formatter rather than showing that value as-is.
const formatTime12 = (time) => {
    if (!time) return '—';
    const [hour, minute] = time.split(':').map(Number);
    const period = hour >= 12 ? 'PM' : 'AM';
    const hour12 = hour % 12 === 0 ? 12 : hour % 12;
    return `${hour12}:${String(minute).padStart(2, '0')} ${period}`;
};

const onError = () => {
    toast.add({ severity: 'warn', summary: 'Missing information', detail: 'Please check the highlighted fields and try again.', life: 3000 });
};

/* ------------------------------------------------------------------ */
/* General                                                             */
/* ------------------------------------------------------------------ */
const generalForm = useForm({
    school_name: props.settings['general.school_name'] ?? '',
    school_short_name: props.settings['general.school_short_name'] ?? '',
    school_address: props.settings['general.school_address'] ?? '',
    school_contact: props.settings['general.school_contact'] ?? '',
    school_email: props.settings['general.school_email'] ?? '',
    logo: null,
});

// School Logo — shows the currently-saved logo (school_logo_path,
// same URL the print reports' letterhead falls back to) until the
// Registrar picks a new file, at which point this swaps to a local
// object-URL preview of the picked file so they see exactly what
// they're about to save without waiting on a round trip.
const logoPreviewUrl = ref(props.settings['general.school_logo_path'] || null);
const logoInput = ref(null);
const triggerLogoPicker = () => logoInput.value?.click();
const onLogoSelected = (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    generalForm.logo = file;
    if (logoPreviewUrl.value && logoPreviewUrl.value.startsWith('blob:')) {
        URL.revokeObjectURL(logoPreviewUrl.value);
    }
    logoPreviewUrl.value = URL.createObjectURL(file);
};
const removeLogoSelection = () => {
    generalForm.logo = null;
    if (logoPreviewUrl.value && logoPreviewUrl.value.startsWith('blob:')) {
        URL.revokeObjectURL(logoPreviewUrl.value);
    }
    logoPreviewUrl.value = props.settings['general.school_logo_path'] || null;
    if (logoInput.value) logoInput.value.value = '';
};

const saveGeneral = () => {
    generalForm.transform((data) => ({ ...data, _method: 'put' })).post(route('settings.general.update'), {
        preserveScroll: true,
        forceFormData: true,
        onError,
    });
};

/* ------------------------------------------------------------------ */
/* Faculty & workload                                                  */
/* ------------------------------------------------------------------ */
const workloadForm = useForm({
    max_teaching_load: props.settings['workload.max_teaching_load'] ?? 24,
    warning_threshold: props.settings['workload.warning_threshold'] ?? 85,
    overloaded_threshold: props.settings['workload.overloaded_threshold'] ?? 100,
    allow_admin_override: props.settings['workload.allow_admin_override'] ?? true,
    max_daily_teaching_hours: props.settings['workload.max_daily_teaching_hours'] ?? 8,
});
const saveWorkload = () => {
    workloadForm.transform((data) => ({ ...data, _method: 'put' })).post(route('settings.workload.update'), { preserveScroll: true, onError });
};

/* ------------------------------------------------------------------ */
/* College Colors                                                      */
/* ------------------------------------------------------------------ */
// One color per College. Room Grid / Section Grid color their blocks by the
// College that owns the Section, so a newly added College just needs a color
// picked here (it is also auto-assigned the next free palette color on create).
const collegeColorsForm = useForm({
    colors: props.colleges.map((college) => ({ id: college.id, color: college.color })),
});
const collegeColorFor = (id) => collegeColorsForm.colors.find((entry) => entry.id === id);
const setCollegeColor = (id, hex) => {
    const entry = collegeColorFor(id);
    if (entry) entry.color = String(hex).toUpperCase();
};
// Free-typed hex: only accept a complete #RRGGBB so a half-typed value never
// reaches the preview/save.
const onHexInput = (id, event) => {
    let value = event.target.value.trim();
    if (value && !value.startsWith('#')) value = `#${value}`;
    if (/^#[0-9a-fA-F]{6}$/.test(value)) setCollegeColor(id, value);
};
const collegeColorsDirty = computed(() => props.colleges.some((college) => collegeColorFor(college.id)?.color !== college.color));
// Two colleges sharing a color is allowed, but flagged so it's a conscious choice.
const duplicateColorIds = computed(() => {
    const seen = {};
    collegeColorsForm.colors.forEach((entry) => { (seen[entry.color.toUpperCase()] ??= []).push(entry.id); });
    return new Set(Object.values(seen).filter((ids) => ids.length > 1).flat());
});
const saveCollegeColors = () => {
    collegeColorsForm.transform((data) => ({ ...data, _method: 'put' })).post(route('settings.college-colors.update'), {
        preserveScroll: true,
        onError,
        onSuccess: () => collegeColorsForm.defaults({ colors: collegeColorsForm.colors.map((entry) => ({ ...entry })) }),
    });
};


/* ------------------------------------------------------------------ */
/* System / Maintenance                                                */
/* ------------------------------------------------------------------ */
const refreshingCache = ref(false);
const refreshCache = () => {
    refreshingCache.value = true;
    router.post(route('settings.system.refresh-cache'), {}, {
        preserveScroll: true,
        onFinish: () => { refreshingCache.value = false; },
    });
};

/* ------------------------------------------------------------------ */
/* Active Sessions                                                     */
/* ------------------------------------------------------------------ */
function refreshSessions() {
    router.reload({ only: ['activeSessions'] });
}

function forceLogout(session) {
    if (! confirm('Log this device out now? The user will be signed out immediately.')) {
        return;
    }

    router.delete(route('settings.active-sessions.destroy', session.id), {
        preserveScroll: true,
        onSuccess: () => refreshSessions(),
    });
}

/* ------------------------------------------------------------------ */
/* Activity Log                                                        */
/* ------------------------------------------------------------------ */
const activityLogFilters = ref({
    log_action: props.activityLog?.filters?.action ?? null,
    log_user_id: props.activityLog?.filters?.user_id ?? null,
    log_date_from: props.activityLog?.filters?.date_from ?? null,
    log_date_to: props.activityLog?.filters?.date_to ?? null,
});

function reloadActivityLog(extra = {}) {
    router.reload({
        only: ['activityLog'],
        data: { ...activityLogFilters.value, ...extra },
        preserveScroll: true,
        preserveState: true,
    });
}

function applyActivityLogFilters() {
    reloadActivityLog({ log_page: 1 });
}

function clearActivityLogFilters() {
    activityLogFilters.value = {
        log_action: null,
        log_user_id: null,
        log_date_from: null,
        log_date_to: null,
    };
    reloadActivityLog({ log_page: 1 });
}

function goToActivityLogPage(page) {
    reloadActivityLog({ log_page: page });
}

/* ------------------------------------------------------------------ */
/* Manage Account (all roles, incl. Administrator)                     */
/* ------------------------------------------------------------------ */
const accountForm = useForm({
    first_name: page.props.auth?.user?.first_name ?? '',
    middle_name: page.props.auth?.user?.middle_name ?? '',
    last_name: page.props.auth?.user?.last_name ?? '',
    suffix: page.props.auth?.user?.suffix ?? '',
    email: page.props.auth?.user?.email ?? '',
    photo: null,
    remove_photo: false,
    password: '',
    password_confirmation: '',
});
// Local preview URL for a newly-picked (not yet saved) photo file —
// separate from the saved auth.user.profile_photo_url so the avatar
// updates instantly on selection without waiting on a round trip.
const photoPreviewUrl = ref(null);
const photoInput = ref(null);
const onPhotoSelected = (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    accountForm.photo = file;
    accountForm.remove_photo = false;
    photoPreviewUrl.value = URL.createObjectURL(file);
};
const onRemovePhoto = () => {
    accountForm.photo = null;
    accountForm.remove_photo = true;
    photoPreviewUrl.value = null;
    if (photoInput.value) photoInput.value.value = '';
};
const accountAvatarUrl = computed(() => (accountForm.remove_photo ? null : (photoPreviewUrl.value ?? page.props.auth?.user?.profile_photo_url ?? null)));
const accountInitials = computed(() => `${accountForm.first_name?.charAt(0) ?? ''}${accountForm.last_name?.charAt(0) ?? ''}`.toUpperCase());
const onUpdateAccount = () => {
    accountForm.transform((data) => ({ ...data, _method: 'put' })).post(route('account.update'), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            accountForm.password = '';
            accountForm.password_confirmation = '';
            accountForm.photo = null;
            accountForm.remove_photo = false;
            photoPreviewUrl.value = null;
        },
        onError,
    });
};
</script>

<template>
    <Head title="Settings" />

    <AppLayout>
        <Toast />

        <div class="max-w-6xl mx-auto w-full neu-form" :class="isDark ? 'dark-scope' : ''">
            <!-- Page Title -->
            <PageHeader title="Settings" subtitle="System-wide configuration. Faculty, Rooms, Subjects, Sections, Programs, Colleges and the Curriculum keep their own dedicated pages — Settings only controls how the system behaves." icon="pi pi-cog" tone="teal">
                <template #info>
                    <InfoPopover
                        title="Settings"
                        :paragraphs="[
                            'System-wide configuration that controls how scheduling behaves — not the data itself (Faculty, Rooms, Subjects, Sections, and Curriculum each have their own pages).',
                        ]"
                        :bullets="[
                            'Academic — a read-only summary of the daily class window and available scheduling days; managed on the Term Setup page.',
                            'Changing these settings affects future scheduling; it does not retroactively change schedules already saved.',
                        ]"
                    />
                </template>
            </PageHeader>

            <Tabs v-model:value="activeTab">
                <TabList>
                    <Tab v-if="has('general')" value="general">General</Tab>
                    <Tab v-if="has('academic')" value="academic">Academic</Tab>
                    <Tab v-if="has('workload')" value="workload">Faculty &amp; Workload</Tab>
                    <Tab v-if="has('collegeColors')" value="collegeColors">College Colors</Tab>
                    <Tab v-if="has('system') && isAdministrator" value="activeSessions">Active Sessions</Tab>
                    <Tab v-if="has('activityLog')" value="activityLog">Activity Log</Tab>
                    <Tab v-if="has('system')" value="system">System</Tab>
                    <Tab value="account">Manage Account</Tab>
                </TabList>

                <TabPanels>
                    <!-- ============================== GENERAL ============================== -->
                    <TabPanel v-if="has('general')" value="general">
                        <div class="overflow-hidden rounded-2xl border transition-colors duration-300" :class="panelTone('blue').border">
                            <div class="flex items-start justify-between gap-4 px-6 py-5" :class="panelTone('blue').band">
                                <div class="flex items-start gap-3">
                                    <span class="flex h-11 w-11 flex-none items-center justify-center rounded-xl text-lg text-white shadow-sm" :class="panelTone('blue').icon">
                                        <i class="pi pi-building"></i>
                                    </span>
                                    <div>
                                        <h2 class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">General</h2>
                                        <p class="mt-0.5 max-w-3xl text-sm" :class="isDark ? 'text-slate-300' : 'text-slate-600'">School identity shown across the system.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="px-6 pb-6 pt-5">
                                <!-- School Logo — used across the system's letterhead, and as
                                     the fallback logo image on every printed report (see
                                     resources/views/reports/partials/letterhead.blade.php). -->
                                <div class="flex items-center gap-4 mb-7">
                                    <div class="w-20 h-20 rounded-xl border border-slate-200 bg-slate-50 flex items-center justify-center overflow-hidden shrink-0">
                                        <img v-if="logoPreviewUrl" :src="logoPreviewUrl" alt="School Logo" class="w-full h-full object-contain" />
                                        <i v-else class="pi pi-image text-2xl text-slate-300"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-slate-700 mb-1">School Logo</p>
                                        <p class="text-xs text-slate-500 mb-2">Shown on the letterhead and printed reports. PNG or JPG, up to 2MB.</p>
                                        <div class="flex items-center gap-2" v-if="canEdit('general')">
                                            <Button label="Upload Logo" icon="pi pi-upload" size="small" outlined @click="triggerLogoPicker" />
                                            <Button v-if="generalForm.logo" label="Cancel" icon="pi pi-times" size="small" text severity="secondary" @click="removeLogoSelection" />
                                            <input ref="logoInput" type="file" accept="image/*" class="hidden" @change="onLogoSelected" />
                                        </div>
                                        <p v-if="generalForm.errors.logo" class="text-xs text-red-500 mt-1">{{ generalForm.errors.logo }}</p>
                                    </div>
                                </div>

                                <fieldset :disabled="!canEdit('general')" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-7">
                                    <FloatLabel variant="on">
                                        <InputText v-uppercase id="schoolName" size="large" v-model="generalForm.school_name" class="w-full" :invalid="!!generalForm.errors.school_name" />
                                        <label for="schoolName">School Name *</label>
                                    </FloatLabel>
                                    <FloatLabel variant="on">
                                        <InputText v-uppercase id="schoolShortName" size="large" v-model="generalForm.school_short_name" class="w-full" />
                                        <label for="schoolShortName">Short Name</label>
                                    </FloatLabel>
                                    <FloatLabel variant="on">
                                        <InputText v-uppercase id="schoolContact" size="large" v-model="generalForm.school_contact" class="w-full" />
                                        <label for="schoolContact">Contact Number</label>
                                    </FloatLabel>

                                    <FloatLabel variant="on">
                                        <InputText id="schoolEmail" size="large" v-model="generalForm.school_email" type="email" class="w-full" :invalid="!!generalForm.errors.school_email" />
                                        <label for="schoolEmail">School Email</label>
                                    </FloatLabel>

                                    <FloatLabel variant="on" class="sm:col-span-2 lg:col-span-3">
                                        <Textarea id="schoolAddress" v-model="generalForm.school_address" class="w-full" rows="1" autoResize />
                                        <label for="schoolAddress">Address</label>
                                    </FloatLabel>
                                </fieldset>

                                <div v-if="canEdit('general')" class="flex justify-end mt-6">
                                    <Button label="Save Changes" icon="pi pi-check" :loading="generalForm.processing" @click="saveGeneral" />
                                </div>
                            </div>
                        </div>
                    </TabPanel>

                    <!-- ============================== ACADEMIC ============================== -->
                    <TabPanel v-if="has('academic')" value="academic">
                        <div
                            v-if="schoolYear"
                            class="overflow-hidden rounded-2xl border transition-colors duration-300"
                            :class="isDark ? 'border-blue-400/20 bg-blue-500/10' : 'border-blue-100 bg-blue-50/70'"
                        >
                            <!-- Colored header band -->
                            <div
                                class="flex flex-wrap items-center justify-between gap-3 px-6 py-5"
                                :class="isDark ? 'bg-gradient-to-r from-blue-500/25 via-cyan-500/10 to-transparent' : 'bg-gradient-to-r from-blue-100/80 via-cyan-50 to-transparent'"
                            >
                                <div class="flex items-center gap-3">
                                    <span
                                        class="flex h-11 w-11 flex-none items-center justify-center rounded-xl text-lg text-white shadow-sm"
                                        :class="isDark ? 'bg-blue-500' : 'bg-[#2563EB]'"
                                    >
                                        <i class="pi pi-calendar-clock"></i>
                                    </span>
                                    <h2 class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Scheduling Window &amp; Calendar</h2>
                                </div>
                                <Tag severity="info" value="Managed in Term Setup" />
                            </div>

                            <div class="px-6 pb-6 pt-4">
                                <p class="mb-5 text-sm" :class="isDark ? 'text-slate-300' : 'text-slate-500'">
                                    Class Start/End Time, Time Interval, and Working Days already live on the
                                    Active School Year so the Auto Schedule engine has one source of truth. Change them from the
                                    Term Setup page — Settings only shows the current values here.
                                </p>

                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                    <div class="flex items-center gap-3 rounded-xl px-4 py-3" :class="isDark ? 'bg-blue-500/10' : 'bg-blue-50'">
                                        <span class="flex h-10 w-10 flex-none items-center justify-center rounded-lg" :class="isDark ? 'bg-blue-500/20 text-blue-300' : 'bg-blue-100 text-blue-600'">
                                            <i class="pi pi-calendar"></i>
                                        </span>
                                        <div class="min-w-0">
                                            <p class="text-xs font-medium" :class="isDark ? 'text-blue-300' : 'text-blue-600'">Active School Year</p>
                                            <p class="truncate text-base font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">{{ schoolYear.name }}</p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-3 rounded-xl px-4 py-3" :class="isDark ? 'bg-violet-500/10' : 'bg-violet-50'">
                                        <span class="flex h-10 w-10 flex-none items-center justify-center rounded-lg" :class="isDark ? 'bg-violet-500/20 text-violet-300' : 'bg-violet-100 text-violet-600'">
                                            <i class="pi pi-clock"></i>
                                        </span>
                                        <div class="min-w-0">
                                            <p class="text-xs font-medium" :class="isDark ? 'text-violet-300' : 'text-violet-600'">Class Hours</p>
                                            <p class="text-base font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">{{ formatTime12(schoolYear.class_start_time) }} – {{ formatTime12(schoolYear.class_end_time) }}</p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-3 rounded-xl px-4 py-3" :class="isDark ? 'bg-amber-500/10' : 'bg-amber-50'">
                                        <span class="flex h-10 w-10 flex-none items-center justify-center rounded-lg" :class="isDark ? 'bg-amber-500/20 text-amber-300' : 'bg-amber-100 text-amber-600'">
                                            <i class="pi pi-stopwatch"></i>
                                        </span>
                                        <div class="min-w-0">
                                            <p class="text-xs font-medium" :class="isDark ? 'text-amber-300' : 'text-amber-700'">Time Interval</p>
                                            <p class="text-base font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">{{ schoolYear.time_interval }} minutes</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-3 rounded-xl px-4 py-3" :class="isDark ? 'bg-emerald-500/10' : 'bg-emerald-50'">
                                    <p class="mb-2 flex items-center gap-2 text-xs font-medium" :class="isDark ? 'text-emerald-300' : 'text-emerald-700'">
                                        <i class="pi pi-th-large"></i>
                                        Working Days
                                    </p>
                                    <div class="flex flex-wrap gap-2">
                                        <span
                                            v-for="day in schoolYear.available_days"
                                            :key="day"
                                            class="rounded-full px-3 py-1 text-sm font-semibold"
                                            :class="isDark ? 'bg-emerald-500/20 text-emerald-200' : 'bg-white text-emerald-700 shadow-sm'"
                                        >{{ day }}</span>
                                    </div>
                                </div>

                                <Button as="a" :href="route('academic-calendar')" label="Open Term Setup" icon="pi pi-external-link" size="small" class="!mt-5" />
                            </div>
                        </div>
                    </TabPanel>

                    <!-- ============================== FACULTY & WORKLOAD ============================== -->
                    <TabPanel v-if="has('workload')" value="workload">
                        <div class="overflow-hidden rounded-2xl border transition-colors duration-300" :class="panelTone('violet').border">
                            <div class="flex items-start justify-between gap-4 px-6 py-5" :class="panelTone('violet').band">
                                <div class="flex items-start gap-3">
                                    <span class="flex h-11 w-11 flex-none items-center justify-center rounded-xl text-lg text-white shadow-sm" :class="panelTone('violet').icon">
                                        <i class="pi pi-sliders-h"></i>
                                    </span>
                                    <div>
                                        <h2 class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Faculty &amp; Workload</h2>
                                        <p class="mt-0.5 max-w-3xl text-sm" :class="isDark ? 'text-slate-300' : 'text-slate-600'">Auto Schedule prefers faculty with lower current workload and never auto-assigns past the maximum load. Individual faculty-specific limits (if set on a Faculty record) still take precedence over this system default. Max Daily Teaching Hours is a separate, per-day ceiling — a faculty member can be well under their weekly Max Teaching Load and still get crammed into too many hours on one single day.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="px-6 pb-6 pt-5">
                                <fieldset :disabled="!canEdit('workload')" class="grid grid-cols-1 sm:grid-cols-4 gap-5">
                                    <FloatLabel variant="on">
                                        <!-- Capped at 50 to match FacultyLoadRequest::HARD_CAP_UNITS
                                             (the institution-wide hard ceiling enforced server-side on
                                             every overload request) — never raise this without also
                                             raising HARD_CAP_UNITS, or the form will accept a value the
                                             backend will just reject/clamp anyway. -->
                                        <InputNumber id="maxLoad" size="large" v-model="workloadForm.max_teaching_load" class="w-full" :min="1" :max="50" />
                                        <label for="maxLoad">Max Teaching Load (units/hrs)</label>
                                    </FloatLabel>
                                    <FloatLabel variant="on">
                                        <InputNumber id="maxDailyHours" size="large" v-model="workloadForm.max_daily_teaching_hours" class="w-full" :min="0" :max="16" suffix=" hrs" />
                                        <label for="maxDailyHours">Max Daily Teaching Hours</label>
                                    </FloatLabel>
                                    <FloatLabel variant="on">
                                        <InputNumber id="warnThreshold" size="large" v-model="workloadForm.warning_threshold" class="w-full" :min="0" :max="100" suffix="%" />
                                        <label for="warnThreshold">Warning Threshold</label>
                                    </FloatLabel>
                                    <FloatLabel variant="on">
                                        <InputNumber id="overloadThreshold" size="large" v-model="workloadForm.overloaded_threshold" class="w-full" :min="0" :max="200" suffix="%" />
                                        <label for="overloadThreshold">Overloaded Threshold</label>
                                    </FloatLabel>
                                </fieldset>
                                <small v-if="workloadForm.errors.max_daily_teaching_hours" class="text-red-500 block mt-2">{{ workloadForm.errors.max_daily_teaching_hours }}</small>
                                <small v-if="workloadForm.errors.warning_threshold" class="text-red-500 block mt-2">{{ workloadForm.errors.warning_threshold }}</small>

                                <div class="flex items-center gap-3 mt-5">
                                    <ToggleSwitch v-model="workloadForm.allow_admin_override" :disabled="!canEdit('workload')" />
                                    <span>Allow Administrator, Registrar, Dean, OIC and Assistant Dean to go above the maximum load (up to 50 units)</span>
                                    <Tag severity="danger" value="HARD CONSTRAINT unless overridden" class="!text-[10px]" />
                                </div>

                                <div v-if="canEdit('workload')" class="flex justify-end mt-6">
                                    <Button label="Save Changes" icon="pi pi-check" :loading="workloadForm.processing" @click="saveWorkload" />
                                </div>
                            </div>
                        </div>
                    </TabPanel>

                    <!-- COLLEGE COLORS — one color per College; Room Grid and Section
                         Grid blocks use the color of the College that owns the Section. -->
                    <TabPanel v-if="has('collegeColors')" value="collegeColors">
                        <div class="overflow-hidden rounded-2xl border transition-colors duration-300" :class="panelTone('amber').border">
                            <div class="flex items-start justify-between gap-4 px-6 py-5" :class="panelTone('amber').band">
                                <div class="flex items-start gap-3">
                                    <span class="flex h-11 w-11 flex-none items-center justify-center rounded-xl text-lg text-white shadow-sm" :class="panelTone('amber').icon">
                                        <i class="pi pi-palette"></i>
                                    </span>
                                    <div>
                                        <h2 class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">College Colors</h2>
                                        <p class="mt-0.5 max-w-3xl text-sm" :class="isDark ? 'text-slate-300' : 'text-slate-600'">Pick a color for each College. Subjects on the Room Grid and Section Grid are colored by the College that owns the Section, so a BSIT subject shows the CCS color. A new College is given a free color automatically and can be changed here at any time.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="px-6 pb-6 pt-5">
                                <div v-if="!colleges.length" class="text-sm" :class="isDark ? 'text-slate-300' : 'text-slate-500'">No colleges yet. Add a College first, then set its color here.</div>

                                <div v-else class="space-y-4">
                                    <div
                                        v-for="college in colleges"
                                        :key="college.id"
                                        class="flex flex-col gap-4 rounded-xl border p-4 lg:flex-row lg:items-center"
                                        :class="isDark ? 'border-white/10 bg-white/5' : 'border-slate-200 bg-white'"
                                    >
                                        <!-- Which college -->
                                        <div class="lg:w-64 shrink-0">
                                            <p class="font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">{{ college.code }}</p>
                                            <p class="text-xs" :class="isDark ? 'text-slate-300' : 'text-slate-500'">{{ college.name }}</p>
                                        </div>

                                        <!-- Color picker + hex + presets -->
                                        <div class="flex flex-1 flex-wrap items-center gap-3">
                                            <input
                                                type="color"
                                                class="h-10 w-12 cursor-pointer rounded-lg border border-slate-300 bg-transparent p-0.5 disabled:cursor-not-allowed"
                                                :value="collegeColorFor(college.id)?.color"
                                                :disabled="!canEdit('collegeColors')"
                                                :aria-label="`Color for ${college.code}`"
                                                @input="setCollegeColor(college.id, $event.target.value)"
                                            />
                                            <InputText
                                                class="!w-28 !font-mono !uppercase"
                                                maxlength="7"
                                                :model-value="collegeColorFor(college.id)?.color"
                                                :disabled="!canEdit('collegeColors')"
                                                @input="onHexInput(college.id, $event)"
                                            />
                                            <div v-if="canEdit('collegeColors')" class="flex flex-wrap items-center gap-1.5">
                                                <button
                                                    v-for="swatch in collegeColorPalette"
                                                    :key="swatch"
                                                    type="button"
                                                    class="h-6 w-6 rounded-full border-2 transition-transform hover:scale-110"
                                                    :class="collegeColorFor(college.id)?.color?.toUpperCase() === swatch ? 'border-slate-800 dark:border-white' : 'border-white/70'"
                                                    :style="{ backgroundColor: swatch }"
                                                    :title="swatch"
                                                    @click="setCollegeColor(college.id, swatch)"
                                                ></button>
                                            </div>
                                        </div>

                                        <!-- Live preview of how a block will look on the grid -->
                                        <div class="lg:w-56 shrink-0">
                                            <div
                                                class="rounded-md border px-2 py-1 text-[13px] leading-tight"
                                                :style="collegeSoftStyle(collegeColorFor(college.id)?.color, isDark)"
                                            >
                                                <div class="truncate text-sm font-semibold">Sample Subject</div>
                                                <div class="truncate text-xs opacity-90">{{ college.code }} · 8:00 AM–11:00 AM</div>
                                            </div>
                                            <small v-if="duplicateColorIds.has(college.id)" class="mt-1 block text-[11px] text-amber-600">Same color as another college.</small>
                                        </div>
                                    </div>
                                </div>

                                <small v-if="collegeColorsForm.errors.colors" class="text-red-500 block mt-2">{{ collegeColorsForm.errors.colors }}</small>
                                <small v-for="(message, key) in collegeColorsForm.errors" :key="key" v-show="key !== 'colors'" class="text-red-500 block mt-2">{{ message }}</small>

                                <div v-if="canEdit('collegeColors') && colleges.length" class="flex justify-end mt-6">
                                    <Button label="Save Changes" icon="pi pi-check" :disabled="!collegeColorsDirty" :loading="collegeColorsForm.processing" @click="saveCollegeColors" />
                                </div>
                            </div>
                        </div>
                    </TabPanel>

                    <!-- ============================== ACTIVE SESSIONS ============================== -->
                    <TabPanel v-if="has('system') && isAdministrator" value="activeSessions">
                        <div class="overflow-hidden rounded-2xl border transition-colors duration-300" :class="panelTone('emerald').border">
                            <div class="flex items-start justify-between gap-4 px-6 py-5" :class="panelTone('emerald').band">
                                <div class="flex items-start gap-3">
                                    <span class="flex h-11 w-11 flex-none items-center justify-center rounded-xl text-lg text-white shadow-sm" :class="panelTone('emerald').icon">
                                        <i class="pi pi-desktop"></i>
                                    </span>
                                    <div>
                                        <h2 class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Active Sessions</h2>
                                        <p class="mt-0.5 max-w-3xl text-sm" :class="isDark ? 'text-slate-300' : 'text-slate-600'">Everyone currently signed in to Classly. A user can appear more than once if they're signed in on more than one device. Visible to Administrators only.</p>
                                    </div>
                                </div>
                                <Button icon="pi pi-refresh" label="Refresh" text @click="refreshSessions" />
                            </div>
                            <div class="px-6 pb-6 pt-5">
                                <div v-if="!activeSessions || activeSessions.length === 0" class="neu-inset rounded-2xl p-8 text-center text-slate-500">
                                    No one else is currently signed in.
                                </div>

                                <div v-else class="space-y-4">
                                    <div v-for="entry in activeSessions" :key="entry.user_id" class="neu-inset rounded-2xl p-5">
                                        <div class="flex items-center justify-between mb-3">
                                            <div>
                                                <span class="font-semibold text-[#1E293B]">{{ entry.name }}</span>
                                                <Tag v-if="entry.role" :value="entry.role" severity="secondary" class="!text-[10px] ml-2 align-middle" />
                                            </div>
                                            <span class="text-xs text-slate-400">
                                                {{ entry.sessions.length }} device{{ entry.sessions.length === 1 ? '' : 's' }}
                                            </span>
                                        </div>

                                        <ul class="space-y-2">
                                            <li
                                                v-for="session in entry.sessions"
                                                :key="session.id"
                                                class="neu-card flex items-center justify-between rounded-xl px-3 py-2"
                                            >
                                                <div class="flex flex-col">
                                                    <span class="text-sm text-[#1E293B]">
                                                        {{ session.device }}
                                                        <Tag v-if="session.is_current" value="This device" severity="success" class="!text-[10px] ml-1 align-middle" />
                                                    </span>
                                                    <span class="text-xs text-slate-400">
                                                        {{ session.ip_address ?? 'Unknown IP' }} · Last active {{ session.last_active }}
                                                    </span>
                                                </div>
                                                <Button
                                                    v-if="!session.is_current"
                                                    label="Log out"
                                                    icon="pi pi-sign-out"
                                                    size="small"
                                                    severity="danger"
                                                    text
                                                    @click="forceLogout(session)"
                                                />
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </TabPanel>

                    <!-- ============================== ACTIVITY LOG ============================== -->
                    <TabPanel v-if="has('activityLog')" value="activityLog">
                        <div class="overflow-hidden rounded-2xl border transition-colors duration-300" :class="panelTone('amber').border">
                            <div class="flex items-start justify-between gap-4 px-6 py-5" :class="panelTone('amber').band">
                                <div class="flex items-start gap-3">
                                    <span class="flex h-11 w-11 flex-none items-center justify-center rounded-xl text-lg text-white shadow-sm" :class="panelTone('amber').icon">
                                        <i class="pi pi-history"></i>
                                    </span>
                                    <div>
                                        <h2 class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Activity Log</h2>
                                        <p class="mt-0.5 max-w-3xl text-sm" :class="isDark ? 'text-slate-300' : 'text-slate-600'">A record of important actions across Classly — who did what, and when. Administrators and Registrars see every College; Dean, OIC, and Assistant Dean see entries for their own College plus any institution-wide/shared item (e.g. a GenEd subject or an all-college room).</p>
                                    </div>
                                </div>
                                <Button icon="pi pi-refresh" label="Refresh" text @click="reloadActivityLog()" />
                            </div>
                            <div class="px-6 pb-6 pt-5">
                                <!-- Filters -->
                                <div class="neu-inset rounded-2xl p-4 mb-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
                                    <div class="flex flex-col gap-1">
                                        <label class="text-xs text-slate-500">Action</label>
                                        <Select
                                            v-model="activityLogFilters.log_action"
                                            :options="activityLog.action_options ?? []"
                                            placeholder="All actions"
                                            showClear
                                            class="w-full"
                                        />
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <label class="text-xs text-slate-500">User</label>
                                        <Select
                                            v-model="activityLogFilters.log_user_id"
                                            :options="activityLog.user_options ?? []"
                                            optionLabel="name"
                                            optionValue="id"
                                            placeholder="All users"
                                            showClear
                                            class="w-full"
                                        />
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <label class="text-xs text-slate-500">From</label>
                                        <DatePicker v-model="activityLogFilters.log_date_from" dateFormat="yy-mm-dd" showIcon class="w-full" />
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <label class="text-xs text-slate-500">To</label>
                                        <DatePicker v-model="activityLogFilters.log_date_to" dateFormat="yy-mm-dd" showIcon class="w-full" />
                                    </div>
                                    <div class="flex gap-2">
                                        <Button label="Apply" size="small" @click="applyActivityLogFilters" />
                                        <Button label="Clear" size="small" text @click="clearActivityLogFilters" />
                                    </div>
                                </div>

                                <div v-if="!activityLog.data || activityLog.data.length === 0" class="neu-inset rounded-2xl p-8 text-center text-slate-500">
                                    No activity recorded yet for these filters.
                                </div>

                                <div v-else class="space-y-2">
                                    <div
                                        v-for="entry in activityLog.data"
                                        :key="entry.id"
                                        class="neu-inset rounded-2xl px-4 py-3 flex items-start justify-between gap-4"
                                    >
                                        <div class="flex flex-col gap-1">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <span class="font-semibold text-[#1E293B] text-sm">{{ entry.actor }}</span>
                                                <Tag v-if="entry.role" :value="entry.role" severity="secondary" class="!text-[10px]" />
                                                <Tag :value="entry.action" severity="info" class="!text-[10px]" />
                                            </div>
                                            <span class="text-sm text-slate-600">{{ entry.description }}</span>
                                        </div>
                                        <span class="text-xs text-slate-400 whitespace-nowrap">
                                            {{ new Date(entry.created_at).toLocaleString() }}
                                        </span>
                                    </div>

                                    <!-- Pagination -->
                                    <div v-if="activityLog.last_page > 1" class="flex items-center justify-between pt-3">
                                        <span class="text-xs text-slate-400">
                                            Page {{ activityLog.current_page }} of {{ activityLog.last_page }} · {{ activityLog.total }} total
                                        </span>
                                        <div class="flex gap-2">
                                            <Button
                                                icon="pi pi-chevron-left"
                                                text
                                                size="small"
                                                :disabled="activityLog.current_page <= 1"
                                                @click="goToActivityLogPage(activityLog.current_page - 1)"
                                            />
                                            <Button
                                                icon="pi pi-chevron-right"
                                                text
                                                size="small"
                                                :disabled="activityLog.current_page >= activityLog.last_page"
                                                @click="goToActivityLogPage(activityLog.current_page + 1)"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </TabPanel>

                    <!-- ============================== SYSTEM ============================== -->
                    <TabPanel v-if="has('system') && system" value="system">
                        <div class="overflow-hidden rounded-2xl border transition-colors duration-300" :class="panelTone('indigo').border">
                            <div class="flex items-start justify-between gap-4 px-6 py-5" :class="panelTone('indigo').band">
                                <div class="flex items-start gap-3">
                                    <span class="flex h-11 w-11 flex-none items-center justify-center rounded-xl text-lg text-white shadow-sm" :class="panelTone('indigo').icon">
                                        <i class="pi pi-server"></i>
                                    </span>
                                    <div>
                                        <h2 class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">System / Maintenance</h2>
                                        <p class="mt-0.5 max-w-3xl text-sm" :class="isDark ? 'text-slate-300' : 'text-slate-600'">Visible to Administrators and Registrars only.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="px-6 pb-6 pt-5">
                                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm mb-6">
                                    <div><span class="text-slate-400 block">Application Version</span>{{ system.app_version }}</div>
                                    <div><span class="text-slate-400 block">Laravel Version</span>{{ system.laravel_version }}</div>
                                    <div>
                                        <span class="text-slate-400 block">Database Status</span>
                                        <Tag :severity="system.database_status === 'Connected' ? 'success' : 'danger'" :value="system.database_status" />
                                    </div>
                                    <div><span class="text-slate-400 block">Last Configuration Update</span>{{ system.last_configuration_update ?? '—' }}</div>
                                </div>

                                <Button v-if="isAdministrator" label="Refresh Configuration Cache" icon="pi pi-refresh" severity="secondary" outlined :loading="refreshingCache" @click="refreshCache" />
                                <p v-else class="text-xs text-slate-400">Only Administrators can refresh the configuration cache.</p>
                            </div>
                        </div>
                    </TabPanel>

                    <TabPanel value="account">
                        <AccountForm
                            :form="accountForm"
                            :avatar-url="accountAvatarUrl"
                            :initials="accountInitials"
                            :password-policy="passwordPolicy"
                            :is-dark="isDark"
                            @photo-selected="onPhotoSelected"
                            @remove-photo="onRemovePhoto"
                            @submit="onUpdateAccount"
                        />
                    </TabPanel>
                </TabPanels>
            </Tabs>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Dark-mode overrides. Wrapping an element in the "dark-scope" class
   (applied on the root container when isDark is true) lets these rules
   restyle the light-themed Tailwind/PrimeVue defaults used throughout
   this page without touching the light-mode markup. */
/* Token overrides: PrimeVue Aura reads these CSS variables for every
   component's colors. Setting them here means anything nested under
   .dark-scope repaints correctly even if a class-level rule below
   doesn't happen to match that component's internal markup. */
.dark-scope {
    --p-card-background: #141B33;
    --p-card-color: #F8FAFC;
    --p-content-background: #141B33;
    --p-content-color: #F8FAFC;
    --p-inputtext-background: rgba(255, 255, 255, 0.06);
    --p-inputtext-color: #F8FAFC;
    --p-inputtext-border-color: rgba(255, 255, 255, 0.18);
    --p-inputtext-placeholder-color: #64748B;
    --p-select-background: rgba(255, 255, 255, 0.06);
    --p-select-color: #F8FAFC;
    --p-select-border-color: rgba(255, 255, 255, 0.18);
    --p-select-overlay-background: #141B33;
}

.dark-scope :deep(.text-\[\#1E293B\]) { color: #F8FAFC !important; }
.dark-scope :deep(.text-\[\#2563EB\]) { color: #60A5FA !important; }
.dark-scope :deep(.text-slate-700) { color: #CBD5E1 !important; }
.dark-scope :deep(.text-slate-600) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-500) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-400) { color: #64748B !important; }
.dark-scope :deep(.bg-white) { background-color: rgba(255, 255, 255, 0.06) !important; }
.dark-scope :deep(.bg-slate-50) { background-color: rgba(255, 255, 255, 0.04) !important; }
.dark-scope :deep(.bg-slate-100) { background-color: rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.border-slate-100) { border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.border-slate-200) { border-color: rgba(255, 255, 255, 0.12) !important; }
.dark-scope :deep(.border-dashed) { border-color: rgba(255, 255, 255, 0.2) !important; }
.dark-scope :deep(.text-slate-300) { color: #475569 !important; }

.dark-scope :deep(.p-card .p-card-body) { background: transparent !important; }
.dark-scope :deep(.p-card .p-card-content) { background: transparent !important; }

.dark-scope :deep(.p-dialog) { background: #0F1730 !important; color: #F8FAFC !important; border: 1px solid rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.p-dialog-header) { background: #0F1730 !important; border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-dialog-content) { background: #0F1730 !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-dialog-footer) { background: #0F1730 !important; border-top: 1px solid rgba(255, 255, 255, 0.1) !important; }

/* Field backgrounds/borders themselves are handled by the shared
   ".neu-form" rules in app.css (inset pressed-in wells, light + dark) —
   only the teleported Select/Multiselect overlay panel needs its own
   dark treatment here since it renders outside this component. */
.dark-scope :deep(.p-select-overlay),
.dark-scope :deep(.p-multiselect-overlay) { background: #141B33 !important; border-color: rgba(255, 255, 255, 0.12) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-select-option),
.dark-scope :deep(.p-multiselect-option) { color: #E2E8F0 !important; }
.dark-scope :deep(.p-select-option:hover),
.dark-scope :deep(.p-multiselect-option:hover) { background: rgba(255, 255, 255, 0.08) !important; }

/* FloatLabel variant="on" keeps the label permanently floated, cutting
   through the input's top border — its background must always match
   the surface behind it (the neu-card panel), not just on focus, or it
   renders as a mismatched opaque chip. */
:deep(.p-floatlabel-on label) { background: var(--neu-card-light); }
:deep(.p-floatlabel-on:has(input:focus) label),
:deep(.p-floatlabel-on:has(.p-inputwrapper-focus) label) { color: #2563EB !important; }
.dark-scope :deep(.p-floatlabel-on label) { background: var(--neu-card-dark) !important; color: #94A3B8 !important; }
.dark-scope :deep(.p-floatlabel-on:has(input:focus) label),
.dark-scope :deep(.p-floatlabel-on:has(.p-inputwrapper-focus) label) { color: #60A5FA !important; }

.dark-scope :deep(.p-divider.p-divider-horizontal:before) { border-color: rgba(255, 255, 255, 0.1) !important; }

.dark-scope :deep(.p-tablist) { background: transparent !important; border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.p-tab) { color: #94A3B8 !important; }
.dark-scope :deep(.p-tab-active) { color: #F8FAFC !important; }
.dark-scope :deep(.p-tablist-nav-button) {
    background: rgba(15, 23, 48, 0.9) !important;
    color: #60A5FA !important;
    border: 1px solid rgba(96, 165, 250, 0.5) !important;
    border-radius: 999px !important;
    box-shadow: 0 0 8px rgba(96, 165, 250, 0.55), 0 0 18px rgba(96, 165, 250, 0.25) !important;
    transition: box-shadow 0.15s ease, color 0.15s ease, border-color 0.15s ease;
}
.dark-scope :deep(.p-tablist-nav-button:hover) {
    color: #93C5FD !important;
    border-color: rgba(147, 197, 253, 0.8) !important;
    box-shadow: 0 0 12px rgba(147, 197, 253, 0.8), 0 0 26px rgba(147, 197, 253, 0.4) !important;
}
.dark-scope :deep(.p-tabs) { background: transparent !important; }
.dark-scope :deep(.p-tabpanels) { background: transparent !important; color: #F8FAFC !important; padding: 0 !important; }
.dark-scope :deep(.p-tablist-tab-list) { background: transparent !important; }
.dark-scope :deep(.p-tablist-active-bar) { background: #60A5FA !important; }

.dark-scope :deep(.p-togglebutton),
.dark-scope :deep(.p-checkbox-box) { background: rgba(255, 255, 255, 0.06) !important; border-color: rgba(255, 255, 255, 0.2) !important; }
.dark-scope :deep(.p-toggleswitch:not(.p-toggleswitch-checked) .p-toggleswitch-slider) { background: rgba(255, 255, 255, 0.15) !important; }

.dark-scope :deep(.p-message) { background: rgba(255, 255, 255, 0.06) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-message-text) { color: #F8FAFC !important; }

.dark-scope :deep(.p-menu) { background: #0F1730 !important; border-color: rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-menu .p-menu-item-link) { color: #E2E8F0 !important; }
.dark-scope :deep(.p-menu .p-menu-item-link:hover) { background: rgba(255, 255, 255, 0.06) !important; }
</style>