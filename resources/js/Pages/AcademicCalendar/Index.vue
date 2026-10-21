<script setup>
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import Swal from 'sweetalert2';
import { useToast } from 'primevue/usetoast';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import Paginator from 'primevue/paginator';
import Tag from 'primevue/tag';
import Select from 'primevue/select';
import FloatLabel from 'primevue/floatlabel';
import Toast from 'primevue/toast';
import Checkbox from 'primevue/checkbox';
import Popover from 'primevue/popover';
import InfoPopover from '@/Components/InfoPopover.vue';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const props = defineProps({
    academicTerms: { type: Object, default: () => ({ data: [], total: 0, per_page: 10, current_page: 1 }) },
    filters: {
        type: Object,
        default: () => ({ academic_term_search: '' }),
    },
    schedulingSettingsOptions: {
        type: Object,
        default: () => ({
            days: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            default_days: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
            default_class_start_time: '07:00',
            default_class_end_time: '17:00',
        }),
    },
});

// Full day names for the "Class Days" checkboxes — keyed by the short
// Day token the backend stores (available_days column and the
// scheduling engine's Day tokens everywhere else in the app).
const dayLabels = {
    Mon: 'Monday',
    Tue: 'Tuesday',
    Wed: 'Wednesday',
    Thu: 'Thursday',
    Fri: 'Friday',
    Sat: 'Saturday',
    Sun: 'Sunday',
};

// Shows the actual blocking Section codes in a real, click-triggered
// dialog rather than a native hover tooltip — a hover-only title
// attribute is invisible in a screenshot, which is how this app is
// mostly being checked/reported on during development, so it doesn't
// actually help track down which sections are open. Codes come
// straight from academicTerm.unfinalized_section_codes (capped
// server-side at 20; see AcademicTermController::index()).
const showUnfinalizedList = (academicTerm) => {
    const codes = academicTerm.unfinalized_section_codes ?? [];
    const remaining = academicTerm.unfinalized_sections_count - codes.length;
    const listHtml = codes.map((code) => `<li>${code}</li>`).join('');

    Swal.fire({
        title: `${academicTerm.unfinalized_sections_count} section${academicTerm.unfinalized_sections_count === 1 ? '' : 's'} still open`,
        html: `<p style="text-align:left;">These sections under <strong>${academicTerm.school_year?.name ?? ''} - ${academicTerm.semester?.name ?? ''}</strong> aren't finalized yet — End Semester is hidden until they are:</p>
               <ul style="text-align:left; margin-top:8px; max-height:220px; overflow-y:auto;">${listHtml}</ul>
               ${remaining > 0 ? `<p style="text-align:left; margin-top:8px;">+${remaining} more not shown.</p>` : ''}`,
        icon: 'warning',
        confirmButtonColor: '#D97706',
        confirmButtonText: 'Got it',
    });
};

// 12-hour display helper for the read-only Lunch Break/Time Interval
// blocks and for building the Class Start/End Time dropdown labels.
const formatTimeLabel = (time) => {
    if (!time) return '—';
    const [hour, minute] = time.split(':').map(Number);
    const period = hour >= 12 ? 'PM' : 'AM';
    const displayHour = hour % 12 === 0 ? 12 : hour % 12;
    return `${displayHour}:${String(minute).padStart(2, '0')} ${period}`;
};

// Class Start Time / Class End Time dropdown options — every
// half-hour mark from 5:00 AM to 9:00 PM (e.g. "7:00 AM", "7:30 AM",
// "8:00 AM", ...), as "H:i" values so they save straight to the
// School Year's class_start_time / class_end_time columns.
const buildTimeOptions = (startHour, endHour) => {
    const options = [];
    for (let minutes = startHour * 60; minutes <= endHour * 60; minutes += 30) {
        const hour = String(Math.floor(minutes / 60)).padStart(2, '0');
        const minute = String(minutes % 60).padStart(2, '0');
        const value = `${hour}:${minute}`;
        options.push({ label: formatTimeLabel(value), value });
    }
    return options;
};

const classTimeOptions = buildTimeOptions(5, 21);

/* ------------------------------------------------------------------ */
/* Term Setup display helpers                                           */
/* ------------------------------------------------------------------ */

const DAY_ORDER = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

// The class-hours strip spans 5:00 AM – 9:00 PM, the same range as the
// Class Start/End Time dropdowns, so a bar's position is comparable
// between terms.
const hourTicks = ['5 AM', '9 AM', '1 PM', '5 PM', '9 PM'];
const STRIP_START_MIN = 5 * 60;
const STRIP_SPAN_MIN = 16 * 60;

const toMinutes = (time) => {
    if (!time) return null;
    const [hour, minute] = time.split(':').map(Number);
    return hour * 60 + minute;
};

const windowBar = (schoolYear) => {
    const start = toMinutes(schoolYear?.class_start_time);
    const end = toMinutes(schoolYear?.class_end_time);
    if (start === null || end === null) return { left: '0%', width: '0%' };

    const clamp = (value) => Math.max(0, Math.min(100, value));
    const left = clamp(((start - STRIP_START_MIN) / STRIP_SPAN_MIN) * 100);
    const right = clamp(((end - STRIP_START_MIN) / STRIP_SPAN_MIN) * 100);

    return { left: `${left}%`, width: `${Math.max(right - left, 2)}%` };
};

const dayChipTones = {
    emerald: ['bg-emerald-200/70 text-emerald-800', 'bg-emerald-500/20 text-emerald-200'],
    yellow: ['bg-yellow-200/80 text-yellow-900', 'bg-yellow-500/20 text-yellow-200'],
    slate: ['bg-slate-200 text-slate-700', 'bg-white/10 text-slate-300'],
    red: ['bg-red-100 text-red-700', 'bg-red-500/20 text-red-200'],
};

const dayChipClass = (enabled, tone = 'slate') => {
    if (enabled) {
        return (dayChipTones[tone] ?? dayChipTones.slate)[isDark.value ? 1 : 0];
    }
    return isDark.value ? 'bg-white/5 text-slate-600 line-through' : 'bg-white/70 text-slate-400 line-through';
};

// Row colors by status: Active = green, Inactive (planning / not yet
// started or closed out) = yellow, Archived = grey, Deleted = red.
const statusTheme = (term) => {
    const d = isDark.value;
    if (term.deleted_at) {
        return {
            label: 'Deleted', tone: 'red',
            edge: 'border-l-red-500',
            card: d ? 'bg-red-500/10 ring-1 ring-red-500/30' : 'bg-red-50 ring-1 ring-red-200',
            pill: d ? 'bg-red-500/20 text-red-300' : 'bg-red-100 text-red-700',
            bar: 'bg-red-400',
        };
    }
    if (term.status === 'Active') {
        return {
            label: 'Active', tone: 'emerald',
            edge: 'border-l-emerald-500',
            card: d ? 'bg-emerald-500/10 ring-1 ring-emerald-500/30' : 'bg-emerald-50 ring-1 ring-emerald-200',
            pill: d ? 'bg-emerald-500/20 text-emerald-300' : 'bg-emerald-200/70 text-emerald-800',
            bar: 'bg-emerald-500',
        };
    }
    if (term.status === 'Archived') {
        return {
            label: 'Archived', tone: 'slate',
            edge: d ? 'border-l-slate-500' : 'border-l-slate-400',
            card: d ? 'bg-white/5 ring-1 ring-white/10' : 'bg-slate-100 ring-1 ring-slate-200',
            pill: d ? 'bg-white/10 text-slate-300' : 'bg-slate-200 text-slate-700',
            bar: 'bg-slate-400',
        };
    }
    return {
        label: term.status, tone: 'yellow',
        edge: 'border-l-yellow-400',
        card: d ? 'bg-yellow-500/10 ring-1 ring-yellow-500/30' : 'bg-yellow-50 ring-1 ring-yellow-200',
        pill: d ? 'bg-yellow-500/20 text-yellow-300' : 'bg-yellow-200/80 text-yellow-900',
        bar: 'bg-yellow-500',
    };
};

// The Active term shown in the "Current term" card. Looked up in the
// rows already loaded for this page (newest first), so no extra request.
const activeTerm = computed(
    () => props.academicTerms.data.find((term) => term.status === 'Active' && !term.deleted_at) ?? null,
);

const toast = useToast();
const page = usePage();

// Show a toast whenever the backend flashes a success/error message.
watch(
    () => page.props.flash?.success,
    (message) => {
        if (message) {
            toast.add({ severity: 'success', summary: 'Success', detail: message, life: 4000 });
        }
    },
);
watch(
    () => page.props.flash?.error,
    (message) => {
        if (message) {
            toast.add({ severity: 'error', summary: 'Error', detail: message, life: 4000 });
        }
    },
);

// Status dropdown deliberately offers only Active/Inactive — Archived
// is reachable exclusively via the End Semester action, and reversed
// exclusively via the dedicated Reopen action below (each with its
// own gate/reason/log); see AcademicTermController::update()'s guard,
// which independently blocks a direct request from smuggling
// "Archived" through this form either way.
const statusOptions = [
    { label: 'Active', value: 'Active' },
    { label: 'Inactive', value: 'Inactive' },
];

// Semester dropdown — a fixed list, not a lookup table. There's no
// Semester picker/CRUD screen anymore; the backend creates the
// matching Semester record behind the scenes the first time each one
// is actually used (see AcademicTermController@resolveSemester), so
// nothing needs to be seeded for this to work.
const semesterOptions = [
    { label: '1st Semester', value: '1st Semester' },
    { label: '2nd Semester', value: '2nd Semester' },
    { label: 'Summer', value: 'Summer' },
];

/* ------------------------------------------------------------------ */
/* Term Setup                                                      */
/*                                                                      */
/* This is the single place School Year (Start Year/End Year),         */
/* Semester, Status, Remarks, and Scheduling Preferences are all       */
/* entered together — there is no separate School Year or Semester    */
/* tab on this page anymore. The School Year and Semester records      */
/* themselves are untouched under the hood (still used everywhere      */
/* else in the app); saving an Academic Term just finds-or-creates the */
/* matching School Year from Start Year/End Year behind the scenes.    */
/* ------------------------------------------------------------------ */

const academicTermSearch = ref(props.filters.academic_term_search ?? '');
const academicTermLoading = ref(false);
let academicTermSearchDebounce = null;

const reloadAcademicTerms = (extra = {}) => {
    academicTermLoading.value = true;

    router.get(
        route('academic-calendar'),
        { academic_term_search: academicTermSearch.value, ...extra },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['academicTerms'],
            onFinish: () => {
                academicTermLoading.value = false;
            },
        },
    );
};

watch(academicTermSearch, () => {
    clearTimeout(academicTermSearchDebounce);
    academicTermSearchDebounce = setTimeout(() => {
        reloadAcademicTerms({ academic_term_page: 1 });
    }, 350);
});

const onAcademicTermPage = (event) => {
    reloadAcademicTerms({ academic_term_page: event.page + 1 });
};

const onRefreshAcademicTerms = () => {
    reloadAcademicTerms({ academic_term_page: props.academicTerms.current_page });
};

const academicTermDialogVisible = ref(false);
const academicTermDialogMode = ref('add'); // 'add' | 'edit'
const editingAcademicTerm = ref(null);

const academicTermForm = useForm({
    start_year: null,
    end_year: null,
    semester: null,
    status: 'Active',
    remarks: '',
    // Scheduling Preferences — used by the Auto Schedule AI. Time
    // Interval is fixed at 30 Minutes (see the locked "Time Interval"
    // row in the template) so it isn't part of this form's payload.
    class_start_time: props.schedulingSettingsOptions.default_class_start_time,
    class_end_time: props.schedulingSettingsOptions.default_class_end_time,
    available_days: [...props.schedulingSettingsOptions.default_days],
});

// Read-only preview of the derived School Year name (e.g. 2026-2027).
// The user never types this directly — it's derived from Start Year/
// End Year, same as the old standalone School Year form.
const schoolYearNamePreview = computed(() => {
    if (!academicTermForm.start_year || !academicTermForm.end_year) {
        return '—';
    }

    return `${academicTermForm.start_year}-${academicTermForm.end_year}`;
});

// Keep End Year one year ahead of Start Year automatically, so the
// common case needs no extra typing (the user can still override it).
watch(
    () => academicTermForm.start_year,
    (newStartYear, oldStartYear) => {
        if (newStartYear === oldStartYear) {
            return;
        }

        if (newStartYear && (!academicTermForm.end_year || academicTermForm.end_year === oldStartYear + 1)) {
            academicTermForm.end_year = newStartYear + 1;
        }
    },
);

const openAddAcademicTerm = () => {
    academicTermDialogMode.value = 'add';
    editingAcademicTerm.value = null;
    academicTermForm.reset();
    academicTermForm.clearErrors();
    academicTermForm.status = 'Active';
    academicTermForm.class_start_time = props.schedulingSettingsOptions.default_class_start_time;
    academicTermForm.class_end_time = props.schedulingSettingsOptions.default_class_end_time;
    academicTermForm.available_days = [...props.schedulingSettingsOptions.default_days];
    academicTermDialogVisible.value = true;
};

const openEditAcademicTerm = (academicTerm) => {
    academicTermDialogMode.value = 'edit';
    editingAcademicTerm.value = academicTerm;
    academicTermForm.clearErrors();
    academicTermForm.start_year = academicTerm.school_year?.start_year ?? null;
    academicTermForm.end_year = academicTerm.school_year?.end_year ?? null;
    academicTermForm.semester = academicTerm.semester?.name ?? null;
    academicTermForm.status = academicTerm.status;
    academicTermForm.remarks = academicTerm.remarks ?? '';
    academicTermForm.class_start_time = academicTerm.school_year?.class_start_time?.slice(0, 5) ?? props.schedulingSettingsOptions.default_class_start_time;
    academicTermForm.class_end_time = academicTerm.school_year?.class_end_time?.slice(0, 5) ?? props.schedulingSettingsOptions.default_class_end_time;
    academicTermForm.available_days = academicTerm.school_year?.available_days?.length
        ? [...academicTerm.school_year.available_days]
        : [...props.schedulingSettingsOptions.default_days];
    academicTermDialogVisible.value = true;
};

const closeAcademicTermDialog = () => {
    academicTermDialogVisible.value = false;
    academicTermForm.reset();
    academicTermForm.clearErrors();
    editingAcademicTerm.value = null;
};

const onSaveAcademicTerm = () => {
    const onError = () => {
        toast.add({
            severity: 'warn',
            summary: 'Missing information',
            detail: 'Please check the highlighted fields and try again.',
            life: 3000,
        });
    };

    if (academicTermDialogMode.value === 'add') {
        academicTermForm.post(route('academic-terms.store'), {
            preserveScroll: true,
            onSuccess: () => closeAcademicTermDialog(),
            onError,
        });
    } else {
        academicTermForm.put(route('academic-terms.update', editingAcademicTerm.value.id), {
            preserveScroll: true,
            onSuccess: () => closeAcademicTermDialog(),
            onError,
        });
    }
};

// DELETE GATE — the controller (AcademicTermController::destroy())
// independently blocks this when any Section under the term is
// finalized or already has subjects scheduled — see that method's
// docblock. That's the real enforcement; this confirmation is just a
// heads-up. A rejected delete comes back as errors.status and is
// shown in a follow-up alert (same pattern as onArchiveAcademicTerm
// below), naming exactly which sections are in the way.
const onDeleteAcademicTerm = (academicTerm) => {
    Swal.fire({
        title: 'Delete this academic term?',
        html: `<p><strong>${academicTerm.school_year?.name ?? ''} - ${academicTerm.semester?.name ?? ''}</strong> will be archived, not permanently deleted — it can be restored later from this same list.</p>
               <p style="margin-top:8px;">If any section under this term is finalized or already has subjects scheduled, deletion will be blocked until that's resolved.</p>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, delete it',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('academic-terms.destroy', academicTerm.id), {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => {
                    Swal.fire({
                        title: "Can't delete this term",
                        text: errors.status ?? 'Please try again.',
                        icon: 'error',
                        confirmButtonColor: '#DC2626',
                        confirmButtonText: 'Got it',
                    });
                },
            });
        }
    });
};

const onRestoreAcademicTerm = (academicTerm) => {
    router.put(route('academic-terms.restore', academicTerm.id), {}, {
        preserveScroll: true,
        preserveState: true,
    });
};

// Archive ("End Semester") is only offered for Inactive terms that
// have at least one Section (data.has_sections) AND have every one
// of those Sections finalized (data.all_sections_finalized) — a term
// must be switched to Inactive first (or superseded by making another
// term Active, which auto-flips it per AcademicTerm::booted()), and an
// already-Archived term has nothing left to do. The button itself is
// hidden (not just disabled) until both flags are true, computed
// server-side in AcademicTermController::index() — so admins see it
// "unlock" only once the term is actually ready. A term with zero
// Sections shows the "🔒 No sections yet" hint above instead.
//
// END SEMESTER GATE: the controller independently re-checks this on
// submit — see AcademicTermController::archive(). That's the real
// enforcement; the confirmation copy here is just a heads-up. The
// blocking-section list, when the archive is rejected, comes back as
// errors.status and is shown in a follow-up alert rather than a
// generic toast, so the Admin/Registrar sees exactly which sections
// to go finalize first.
//
// DOUBLE CONFIRMATION FOR MISSING OFFERINGS: academicTerm.missing_
// offerings (computed server-side in AcademicTermController::index())
// lists every currently-Active college/major with zero sections at
// all this term. When that list isn't empty, a second prompt collects
// a typed reason before submitting — mirrors the server-side gate in
// archive(), which independently re-checks and rejects with
// errors.missing_offerings if a reason wasn't sent (e.g. the page's
// cached list went stale). Ordinary case (nothing missing) skips
// straight to submitting, same as before this feature existed.
const submitArchive = (academicTerm, reason = '') => {
    router.put(route('academic-terms.archive', academicTerm.id), { reason }, {
        preserveScroll: true,
        preserveState: true,
        onError: (errors) => {
            if (errors.missing_offerings) {
                promptMissingOfferingsReason(academicTerm, errors.missing_offerings);
                return;
            }

            Swal.fire({
                title: "Can't end this semester yet",
                text: errors.status ?? 'Please try again.',
                icon: 'error',
                confirmButtonColor: '#D97706',
                confirmButtonText: 'Got it',
            });
        },
    });
};

const promptMissingOfferingsReason = (academicTerm, warningText) => {
    const listHtml = (academicTerm.missing_offerings ?? [])
        .map((offering) => `<li>${offering.college ? `${offering.college} — ` : ''}${offering.major}</li>`)
        .join('');

    Swal.fire({
        title: 'Some offerings have no sections',
        html: `<p style="text-align:left;">${warningText}</p>
               ${listHtml ? `<ul style="text-align:left; margin-top:8px;">${listHtml}</ul>` : ''}
               <p style="text-align:left; margin-top:12px;">If this is intentional (e.g. that college/major genuinely isn't offered this term), type a short reason to proceed.</p>`,
        icon: 'warning',
        input: 'text',
        inputPlaceholder: 'e.g. COC has no offerings this term, confirmed with the Registrar',
        inputValidator: (value) => (!value || !value.trim() ? 'A reason is required to proceed.' : undefined),
        showCancelButton: true,
        confirmButtonColor: '#D97706',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Proceed anyway',
    }).then((result) => {
        if (result.isConfirmed) {
            submitArchive(academicTerm, result.value.trim());
        }
    });
};

const onArchiveAcademicTerm = (academicTerm) => {
    Swal.fire({
        title: 'End this semester?',
        html: `Archiving <strong>${academicTerm.school_year?.name ?? ''} - ${academicTerm.semester?.name ?? ''}</strong> marks it as historical. Every section under this term must already be finalized — sections still open will block this.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#D97706',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, end semester',
    }).then((result) => {
        if (result.isConfirmed) {
            if ((academicTerm.missing_offerings ?? []).length > 0) {
                promptMissingOfferingsReason(academicTerm, 'These active colleges/majors have no sections at all this term:');
                return;
            }

            submitArchive(academicTerm);
        }
    });
};

// Reopen ("Undo End Semester") — the only way back out of Archived
// (see AcademicTermController::update()'s guard, which blocks the
// Status dropdown from doing this directly). Always requires a typed
// reason, mirroring Finalize/Unlock's own bar for reversing a
// "done" state, and always resolves back to Inactive — see
// AcademicTermController::reopen()'s docblock for why never Active.
const onReopenAcademicTerm = (academicTerm) => {
    Swal.fire({
        title: 'Reopen this semester?',
        html: `<strong>${academicTerm.school_year?.name ?? ''} - ${academicTerm.semester?.name ?? ''}</strong> will go back to Inactive so it can be edited again. Type a short reason for reopening it.`,
        icon: 'warning',
        input: 'text',
        inputPlaceholder: 'e.g. Need to correct a room assignment for BSIT-1A',
        inputValidator: (value) => (!value || !value.trim() ? 'A reason is required to reopen this term.' : undefined),
        showCancelButton: true,
        confirmButtonColor: '#D97706',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, reopen it',
    }).then((result) => {
        if (result.isConfirmed) {
            router.put(route('academic-terms.reopen', academicTerm.id), { reason: result.value.trim() }, {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => {
                    Swal.fire({
                        title: "Can't reopen this term",
                        text: errors.status ?? errors.reason ?? 'Please try again.',
                        icon: 'error',
                        confirmButtonColor: '#D97706',
                        confirmButtonText: 'Got it',
                    });
                },
            });
        }
    });
};
// Help popover — explains the End Semester/Archive finalization
// requirement in place, same pattern as the Section Subjects
// workspace's own "How this page works" info icon.
const helpPopover = ref(null);
</script>

<template>
    <Head title="Term Setup" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Term Setup</span>
        </template>

        <div class="w-full">
            <!-- Page Title -->
            <PageHeader title="Term Setup" subtitle="Manage academic terms — School Year, Semester, and Scheduling Preferences all in one place." icon="pi pi-calendar" tone="teal">
                <template #info>
                    <InfoPopover
                        title="Term Setup"
                        :paragraphs="[
                            'Manages the school\'s academic terms — School Year, Semester, and Scheduling Preferences — used by the scheduling system.',
                        ]"
                        :bullets="[
                            'Only one academic term can be Active at a time.',
                            'Active terms are used for day-to-day scheduling.',
                            'Inactive terms are historical/closed terms.',
                            'A term can only be archived (End Semester) once it has at least one section and all of its sections are finalized.',
                            'An Archived term can be brought back to Inactive via Reopen, with a required reason.',
                            'Admin/Registrar can unlock a finalized section when corrections are required.',
                        ]"
                    />
                </template>
            </PageHeader>

            <Popover ref="helpPopover" :pt="{ root: { class: isDark ? 'dark-scope' : '' } }">
                <div class="w-80 max-w-[85vw] text-sm text-slate-600 leading-relaxed space-y-2">
                    <p>
                        <strong>Active</strong> is the term currently used for day-to-day scheduling — only one term
                        can be Active at a time. Switch it to <strong>Inactive</strong> once its semester is over and
                        you're ready to close it out.
                    </p>
                    <p>
                        <strong>End Semester</strong> (the archive action, shown only on Inactive terms) marks a term
                        as historical. It's blocked unless the term has at least one section and every section under
                        it has been finalized — a term with no sections yet shows a "No sections yet" notice instead
                        of the button, and a term with unfinalized sections shows exactly which ones still need
                        finalizing.
                    </p>
                    <p>
                        An Admin/Registrar can still unlock and correct a section even after its term has been
                        archived — archiving doesn't take that away.
                    </p>
                    <p>
                        <strong>Reopen</strong> (shown only on Archived terms) undoes End Semester, putting the term
                        back to Inactive — never Active. It requires a typed reason, which is recorded to the
                        Activity Log, same as Unlock.
                    </p>
                </div>
            </Popover>

            <!-- Current term -->
            <section
                v-if="activeTerm"
                class="mb-4 rounded-2xl px-5 py-4 ring-1"
                :class="isDark ? 'bg-emerald-500/10 ring-emerald-500/30' : 'bg-emerald-50 ring-emerald-200'"
                aria-label="Current term"
            >
                <div class="flex flex-wrap items-center gap-x-8 gap-y-3">
                    <div class="min-w-0">
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold"
                            :class="isDark ? 'bg-emerald-500/20 text-emerald-300' : 'bg-emerald-200/70 text-emerald-800'"
                        >
                            <span class="term-live-dot h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            Current term
                        </span>
                        <h2 class="mt-1 text-2xl font-bold leading-tight tracking-tight" :class="isDark ? 'text-white' : 'text-emerald-950'">
                            {{ activeTerm.school_year?.name }}
                            <span class="ml-1 text-base font-medium" :class="isDark ? 'text-emerald-200/80' : 'text-emerald-800/80'">{{ activeTerm.semester?.name }}</span>
                        </h2>
                    </div>

                    <div class="min-w-[15rem] flex-1">
                        <div class="flex items-baseline justify-between gap-3 text-sm">
                            <span :class="isDark ? 'text-emerald-200/80' : 'text-emerald-800/80'">Class hours</span>
                            <span class="font-semibold tabular-nums" :class="isDark ? 'text-white' : 'text-emerald-950'">
                                {{ formatTimeLabel(activeTerm.school_year?.class_start_time?.slice(0, 5)) }} –
                                {{ formatTimeLabel(activeTerm.school_year?.class_end_time?.slice(0, 5)) }}
                            </span>
                        </div>
                        <div class="relative mt-1.5 h-2.5 overflow-hidden rounded-full" :class="isDark ? 'bg-white/10' : 'bg-white'" role="img"
                            :aria-label="`Classes run ${formatTimeLabel(activeTerm.school_year?.class_start_time?.slice(0, 5))} to ${formatTimeLabel(activeTerm.school_year?.class_end_time?.slice(0, 5))}`">
                            <div class="absolute inset-y-0 rounded-full bg-gradient-to-r from-emerald-600 to-emerald-400" :style="windowBar(activeTerm.school_year)"></div>
                        </div>
                        <div class="mt-1 flex justify-between text-[11px] tabular-nums" :class="isDark ? 'text-emerald-200/60' : 'text-emerald-800/60'">
                            <span v-for="tick in hourTicks" :key="tick">{{ tick }}</span>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5">
                        <span
                            v-for="day in DAY_ORDER"
                            :key="day"
                            class="inline-flex h-7 min-w-[2.25rem] items-center justify-center rounded-md px-1.5 text-xs font-medium"
                            :class="dayChipClass((activeTerm.school_year?.available_days ?? []).includes(day), 'emerald')"
                            :title="dayLabels[day]"
                        >{{ day }}</span>
                    </div>

                    <Button label="Edit" icon="pi pi-pencil" size="small" severity="success" @click="openEditAcademicTerm(activeTerm)" />
                </div>
            </section>

            <!-- All terms -->
            <div class="neu-card rounded-2xl p-4 transition-colors duration-300">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-lg font-semibold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">All terms</h2>
                    <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                        <span class="relative w-full sm:w-72">
                            <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-sm" :class="isDark ? 'text-slate-500' : 'text-slate-400'"></i>
                            <InputText
                                v-uppercase
                                v-model="academicTermSearch"
                                placeholder="Search by school year or semester"
                                class="neu-inset w-full !rounded-xl !border-none !pl-9"
                                :class="isDark ? '!text-white placeholder:!text-slate-500' : ''"
                            />
                        </span>
                        <Button
                            icon="pi pi-refresh"
                            severity="secondary"
                            text
                            class="neu-icon-well !rounded-full"
                            :loading="academicTermLoading"
                            @click="onRefreshAcademicTerms"
                            aria-label="Refresh"
                        />
                        <Button label="Add academic term" icon="pi pi-plus" @click="openAddAcademicTerm" />
                    </div>
                </div>

                <!-- Empty state -->
                <div v-if="!academicTerms.data.length" class="py-14 text-center">
                    <i class="pi pi-calendar-plus text-4xl" :class="isDark ? 'text-slate-600' : 'text-slate-300'"></i>
                    <p class="mt-3 font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-600'">
                        {{ academicTermSearch ? 'No terms match your search.' : 'No academic terms yet.' }}
                    </p>
                    <p class="mt-1 text-sm" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                        {{ academicTermSearch ? 'Try a different school year or semester.' : 'Add a term to set the school year and class hours for scheduling.' }}
                    </p>
                    <Button v-if="!academicTermSearch" label="Add academic term" icon="pi pi-plus" class="mt-4" @click="openAddAcademicTerm" />
                </div>

                <ul v-else class="space-y-2" :class="academicTermLoading ? 'opacity-60 transition-opacity' : ''">
                    <li
                        v-for="term in academicTerms.data"
                        :key="term.id"
                        class="grid items-center gap-x-4 gap-y-2 rounded-xl border-l-4 px-4 py-2.5 md:grid-cols-[minmax(0,4fr)_minmax(0,6fr)_auto]"
                        :class="[statusTheme(term).edge, statusTheme(term).card, term.deleted_at ? 'opacity-70' : '']"
                    >
                        <!-- Term + status -->
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-base font-semibold tabular-nums" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                                    {{ term.school_year?.name }}
                                </span>
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="statusTheme(term).pill">
                                    {{ statusTheme(term).label }}
                                </span>
                            </div>
                            <p class="text-sm" :class="isDark ? 'text-slate-400' : 'text-slate-500'">{{ term.semester?.name }}</p>

                            <button
                                v-if="!term.deleted_at && term.status === 'Inactive' && !term.all_sections_finalized"
                                type="button"
                                class="mt-1 inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-orange-500"
                                :class="isDark ? 'bg-orange-500/20 text-orange-300' : 'bg-orange-100 text-orange-800'"
                                @click="showUnfinalizedList(term)"
                            >
                                <i class="pi pi-lock text-[10px]"></i>
                                {{ term.unfinalized_sections_count }} section{{ term.unfinalized_sections_count === 1 ? '' : 's' }} still open
                            </button>
                            <span
                                v-else-if="!term.deleted_at && term.status === 'Inactive' && !term.has_sections"
                                class="mt-1 inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="isDark ? 'bg-orange-500/20 text-orange-300' : 'bg-orange-100 text-orange-800'"
                                title="Add at least one section under this term before End Semester becomes available."
                            >
                                <i class="pi pi-lock text-[10px]"></i>
                                No sections yet
                            </span>
                        </div>

                        <!-- Class hours + days + remarks -->
                        <div class="min-w-0">
                            <p class="text-sm font-medium tabular-nums" :class="isDark ? 'text-slate-200' : 'text-slate-700'">
                                {{ formatTimeLabel(term.school_year?.class_start_time?.slice(0, 5)) }} –
                                {{ formatTimeLabel(term.school_year?.class_end_time?.slice(0, 5)) }}
                            </p>
                            <div class="relative mt-1 h-1.5 overflow-hidden rounded-full" :class="isDark ? 'bg-white/10' : 'bg-white'">
                                <div class="absolute inset-y-0 rounded-full" :class="statusTheme(term).bar" :style="windowBar(term.school_year)"></div>
                            </div>
                            <div class="mt-1.5 flex flex-wrap gap-1">
                                <span
                                    v-for="day in DAY_ORDER"
                                    :key="day"
                                    class="inline-flex h-5 min-w-[1.75rem] items-center justify-center rounded px-1 text-[11px] font-medium"
                                    :class="dayChipClass((term.school_year?.available_days ?? []).includes(day), statusTheme(term).tone)"
                                    :title="dayLabels[day]"
                                >
                                    {{ day }}
                                </span>
                            </div>
                            <p v-if="term.remarks" class="mt-1 truncate text-xs" :class="isDark ? 'text-slate-500' : 'text-slate-400'" :title="term.remarks">
                                {{ term.remarks }}
                            </p>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center gap-1 md:justify-end">
                            <template v-if="!term.deleted_at">
                                <Button
                                    v-if="term.status === 'Inactive' && term.all_sections_finalized && term.has_sections"
                                    label="End semester"
                                    icon="pi pi-inbox"
                                    size="small"
                                    severity="warn"
                                    outlined
                                    title="Archive this term"
                                    @click="onArchiveAcademicTerm(term)"
                                />
                                <Button
                                    v-if="term.status === 'Archived'"
                                    label="Reopen"
                                    icon="pi pi-history"
                                    size="small"
                                    severity="warn"
                                    outlined
                                    title="Undo End Semester — back to Inactive"
                                    @click="onReopenAcademicTerm(term)"
                                />
                                <Button icon="pi pi-pencil" text rounded severity="secondary" size="small" aria-label="Edit term" title="Edit term" @click="openEditAcademicTerm(term)" />
                                <Button icon="pi pi-trash" text rounded severity="danger" size="small" aria-label="Delete term" title="Delete term" @click="onDeleteAcademicTerm(term)" />
                            </template>
                            <Button
                                v-else
                                icon="pi pi-refresh"
                                label="Restore"
                                text
                                severity="success"
                                size="small"
                                @click="onRestoreAcademicTerm(term)"
                            />
                        </div>
                    </li>
                </ul>

                <Paginator
                    v-if="academicTerms.total > academicTerms.per_page"
                    class="!mt-4 !bg-transparent"
                    :class="isDark ? 'term-paginator-dark' : ''"
                    template="PrevPageLink PageLinks NextPageLink"
                    :rows="academicTerms.per_page"
                    :totalRecords="academicTerms.total"
                    :first="(academicTerms.current_page - 1) * academicTerms.per_page"
                    @page="onAcademicTermPage"
                />
            </div>
        </div>

        <!-- Add / Edit Academic Term Modal -->
        <Dialog
            v-model:visible="academicTermDialogVisible"
            modal
            :style="{ width: '620px', ...(isDark ? {} : { backgroundColor: 'var(--neu-card-light)' }) }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            :pt="{
                root: { class: isDark ? '!bg-[#141D33] !border !border-white/10 !text-white !rounded-2xl !shadow-2xl' : '!border !border-[rgba(30,41,59,0.06)] !rounded-2xl !shadow-2xl' },
                header: { class: isDark ? '!bg-[#141D33] !border-b !border-white/10 !rounded-t-2xl' : '!rounded-t-2xl' },
                content: { class: isDark ? '!bg-[#141D33]' : '' },
                footer: { class: isDark ? '!bg-[#141D33] !border-t !border-white/10 !rounded-b-2xl' : '!rounded-b-2xl' },
            }"
        >
            <template #header>
                <span class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                    {{ academicTermDialogMode === 'add' ? 'Add Academic Term' : 'Edit Academic Term' }}
                </span>
            </template>

            <form class="pt-2 academic-term-form" autocomplete="off" @submit.prevent="onSaveAcademicTerm">
                <!-- School Year -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <FloatLabel variant="on">
                        <InputNumber
                            id="academicTermStartYear"
                            v-model="academicTermForm.start_year"
                            class="w-full"
                            :useGrouping="false"
                            :invalid="!!academicTermForm.errors.start_year"
                        />
                        <label for="academicTermStartYear">Start Year *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <InputNumber
                            id="academicTermEndYear"
                            v-model="academicTermForm.end_year"
                            class="w-full"
                            :useGrouping="false"
                            :invalid="!!academicTermForm.errors.end_year"
                        />
                        <label for="academicTermEndYear">End Year *</label>
                    </FloatLabel>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-1 mt-1">
                    <small v-if="academicTermForm.errors.start_year" class="text-red-500">{{ academicTermForm.errors.start_year }}</small>
                    <small v-if="academicTermForm.errors.end_year" class="text-red-500">{{ academicTermForm.errors.end_year }}</small>
                </div>

                <!-- Read-only preview of the derived School Year name -->
                <div class="mt-3 rounded-xl border px-4 py-3" :class="isDark ? 'border-white/10 bg-white/[0.04]' : 'border-slate-200 bg-slate-50'">
                    <p class="text-xs font-medium uppercase tracking-wider" :class="isDark ? 'text-slate-400' : 'text-slate-500'">School Year</p>
                    <p class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">{{ schoolYearNamePreview }}</p>
                </div>

                <!-- Semester -->
                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <Select
                            id="academicTermSemester"
                            v-model="academicTermForm.semester"
                            :options="semesterOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :invalid="!!academicTermForm.errors.semester"
                        />
                        <label for="academicTermSemester">Semester *</label>
                    </FloatLabel>
                    <small v-if="academicTermForm.errors.semester" class="text-red-500 -mt-4">{{ academicTermForm.errors.semester }}</small>
                </div>

                <!-- Status -->
                <div class="grid grid-cols-1 gap-5 mt-5">
                    <template v-if="academicTermForm.status === 'Archived'">
                        <div class="rounded-xl border px-4 py-3" :class="isDark ? 'border-white/10 bg-white/[0.04]' : 'border-slate-200 bg-slate-50'">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium" :class="isDark ? 'text-slate-200' : 'text-slate-700'">Status</span>
                                <Tag value="Archived" severity="warn" />
                            </div>
                            <p class="text-xs mt-2" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                                This term has already been ended. Close this dialog and use the Reopen action on the list
                                to bring it back to Inactive — status can't be changed from here while it's Archived.
                            </p>
                        </div>
                    </template>
                    <template v-else>
                        <FloatLabel variant="on">
                            <Select
                                id="academicTermStatus"
                                v-model="academicTermForm.status"
                                :options="statusOptions"
                                optionLabel="label"
                                optionValue="value"
                                class="w-full"
                                :invalid="!!academicTermForm.errors.status"
                            />
                            <label for="academicTermStatus">Status *</label>
                        </FloatLabel>
                        <small v-if="academicTermForm.errors.status" class="text-red-500 -mt-4">{{ academicTermForm.errors.status }}</small>
                        <p v-if="academicTermForm.status === 'Active'" class="text-xs -mt-4" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                            Setting this Academic Term Active will automatically set every other Academic Term to Inactive.
                        </p>
                    </template>
                </div>

                <!-- Remarks -->
                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            v-uppercase
                            id="academicTermRemarks"
                            v-model="academicTermForm.remarks"
                            class="w-full"
                            :invalid="!!academicTermForm.errors.remarks"
                            maxlength="255"
                        />
                        <label for="academicTermRemarks">Remarks</label>
                    </FloatLabel>
                    <small v-if="academicTermForm.errors.remarks" class="text-red-500 -mt-4">{{ academicTermForm.errors.remarks }}</small>
                </div>

                <!-- Scheduling Preferences — used by the Auto Schedule AI -->
                <div class="mt-6 pt-5 border-t" :class="isDark ? 'border-white/10' : 'border-slate-100'">
                    <p class="text-sm font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Scheduling Preferences</p>
                    <p class="text-xs mt-1" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                        These rules are used by the Auto Schedule AI when generating class schedules for this School Year.
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <Select
                            id="academicTermClassStartTime"
                            v-model="academicTermForm.class_start_time"
                            :options="classTimeOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :invalid="!!academicTermForm.errors.class_start_time"
                        />
                        <label for="academicTermClassStartTime">Class Start Time *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <Select
                            id="academicTermClassEndTime"
                            v-model="academicTermForm.class_end_time"
                            :options="classTimeOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :invalid="!!academicTermForm.errors.class_end_time"
                        />
                        <label for="academicTermClassEndTime">Class End Time *</label>
                    </FloatLabel>
                </div>
                <div class="grid grid-cols-2 gap-1 mt-1">
                    <small v-if="academicTermForm.errors.class_start_time" class="text-red-500">{{ academicTermForm.errors.class_start_time }}</small>
                    <small v-if="academicTermForm.errors.class_end_time" class="text-red-500">{{ academicTermForm.errors.class_end_time }}</small>
                </div>

                <!-- Time Interval — locked information row, always 30 Minutes -->
                <div class="mt-5 rounded-xl border px-4 py-3" :class="isDark ? 'border-white/10 bg-white/[0.04]' : 'border-slate-200 bg-slate-50'">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium" :class="isDark ? 'text-slate-200' : 'text-slate-700'">Time Interval</span>
                        <i class="pi pi-lock text-xs" :class="isDark ? 'text-slate-500' : 'text-slate-400'" title="Not editable"></i>
                    </div>
                    <p class="text-sm mt-1" :class="isDark ? 'text-slate-400' : 'text-slate-500'">30 Minutes</p>
                    <p class="text-xs mt-1" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                        The Auto Schedule AI slices the day into 30-minute slots when generating class schedules.
                    </p>
                </div>

                <div class="mt-5">
                    <label class="text-sm font-medium" :class="isDark ? 'text-slate-200' : 'text-slate-700'">Class Days *</label>
                    <div class="grid grid-cols-2 gap-3 mt-2">
                        <div v-for="day in props.schedulingSettingsOptions.days" :key="day" class="flex items-center gap-2">
                            <Checkbox
                                v-model="academicTermForm.available_days"
                                :inputId="`academicTermDay_${day}`"
                                :value="day"
                            />
                            <label :for="`academicTermDay_${day}`" class="text-sm" :class="isDark ? 'text-slate-300' : 'text-slate-600'">{{ dayLabels[day] }}</label>
                        </div>
                    </div>
                    <small v-if="academicTermForm.errors.available_days" class="text-red-500 block mt-2">{{ academicTermForm.errors.available_days }}</small>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" text severity="secondary" :disabled="academicTermForm.processing" @click="closeAcademicTermDialog" />
                <Button label="Save" icon="pi pi-check" :loading="academicTermForm.processing" @click="onSaveAcademicTerm" />
            </template>
        </Dialog>
    </AppLayout>
</template>

<style scoped>
/* The only non-user-triggered motion on the page: a soft pulse on the
   Active term's dot. Switched off for people who ask for less motion. */
.term-live-dot {
    animation: term-live-pulse 2.4s ease-in-out infinite;
}
@keyframes term-live-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.55); }
    50% { box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
}
@media (prefers-reduced-motion: reduce) {
    .term-live-dot { animation: none; }
}

/* Dark-mode paginator (PrimeVue's own classes, kept local to this page). */
.term-paginator-dark :deep(.p-paginator) {
    background: transparent;
    color: #cbd5e1;
}
.term-paginator-dark :deep(.p-paginator-page),
.term-paginator-dark :deep(.p-paginator-prev),
.term-paginator-dark :deep(.p-paginator-next) {
    color: #cbd5e1;
}
.term-paginator-dark :deep(.p-paginator-page.p-paginator-page-selected) {
    background: rgba(96, 165, 250, 0.2);
    color: #bfdbfe;
}
</style>