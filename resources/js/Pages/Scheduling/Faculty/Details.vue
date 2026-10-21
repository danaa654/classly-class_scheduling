<script setup>
import { Head, useForm, usePage, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch, nextTick } from 'vue';
import { useToast } from 'primevue/usetoast';
import Swal from 'sweetalert2';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackLink from '@/Components/BackLink.vue';
import Card from 'primevue/card';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Textarea from 'primevue/textarea';
import Select from 'primevue/select';
import MultiSelect from 'primevue/multiselect';
import Button from 'primevue/button';
import ToggleSwitch from 'primevue/toggleswitch';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import Tabs from 'primevue/tabs';
import TabList from 'primevue/tablist';
import Tab from 'primevue/tab';
import TabPanels from 'primevue/tabpanels';
import TabPanel from 'primevue/tabpanel';
import Toast from 'primevue/toast';
import ProgressBar from 'primevue/progressbar';
import InfoPopover from '@/Components/InfoPopover.vue';
import SendScheduleModal from '@/Pages/Reports/Partials/SendScheduleModal.vue';
import FacultyWeekGrid from '@/Components/Scheduling/FacultyWeekGrid.vue';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const props = defineProps({
    faculty: { type: Object, required: true },
    colleges: { type: Array, default: () => [] },
    subjects: { type: Array, default: () => [] },
    hardCapUnits: { type: Number, default: 40 },
    // Gates the "Edit Information" button — false when this faculty
    // belongs to another College and the viewer got here via the "All
    // Faculty" filter (Dean/OIC read-mostly scope). See
    // FacultyPolicy::canAccess() / FacultyController::show().
    canEdit: { type: Boolean, default: true },
    // Term-scoped data backing the Workload tab's Print / Send via Email
    // buttons — same underlying flow as Reports > Schedule by Faculty's
    // single-faculty send, see FacultyController@show.
    scheduleMeta: {
        type: Object,
        default: () => ({ academic_term_id: null, academic_year: null, semester: null, is_finalized: false }),
    },
    // Set teaching hours for this faculty member, as editable rows of
    // { days: ['Mon', ...], start_time: '08:00', end_time: '12:00' }.
    // Empty = available any time. See FacultyController@show.
    facultyAvailability: { type: Array, default: () => [] },
    // Class hours / Available Days of the viewed term's School Year — the
    // Workload tab's Grid view lays out on it (see FacultyController@show).
    schedulingWindow: {
        type: Object,
        default: () => ({ start_time: '07:00', end_time: '17:00', available_days: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], interval_minutes: 30 }),
    },
    // SYSTEM ROLES — what this person is besides Faculty (Registrar / OIC of a
    // college / ...), from FacultyController@show. Everyone sees the badges;
    // only an Administrator (canManage) gets the editor in Edit Faculty.
    systemRoles: {
        type: Object,
        default: () => ({ canManage: false, roles: [], hasLogin: false, collegeId: null, departmentIds: [], isAdministratorLogin: false, linkableLogin: false, assignable: [] }),
    },
    allDepartments: { type: Array, default: () => [] },
});

/* Presentation helpers — college color pill / ID badge, same look as the
   Faculty list, Subjects and Curriculum pages. */
const collegeColorValue = computed(() => {
    const c = props.faculty?.college?.color;
    return /^#[0-9a-f]{6}$/i.test(c ?? '') ? c : null;
});
const collegePillStyle = computed(() => ({
    backgroundColor: `${collegeColorValue.value ?? '#94A3B8'}${isDark.value ? '33' : '22'}`,
}));
const idBadgeStyle = computed(() => {
    const color = collegeColorValue.value ?? '#64748B';
    const r = parseInt(color.slice(1, 3), 16);
    const g = parseInt(color.slice(3, 5), 16);
    const b = parseInt(color.slice(5, 7), 16);
    const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;

    return { backgroundColor: color, color: luminance > 0.62 ? '#1E293B' : '#FFFFFF' };
});
const subjectRowStyle = () => ({
    boxShadow: `inset 4px 0 0 0 ${collegeColorValue.value ?? '#CBD5E1'}`,
});

const toast = useToast();
const page = usePage();

// Same restriction as the Faculty Master (Index) edit modal: only
// Admin/Registrar may change the load ceiling directly. Everyone else
// (Dean/OIC/Assistant Dean) must go through a load request instead.
const canChangeMaxLoad = computed(() => !!page.props.auth?.can?.changeFacultyMaxLoad);

watch(
    () => page.props.flash?.success,
    (message) => {
        if (message) toast.add({ severity: 'success', summary: 'Success', detail: message, life: 4000 });
    },
);
watch(
    () => page.props.flash?.error,
    (message) => {
        if (message) toast.add({ severity: 'error', summary: 'Error', detail: message, life: 4000 });
    },
);

const activeTab = ref('information');

const fullName = computed(() => {
    const middleInitial = props.faculty.middle_name ? ` ${props.faculty.middle_name.charAt(0)}.` : '';
    const suffix = props.faculty.suffix ? ` ${props.faculty.suffix}` : '';
    return `${props.faculty.last_name}, ${props.faculty.first_name}${middleInitial}${suffix}`;
});

/* ------------------------------------------------------------------ */
/* Workload tab — Print / Send via Email (mirrors Reports > Schedule    */
/* by Faculty's single-faculty flow, scoped to whichever term this     */
/* user is currently viewing rather than a report filter).             */
/* ------------------------------------------------------------------ */
const showSendScheduleModal = ref(false);

const sendScheduleFaculty = computed(() => ({
    id: props.faculty.id,
    full_name: fullName.value,
    faculty_id: props.faculty.faculty_id,
    email: props.faculty.email,
}));

const sendScheduleTerm = computed(() => {
    if (!props.scheduleMeta.academic_term_id) return null;
    return {
        id: props.scheduleMeta.academic_term_id,
        label: `${props.scheduleMeta.academic_year} · ${props.scheduleMeta.semester}`,
    };
});

// Assigned Subjects: Table (default) or the weekly Grid. Print follows
// whichever is showing — the Grid prints the same one-page landscape
// timetable as Reports > Schedule by Faculty > Grid.
const workloadView = ref('table');

function printFacultySchedule() {
    if (!props.scheduleMeta.academic_year) return;
    window.open(
        route('reports.print', {
            report_type: 'schedule_by_faculty',
            faculty_id: [props.faculty.id],
            academic_year: props.scheduleMeta.academic_year,
            semester: props.scheduleMeta.semester,
            ...(workloadView.value === 'grid' ? { view_mode: 'grid' } : {}),
        }),
        '_blank',
    );
}

/* ------------------------------------------------------------------ */
/* Information tab                                                     */
/* ------------------------------------------------------------------ */

const employmentTypeOptions = ['Full-time', 'Part-time'];
const statusOptions = [
    { label: 'Active', value: 'Active' },
    { label: 'Inactive', value: 'Inactive' },
];

const editVisible = ref(false);

const facultyForm = useForm({
    faculty_id: props.faculty.faculty_id,
    first_name: props.faculty.first_name,
    middle_name: props.faculty.middle_name,
    last_name: props.faculty.last_name,
    suffix: props.faculty.suffix,
    employment_type: props.faculty.employment_type,
    college_id: props.faculty.college_id,
    max_teaching_units: props.faculty.max_teaching_units,
    workload_type: props.faculty.workload_type ?? 'units',
    max_weekly_hours: props.faculty.max_weekly_hours,
    status: props.faculty.status,
    email: props.faculty.email,
    contact_number: props.faculty.contact_number,
    remarks: props.faculty.remarks,
    // System roles (Administrator-only; stripped from the request otherwise).
    system_roles: [...props.systemRoles.roles],
    role_college_id: props.systemRoles.collegeId ?? props.faculty.college_id ?? null,
    role_oversees_all_departments: !(props.systemRoles.departmentIds?.length > 0),
    role_department_ids: [...(props.systemRoles.departmentIds ?? [])],
}).transform((data) => {
    if (props.systemRoles.canManage) return data;
    const { system_roles, role_college_id, role_oversees_all_departments, role_department_ids, ...rest } = data;
    return rest;
});

// Whichever workload measurement the institution uses — 'units'
// (default, checked against Max Teaching Units) or 'hours' (checked
// against Max Weekly Hours instead). See FacultyWorkloadService.
const workloadTypeOptions = [
    { label: 'Teaching Units', value: 'units' },
    { label: 'Weekly Hours', value: 'hours' },
];

// Faculty ID, name, suffix, contact number, and remarks are always
// stored/displayed in caps (matches the table); Email is left as
// typed since addresses are case-sensitive-looking to users.
const UPPERCASE_FIELDS = ['faculty_id', 'first_name', 'middle_name', 'last_name', 'suffix', 'contact_number', 'remarks'];
UPPERCASE_FIELDS.forEach((field) => {
    watch(
        () => facultyForm[field],
        (value) => {
            if (typeof value === 'string' && value !== value.toUpperCase()) {
                facultyForm[field] = value.toUpperCase();
            }
        },
    );
});

const openEdit = () => {
    facultyForm.clearErrors();
    facultyForm.faculty_id = props.faculty.faculty_id;
    facultyForm.first_name = props.faculty.first_name;
    facultyForm.middle_name = props.faculty.middle_name;
    facultyForm.last_name = props.faculty.last_name;
    facultyForm.suffix = props.faculty.suffix;
    facultyForm.employment_type = props.faculty.employment_type;
    facultyForm.college_id = props.faculty.college_id;
    facultyForm.max_teaching_units = props.faculty.max_teaching_units;
    facultyForm.workload_type = props.faculty.workload_type ?? 'units';
    facultyForm.max_weekly_hours = props.faculty.max_weekly_hours;
    facultyForm.status = props.faculty.status;
    facultyForm.email = props.faculty.email;
    facultyForm.contact_number = props.faculty.contact_number;
    facultyForm.remarks = props.faculty.remarks;
    facultyForm.system_roles = [...props.systemRoles.roles];
    facultyForm.role_college_id = props.systemRoles.collegeId ?? props.faculty.college_id ?? null;
    facultyForm.role_oversees_all_departments = !(props.systemRoles.departmentIds?.length > 0);
    facultyForm.role_department_ids = [...(props.systemRoles.departmentIds ?? [])];
    editVisible.value = true;
};

/* System roles editor ------------------------------------------------ */
const ROLE_HINTS = {
    Registrar: 'Full registrar access across every college.',
    'Assistant Dean': 'GenEd / Minor scheduling across colleges.',
    Dean: 'Runs one whole college.',
    OIC: 'Officer-in-charge of a college (or some of its departments).',
};
const toggleSystemRole = (role, checked) => {
    const next = new Set(facultyForm.system_roles);
    if (checked) {
        next.add(role);
        // A college has a Dean OR an OIC, never both on one person.
        if (role === 'Dean') next.delete('OIC');
        if (role === 'OIC') next.delete('Dean');
    } else {
        next.delete(role);
    }
    facultyForm.system_roles = [...next];
};
const needsRoleCollege = computed(() => facultyForm.system_roles.some((r) => r === 'Dean' || r === 'OIC'));
const isOicSelected = computed(() => facultyForm.system_roles.includes('OIC'));
const roleDepartments = computed(() => props.allDepartments.filter((d) => d.college_id === facultyForm.role_college_id));
watch(
    () => facultyForm.role_college_id,
    (id, old) => {
        if (old !== undefined && id !== old) facultyForm.role_department_ids = [];
    },
);
const collegeNameById = (id) => props.colleges.find((c) => c.id === id)?.name ?? (id === props.faculty.college_id ? props.faculty.college?.name : null);

// Header badges: "Registrar", "OIC · College of Computer Studies", ...
const roleBadges = computed(() =>
    props.systemRoles.roles.map((role) => {
        const scoped = role === 'Dean' || role === 'OIC';
        const college = scoped ? collegeNameById(props.systemRoles.collegeId) : null;
        return { role, label: college ? `${role} · ${college}` : role };
    }),
);
const loginNote = computed(() => {
    if (props.systemRoles.hasLogin) return 'Changes apply to this person\'s existing login. Remove every role to make it a plain faculty login again.';
    if (props.systemRoles.linkableLogin) return 'An account with this email already exists. It will be linked to this faculty record, so they keep one login.';
    return 'This person has no login yet. Saving a role creates one using the email above and sends a set-password invitation.';
});

const closeEdit = () => {
    editVisible.value = false;
    facultyForm.clearErrors();
};

const onSaveFaculty = () => {
    facultyForm.put(route('scheduling.faculty.update', props.faculty.id), {
        preserveScroll: true,
        onSuccess: () => closeEdit(),
    });
};

/* ------------------------------------------------------------------ */
/* Teaching Qualifications tab                                         */
/* ------------------------------------------------------------------ */

const selectedSubjectIds = ref((props.faculty.subjects ?? []).map((subject) => subject.id));

// The "Assign Subjects" search box below is PrimeVue MultiSelect's
// built-in filter input, teleported into an overlay only while the
// dropdown is open. The app-wide v-uppercase directive looks for an
// <input> inside the element it's attached to at mount time, so it
// never finds this one — it doesn't exist in that spot yet, and once
// it does it's been moved outside this component's DOM subtree
// entirely. Uppercasing it here instead, via the component's own
// `filter` event and its internal filterInput ref, so what you type
// to search matches the ALL-CAPS subject codes it's searching
// against.
const subjectMultiSelect = ref(null);
const onSubjectFilter = (event) => {
    const upper = event.value.toUpperCase();
    if (event.value === upper) return;

    const inputEl = subjectMultiSelect.value?.$refs?.filterInput?.$el;
    const start = inputEl?.selectionStart;
    const end = inputEl?.selectionEnd;

    subjectMultiSelect.value.filterValue = upper;

    nextTick(() => {
        if (inputEl && document.activeElement === inputEl) {
            inputEl.setSelectionRange(start, end);
        }
    });
};

/* ------------------------------------------------------------------ */
/* Availability tab (set teaching hours)                               */
/* ------------------------------------------------------------------ */

const ALL_DAY_ORDER = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

// Deep copy so editing rows never mutates the prop.
function cloneAvailability(rows) {
    return (rows ?? []).map((row) => ({
        days: [...row.days],
        start_time: row.start_time,
        end_time: row.end_time,
    }));
}

const availabilityForm = useForm({
    availability: cloneAvailability(props.facultyAvailability),
});

// Re-sync after a save (Inertia hands back fresh props).
watch(
    () => props.facultyAvailability,
    (rows) => {
        availabilityForm.availability = cloneAvailability(rows);
        availabilityForm.clearErrors();
    },
);

const availabilityDayOptions = computed(() => props.schedulingWindow.available_days ?? ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']);

// Off = no restriction (no rows saved); on = only the rows below.
const hasAvailabilityLimit = computed({
    get: () => availabilityForm.availability.length > 0,
    set: (on) => {
        if (!on) {
            availabilityForm.availability = [];
        } else if (availabilityForm.availability.length === 0) {
            addAvailabilityWindow();
        }
    },
});

// The Term Setup class hours always win: an availability time outside
// them is pulled back to the nearest class-hour boundary (e.g. term is
// 8:00 AM-7:30 PM, so 7:00 AM becomes 8:00 AM and 8:00 PM becomes 7:30 PM).
const classStart = computed(() => String(props.schedulingWindow.start_time).slice(0, 5));
const classEnd = computed(() => String(props.schedulingWindow.end_time).slice(0, 5));

const clampRow = (row) => {
    const clamp = (value) => (value ? (value < classStart.value ? classStart.value : value > classEnd.value ? classEnd.value : value) : value);
    row.start_time = clamp(row.start_time);
    row.end_time = clamp(row.end_time);
};

const addAvailabilityWindow = () => {
    const row = {
        days: [...availabilityDayOptions.value],
        start_time: '08:00',
        end_time: '12:00',
    };
    clampRow(row);
    availabilityForm.availability.push(row);
};

const removeAvailabilityWindow = (index) => {
    availabilityForm.availability.splice(index, 1);
};

const toggleAvailabilityDay = (row, day) => {
    const at = row.days.indexOf(day);
    if (at === -1) {
        row.days.push(day);
        row.days.sort((a, b) => ALL_DAY_ORDER.indexOf(a) - ALL_DAY_ORDER.indexOf(b));
    } else {
        row.days.splice(at, 1);
    }
};

const normalizeAvailability = (rows) =>
    JSON.stringify(
        (rows ?? [])
            .map((row) => ({ days: [...row.days].sort(), start_time: row.start_time, end_time: row.end_time }))
            .sort((a, b) => (a.start_time + a.days.join()).localeCompare(b.start_time + b.days.join())),
    );

const isAvailabilityDirty = computed(
    () => normalizeAvailability(availabilityForm.availability) !== normalizeAvailability(props.facultyAvailability),
);

const saveAvailability = () => {
    availabilityForm.availability.forEach(clampRow);
    availabilityForm.put(route('scheduling.faculty.availability.update', props.faculty.id), {
        preserveScroll: true,
    });
};

// "08:00" -> "8:00 AM"
const to12Hour = (hhmm) => {
    if (!hhmm) return '';
    const [h, m] = hhmm.split(':').map(Number);
    return `${((h + 11) % 12) + 1}:${String(m).padStart(2, '0')} ${h >= 12 ? 'PM' : 'AM'}`;
};

const toMinutes = (hhmm) => {
    const [h, m] = (hhmm || '0:0').split(':').map(Number);
    return h * 60 + m;
};

// Read-only summary lines for the Information tab, e.g.
// "Mon, Tue, Wed, Thu, Fri · 8:00 AM – 12:00 PM".
const availabilitySummary = computed(() =>
    props.facultyAvailability.map((row) => ({
        days: row.days.join(', '),
        range: `${to12Hour(row.start_time)} – ${to12Hour(row.end_time)}`,
    })),
);

// Live weekly preview: one bar per class day across the school's class
// hours, with the available windows drawn as coloured blocks.
const availabilityPreview = computed(() => {
    const dayStart = toMinutes(props.schedulingWindow.start_time);
    const dayEnd = toMinutes(props.schedulingWindow.end_time);
    const span = Math.max(dayEnd - dayStart, 1);

    return availabilityDayOptions.value.map((day) => ({
        day,
        blocks: availabilityForm.availability
            .filter((row) => row.days.includes(day) && row.start_time && row.end_time && row.end_time > row.start_time)
            .map((row) => {
                const left = Math.max(toMinutes(row.start_time) - dayStart, 0);
                const right = Math.min(toMinutes(row.end_time) - dayStart, span);
                return {
                    left: (left / span) * 100,
                    width: Math.max(((right - left) / span) * 100, 0),
                    label: `${to12Hour(row.start_time)} – ${to12Hour(row.end_time)}`,
                };
            }),
    }));
});

const savingQualifications = ref(false);

// Groups the (already relevance-ordered — see FacultyController::show())
// subject list so the picker visually leads with what this faculty is
// most likely to teach: their own College's Major subjects first, then
// shared GenEd/Minor, then everyone else's Major subjects last.
const subjectGroups = computed(() => {
    const facultyCollegeId = props.faculty.college_id;
    const own = [];
    const shared = [];
    const other = [];

    for (const subject of props.subjects) {
        if (subject.category === 'Major' && subject.college_id === facultyCollegeId) {
            own.push(subject);
        } else if (subject.category === 'General Education' || subject.category === 'Minor') {
            shared.push(subject);
        } else {
            other.push(subject);
        }
    }

    const groups = [];
    if (own.length) {
        groups.push({
            label: props.faculty.college?.name
                ? `${props.faculty.college.name} (This Faculty's College)`
                : "This Faculty's College",
            subjects: own,
        });
    }
    if (shared.length) {
        groups.push({ label: 'General Education / Minor', subjects: shared });
    }
    if (other.length) {
        groups.push({ label: 'Other Colleges', subjects: other });
    }

    return groups;
});

watch(
    () => props.faculty.subjects,
    (subjects) => {
        selectedSubjectIds.value = (subjects ?? []).map((subject) => subject.id);
    },
);

const isQualificationsDirty = computed(() => {
    const current = new Set(selectedSubjectIds.value);
    const original = new Set((props.faculty.subjects ?? []).map((subject) => subject.id));
    if (current.size !== original.size) return true;
    for (const id of current) if (!original.has(id)) return true;
    return false;
});

const assignedSubjects = computed(() => {
    const bySubjectId = new Map(props.subjects.map((subject) => [subject.id, subject]));
    return selectedSubjectIds.value
        .map((id) => bySubjectId.get(id))
        .filter(Boolean)
        .sort((a, b) => a.subject_code.localeCompare(b.subject_code));
});

const removeSubject = (subjectId) => {
    selectedSubjectIds.value = selectedSubjectIds.value.filter((id) => id !== subjectId);
};

const saveQualifications = async () => {
    // Warn (don't block — cross-college Major assignments are a valid
    // use case, e.g. a faculty covering a course outside their home
    // College) whenever a newly-added subject is a Major subject that
    // belongs to a DIFFERENT College than this faculty's own. Only
    // checked against subjects being newly added this save, not ones
    // already on the faculty's record, so re-saving with no changes to
    // that slice never re-prompts.
    const originalIds = new Set((props.faculty.subjects ?? []).map((subject) => subject.id));
    const bySubjectId = new Map(props.subjects.map((subject) => [subject.id, subject]));
    const newlyAddedCrossCollegeMajors = selectedSubjectIds.value
        .filter((id) => !originalIds.has(id))
        .map((id) => bySubjectId.get(id))
        .filter((subject) => subject && subject.category === 'Major' && subject.college_id !== props.faculty.college_id);

    if (newlyAddedCrossCollegeMajors.length) {
        const list = newlyAddedCrossCollegeMajors
            .map((subject) => `<li><strong>${subject.subject_code}</strong> — ${subject.subject_title}</li>`)
            .join('');
        const result = await Swal.fire({
            icon: 'warning',
            title: 'Major subject from another College',
            html: `${newlyAddedCrossCollegeMajors.length > 1 ? 'These are Major subjects' : 'This is a Major subject'} of a different College/department than ${props.faculty.first_name ?? 'this faculty member'}'s own (${props.faculty.college?.name ?? 'no College'}):<ul class="text-left mt-2">${list}</ul>Assign anyway?`,
            showCancelButton: true,
            confirmButtonText: 'Assign Anyway',
            cancelButtonText: 'Cancel',
        });
        if (!result.isConfirmed) {
            return;
        }
    }

    savingQualifications.value = true;

    router.put(
        route('scheduling.teaching-qualifications.update', props.faculty.id),
        { subject_ids: selectedSubjectIds.value },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['faculty', 'flash'],
            onFinish: () => {
                savingQualifications.value = false;
            },
        },
    );
};

/* ------------------------------------------------------------------ */
/* Shared helpers (time formatting used by the Workload tab)           */
/* ------------------------------------------------------------------ */

const formatTime = (time) => {
    if (!time) return '—';
    const [hour, minute] = time.split(':').map(Number);
    const period = hour >= 12 ? 'PM' : 'AM';
    const displayHour = hour % 12 === 0 ? 12 : hour % 12;
    return `${displayHour}:${String(minute).padStart(2, '0')} ${period}`;
};

/* ------------------------------------------------------------------ */
/* Workload tab — FACULTY WORKLOAD VALIDATION SYSTEM.                  */
/* `faculty.workload` is computed server-side by FacultyWorkloadService */
/* (FacultyController@show) — the same engine Auto Generate/Recommend  */
/* Faculty/Manual Assignment/Save Schedule all use — so this tab can    */
/* never disagree with what the scheduling engine actually enforces.   */
/* ------------------------------------------------------------------ */

const workload = computed(() => props.faculty.workload ?? null);

const workloadPercent = computed(() => {
    if (!workload.value) return 0;
    return Math.min(100, Math.max(0, workload.value.percent ?? 0));
});

const workloadStatusMeta = computed(() => {
    switch (workload.value?.status) {
        case 'overloaded':
            return { emoji: '🔴', label: 'Overloaded', class: 'text-red-600 bg-red-50 border-red-200' };
        case 'high':
            return { emoji: '🟡', label: 'Approaching Limit', class: 'text-amber-600 bg-amber-50 border-amber-200' };
        default:
            return { emoji: '🟢', label: 'Healthy', class: 'text-emerald-600 bg-emerald-50 border-emerald-200' };
    }
});

// Kept for the "qualified subjects" reference figure still shown
// beneath the main workload cards (how many units this faculty member
// is qualified to teach in total, regardless of whether scheduled).
const qualifiedUnits = computed(() =>
    (props.faculty.subjects ?? []).reduce((sum, subject) => sum + Number(subject.units || 0), 0),
);

// The actual Subjects/Sections making up the "Current Load" number
// above — same 'Scheduled'/'Draft', active-semester placements,
// straight from FacultyWorkloadService::assignedPlacements() via
// FacultyController@show (evaluate(..., includePlacements: true)).
const assignedPlacements = computed(() => workload.value?.assigned_placements ?? []);

// Reuses the formatTime(time) helper declared above —
// same 'HH:mm'/'HH:mm:ss' -> 12-hour am/pm formatting applies here.

// SPLIT-DELIVERY SCHEDULING — assignedPlacements() now groups a
// Face-to-Face/Online split pair into ONE `data` row with a
// `data.schedules` array (one entry per delivery-mode row) instead of
// two separate top-level rows — see
// FacultyWorkloadService::assignedPlacements()'s docblock. The
// Schedule/Room/Actions columns below render one line per
// `data.schedules` entry so a split subject shows both its
// Face-to-Face and Online lines under a single EDP Code/Subject/Load,
// while a never-split subject (schedules.length === 1) looks exactly
// as it did before this change.
//
// startEditPlacement()/saveEditPlacement()/fetchTimeSuggestions()
// were all written against a flat placement object (id, section_id,
// subject_code, requires_lab, required_hours, room_id, room_name,
// days, start_time, end_time) — this merges a `data.schedules` entry
// (which only carries its OWN id/section_id/room/days/time) with the
// group-level fields those three functions also need, rather than
// changing their signatures.
const scheduleEditTarget = (data, schedule) => ({
    ...schedule,
    subject_code: data.subject_code,
    requires_lab: data.requires_lab,
    required_hours: data.required_hours,
});

const placementStatusSeverity = (status) => {
    switch (status) {
        case 'Scheduled':
            return 'success';
        case 'Draft':
            return 'warn';
        default:
            return 'secondary';
    }
};

/* ------------------------------------------------------------------ */
/* Assigned Subjects — inline Day/Time/Room editor.                    */
/*                                                                      */
/* Adviser request: this list should not be view-only — a change in a  */
/* faculty member's actual schedule should be editable right here,     */
/* with the same conflict detection the Room Grid / Subjects tab use,  */
/* instead of forcing a trip to the Section's own Subjects tab.        */
/*                                                                      */
/* Reuses scheduling.room-grid.move (SectionSubjectController::         */
/* moveRoomGridAssignment() -> performScheduleAssignmentUpdate()) —     */
/* the SAME write path + conflict checks as the Room Grid — rather      */
/* than a second, parallel one. That endpoint authorizes against the    */
/* schedule row's OWN Section (never a "currently open" Section), which */
/* is exactly right here since this page has no single Section in       */
/* context and different rows can belong to different Sections.         */
/* ------------------------------------------------------------------ */

const DAY_TOKENS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

const csrfToken = () => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : (document.querySelector('meta[name="csrf-token"]')?.content ?? '');
};

const editingPlacementId = ref(null);
const editForm = ref({ days: [], start_time: '', end_time: '', room_id: null });
const editSaving = ref(false);
const editErrors = ref({});
const editRoomOptions = ref([]);
const editRoomsLoading = ref(false);
const editTimeSuggestions = ref([]);
const editTimeSuggestionsLoading = ref(false);
const editTimeSuggestionsMessage = ref('');
// The Subject's real required weekly teaching hours (lecture +
// laboratory), captured when the editor opens — see startEditPlacement().
// Used to filter out RecommendationService::recommendSingleDaySlots()'s
// "fill in just ONE occurrence" candidates from this editor's
// suggestions, since those are meant to pair with the row's OTHER
// already-kept meeting day(s) (a different, partial-fix workflow —
// see RecommendedTimeModal.vue's docblock), not to stand alone as a
// complete weekly schedule. Left null when it can't be determined
// (e.g. the row wasn't fully scheduled yet), in which case every
// candidate is shown rather than risk hiding valid ones.
const editRequiredHours = ref(null);

// Warning keys the backend flags as "confirm to save anyway" rather
// than a hard block — see UpdateSectionSubjectScheduleRequest's
// docblock. A real Room/Faculty/Section/Time conflict comes back
// under a different key (room_id/faculty_id/days) and is never
// offered a retry, matching RoomGrid.vue's CONFIRMABLE_WARNING_KEYS.
const CONFIRMABLE_WARNING_KEYS = { room_type: 'room_type_confirmed', room_college: 'room_college_confirmed', hours: 'hours_confirmed' };

const minutesBetween = (start, end) => {
    const [sh, sm] = start.split(':').map(Number);
    const [eh, em] = end.split(':').map(Number);
    return (eh * 60 + em) - (sh * 60 + sm);
};

// A candidate's TOTAL weekly hours = (session length) x (how many
// days it meets) — must equal the Subject's required hours exactly,
// same check performSchedule AssignmentUpdate() runs server-side
// before allowing a save without an explicit hours_confirmed=true.
const totalsRequiredHours = (candidate) => {
    if (editRequiredHours.value == null) return true;
    const hours = (minutesBetween(candidate.start_time, candidate.end_time) * candidate.days.length) / 60;
    return Math.abs(hours - editRequiredHours.value) < 0.01;
};

// "Suggest Available Time" — reuses the exact same recommendation
// engine (RecommendationService::recommendTimes()/recommendSingleDaySlots())
// the Auto Generate review panel and Room Grid's own Day/Time editor
// use, via scheduling.section-subjects.time-recommendations. It scores
// candidate Day/Time slots against THIS faculty member's and THIS
// row's currently-saved Room's actual bookings, so anything it returns
// is already conflict-free — never a second, hand-rolled availability
// check that could disagree with what Save actually validates against.
//
// Only full, COMPLETE weekly patterns are surfaced here (see
// totalsRequiredHours() above) — this editor replaces the row's
// entire Day/Time in one shot, so a single leftover "Fri 2.5 hrs"
// option that only covers half of a 5-hr subject would silently
// under-schedule it if applied on its own.
const fetchTimeSuggestions = async (placement) => {
    if (!placement.section_id) return;

    editTimeSuggestionsLoading.value = true;
    editTimeSuggestions.value = [];
    editTimeSuggestionsMessage.value = '';

    try {
        const url = new URL(route('scheduling.section-subjects.time-recommendations', [placement.section_id, placement.id]));

        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        const data = await response.json();
        const complete = (data.recommendations ?? []).filter(totalsRequiredHours);
        editTimeSuggestions.value = complete.slice(0, 3);

        if (!editTimeSuggestions.value.length) {
            editTimeSuggestionsMessage.value = (data.recommendations ?? []).length
                ? `No open slot covers the full ${editRequiredHours.value} hrs/week this subject needs in this room right now.`
                : (data.message ?? 'No open slots found for this room right now.');
        }
    } catch (e) {
        editTimeSuggestionsMessage.value = 'Could not load suggestions. Please try again.';
    } finally {
        editTimeSuggestionsLoading.value = false;
    }
};

const applyTimeSuggestion = (suggestion) => {
    editForm.value.days = [...suggestion.days];
    editForm.value.start_time = suggestion.start_time;
    editForm.value.end_time = suggestion.end_time;
    editTimeSuggestions.value = [];
};

const startEditPlacement = async (placement) => {
    editingPlacementId.value = placement.id;
    editErrors.value = {};
    editTimeSuggestions.value = [];
    editTimeSuggestionsMessage.value = '';
    editRequiredHours.value = placement.required_hours ?? null;
    editForm.value = {
        days: placement.days ? placement.days.split(',').map((d) => d.trim()).filter(Boolean) : [],
        start_time: placement.start_time ? placement.start_time.slice(0, 5) : '',
        end_time: placement.end_time ? placement.end_time.slice(0, 5) : '',
        room_id: placement.room_id ?? null,
    };

    editRoomOptions.value = [];
    if (placement.section_id) {
        editRoomsLoading.value = true;
        try {
            const response = await fetch(route('scheduling.section-subjects.rooms', placement.section_id), {
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();
            // Same scope Room Grid's own sidebar uses (see RoomGrid.vue's
            // recommendedRooms) — only rooms belonging to this Section's
            // own Department/College/shared-pool, never the full,
            // unfiltered Room list.
            const recommended = data.recommended ?? [];

            // Same "type-matched first" grouping SectionSubjects/Show.vue's
            // own Room dropdown uses — a Major/lab subject should see
            // Laboratory rooms before plain Lecture rooms, not an
            // alphabetical mix of both.
            const wantsLaboratory = !!placement.requires_lab;
            const typeMatched = [];
            const others = [];
            recommended.forEach((room) => {
                (room.room_type === (wantsLaboratory ? 'Laboratory' : 'Lecture') ? typeMatched : others).push(room);
            });

            const groups = [];
            if (typeMatched.length) groups.push({ label: wantsLaboratory ? 'Laboratory Rooms' : 'Lecture Rooms', rooms: typeMatched });
            if (others.length) groups.push({ label: wantsLaboratory ? 'Other Rooms' : 'Laboratory Rooms', rooms: others });

            // If this row's CURRENT room isn't in the recommended set at
            // all (e.g. an earlier manual override), keep it in its own
            // group so the Select doesn't show blank for it.
            const hasCurrent = placement.room_id && recommended.some((r) => r.id === placement.room_id);
            if (placement.room_id && !hasCurrent) {
                groups.unshift({ label: 'Current Room', rooms: [{ id: placement.room_id, room_name: placement.room_name ?? 'Current room' }] });
            }

            editRoomOptions.value = groups;
        } catch (e) {
            // Room list failing to load isn't fatal — the Select just
            // shows the room currently assigned (if any) and the user
            // can retry by re-opening the editor.
        } finally {
            editRoomsLoading.value = false;
        }
    }
};

const cancelEditPlacement = () => {
    editingPlacementId.value = null;
    editErrors.value = {};
    editTimeSuggestions.value = [];
    editTimeSuggestionsMessage.value = '';
};

const saveEditPlacement = async (placement, confirmedKeys = {}) => {
    editSaving.value = true;
    editErrors.value = {};

    try {
        const response = await fetch(route('scheduling.room-grid.move', placement.id), {
            method: 'PATCH',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                days: editForm.value.days,
                start_time: editForm.value.start_time || null,
                end_time: editForm.value.end_time || null,
                room_id: editForm.value.room_id || null,
                ...confirmedKeys,
            }),
        });
        const data = await response.json();

        if (response.ok) {
            toast.add({ severity: 'success', summary: 'Schedule updated', detail: `${placement.subject_code}'s schedule was saved.`, life: 4000 });
            editingPlacementId.value = null;
            // Refreshes both the placements list and the Current
            // Load summary cards from the server — a Day/Time/Room
            // change doesn't move the Units number, but keeps this
            // page's copy of the row (and any other viewer's) exactly
            // in sync with what was just saved.
            router.reload({ only: ['faculty'] });
            return;
        }

        // Hard conflict (Room/Faculty/Section/Time already booked
        // elsewhere) OR a confirmable soft warning — both come back
        // as { errors: { key: message } }.
        if (data.errors) {
            const confirmableKey = Object.keys(data.errors).find((key) => CONFIRMABLE_WARNING_KEYS[key]);

            if (confirmableKey) {
                const result = await Swal.fire({
                    icon: 'warning',
                    title: 'Confirm this change',
                    text: data.errors[confirmableKey],
                    showCancelButton: true,
                    confirmButtonText: 'Save Anyway',
                    cancelButtonText: 'Cancel',
                });
                if (result.isConfirmed) {
                    return saveEditPlacement(placement, { ...confirmedKeys, [CONFIRMABLE_WARNING_KEYS[confirmableKey]]: true });
                }
                return;
            }

            // A real conflict — never silently overridable. Toast it
            // front-and-center (easy to miss the row itself if the
            // table has scrolled) AND show it right on the row so it's
            // obvious which field caused it.
            editErrors.value = data.errors;
            toast.add({
                severity: 'error',
                summary: 'Scheduling conflict',
                detail: Object.values(data.errors).join(' '),
                life: 8000,
            });
            return;
        }

        // Workload Limit warning (409) — only relevant if a Faculty
        // reassignment were allowed here, but the backend still runs
        // this check on every save, so it's handled defensively.
        if (data.workload_warning) {
            if (!data.can_override) {
                toast.add({ severity: 'error', summary: 'Cannot save', detail: data.message, life: 6000 });
                return;
            }
            const result = await Swal.fire({
                icon: 'warning',
                title: 'Conflict',
                text: data.message,
                showCancelButton: true,
                confirmButtonText: 'Proceed Anyway',
                cancelButtonText: 'Cancel',
            });
            if (result.isConfirmed) {
                return saveEditPlacement(placement, { ...confirmedKeys, workload_confirmed: true });
            }
            return;
        }

        toast.add({ severity: 'error', summary: 'Could not save', detail: data.message || 'Please try again.', life: 5000 });
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Network error', detail: 'Could not reach the server. Please try again.', life: 5000 });
    } finally {
        editSaving.value = false;
    }
};
</script>

<template>
    <Head :title="`${fullName} — Faculty Details`" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold text-[#1E293B]">Faculty Details</span>
        </template>

        <div class="w-full" :class="isDark ? 'dark-scope' : ''">
            <!-- Back link -->
            <div class="mb-4">
                <BackLink :href="route('scheduling.faculty')" label="Back to Faculty" />
            </div>

            <!-- Page Title -->
            <div class="neu-card neu-spotlight relative mb-6 overflow-hidden rounded-2xl">
                <div class="pointer-events-none absolute inset-0 overflow-hidden rounded-2xl">
                    <div class="absolute inset-0 bg-gradient-to-br from-blue-50 via-transparent to-red-50/60"></div>
                    <div class="absolute -right-10 -top-16 h-44 w-44 rounded-full bg-gradient-to-br from-blue-200/80 via-blue-100/40 to-transparent"></div>
                    <div class="absolute -bottom-20 right-16 h-40 w-40 rounded-full bg-gradient-to-tr from-red-200/70 via-red-100/30 to-transparent"></div>
                    <div class="absolute -left-10 bottom-0 h-28 w-28 rounded-full bg-gradient-to-tr border-4 from-blue-100/60 to-transparent border-blue-100"></div>
                    <div class="absolute left-[38%] top-0 h-3 w-3 rounded-full bg-gradient-to-br from-red-400/80 to-blue-300/60"></div>
                    <div class="absolute left-[55%] bottom-4 h-20 w-20 rounded-full bg-gradient-to-r blur-xl from-blue-200/40 to-red-200/40"></div>
                </div>
                <div class="relative rounded-2xl p-6 transition-colors duration-300">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight flex items-center gap-2 text-[#1E293B]">
                        {{ fullName }}
                        <InfoPopover
                            title="Faculty Details"
                            :paragraphs="[
                                'Manage this faculty member\'s profile, teaching qualifications, and current teaching workload.',
                            ]"
                            :bullets="[
                                'Teaching Qualifications control which subjects this faculty member can be assigned to when scheduling.',
                                'Workload shows units currently assigned against the Max Teaching Units cap set on the Information tab.',
                            ]"
                        />
                    </h1>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <span
                            class="inline-flex items-center rounded-lg px-2.5 py-1 text-xs font-bold tracking-wide"
                            :style="idBadgeStyle"
                        >{{ faculty.faculty_id }}</span>
                        <span
                            v-if="faculty.college?.name"
                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold text-slate-700"
                            :style="collegePillStyle"
                        >
                            <span class="h-1.5 w-1.5 rounded-full" :style="{ backgroundColor: collegeColorValue ?? '#94A3B8' }"></span>
                            {{ faculty.college.name }}
                        </span>
                        <span v-else class="inline-flex rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800">General Education</span>
                        <span
                            class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold"
                            :class="faculty.employment_type === 'Full-time' ? 'bg-blue-100 text-blue-700' : 'bg-violet-100 text-violet-700'"
                        >{{ faculty.employment_type }}</span>
                        <span
                            class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold"
                            :class="faculty.status === 'Active' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'"
                        >{{ faculty.status }}</span>
                    </div>
                    <div v-if="roleBadges.length" class="mt-2 flex flex-wrap gap-1.5">
                        <span
                            v-for="b in roleBadges"
                            :key="b.role"
                            class="inline-flex items-center gap-1 rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-semibold text-indigo-700"
                        >
                            <i class="pi pi-shield text-[10px]"></i>{{ b.label }}
                        </span>
                    </div>
                </div>
                </div>
            </div>

            <div class="neu-card rounded-2xl p-4 transition-colors duration-300">
            <Card
                class="!rounded-2xl !bg-transparent !border-0 !shadow-none"
                :pt="{ body: { class: '!bg-transparent !p-0' }, content: { class: '!p-0' } }"
            >
                <template #content>
                    <Tabs v-model:value="activeTab">
                        <TabList>
                            <Tab value="information">Information</Tab>
                            <Tab value="qualifications">Teaching Qualifications</Tab>
                            <Tab value="availability">Availability</Tab>
                            <Tab value="workload">Workload</Tab>
                        </TabList>

                        <TabPanels>
                            <!-- INFORMATION -->
                            <TabPanel value="information">
                                <div class="flex justify-end mb-4">
                                    <Button
                                        v-if="canEdit"
                                        label="Edit Information"
                                        icon="pi pi-pencil"
                                        severity="secondary"
                                        outlined
                                        @click="openEdit"
                                    />
                                    <Tag
                                        v-else
                                        value="View only — outside your College"
                                        severity="secondary"
                                    />
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Faculty ID</p>
                                        <p class="mt-1 text-slate-800 font-medium">{{ faculty.faculty_id }}</p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Full Name</p>
                                        <p class="mt-1 text-slate-800 font-medium">{{ fullName }}</p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Employment Type</p>
                                        <p class="mt-1.5">
                                            <span
                                                class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                                :class="faculty.employment_type === 'Full-time' ? 'bg-blue-100 text-blue-700' : 'bg-violet-100 text-violet-700'"
                                            >{{ faculty.employment_type }}</span>
                                        </p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">College</p>
                                        <p class="mt-1">
                                            <span
                                                v-if="faculty.college?.name"
                                                class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold text-slate-700"
                                                :style="collegePillStyle"
                                            >
                                                <span class="h-1.5 w-1.5 rounded-full" :style="{ backgroundColor: collegeColorValue ?? '#94A3B8' }"></span>
                                                {{ faculty.college.name }}
                                            </span>
                                            <span v-else class="inline-flex rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800">General Education</span>
                                        </p>
                                    </div>
                                    <div v-if="!faculty.college?.name" class="sm:col-span-2 flex items-start gap-2 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2.5">
                                        <i class="pi pi-info-circle text-amber-500 mt-0.5"></i>
                                        <p class="text-sm text-amber-700">
                                            This faculty member is not assigned to any specific college and
                                            may teach General Education subjects.
                                        </p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Max Teaching Units</p>
                                        <p class="mt-1 text-slate-800 font-medium">{{ faculty.max_teaching_units }}</p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Email</p>
                                        <p class="mt-1 text-slate-800 font-medium">{{ faculty.email || '—' }}</p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Contact Number</p>
                                        <p class="mt-1 text-slate-800 font-medium">{{ faculty.contact_number || '—' }}</p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4 sm:col-span-2 lg:col-span-3">
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Availability</p>
                                        <p v-if="!availabilitySummary.length" class="mt-1 text-slate-800 font-medium">Any time (no restriction)</p>
                                        <ul v-else class="mt-1 flex flex-col gap-0.5">
                                            <li v-for="(line, i) in availabilitySummary" :key="i" class="text-slate-800 font-medium">
                                                {{ line.days }} <span class="text-slate-400">&middot;</span> {{ line.range }}
                                            </li>
                                        </ul>
                                        <button type="button" class="mt-1 text-xs text-teal-600 hover:underline font-medium" @click="activeTab = 'availability'">
                                            {{ canEdit ? 'Change availability' : 'View availability' }}
                                        </button>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4 sm:col-span-2 lg:col-span-3">
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Remarks</p>
                                        <p class="mt-1 text-slate-800 font-medium whitespace-pre-line">{{ faculty.remarks || '—' }}</p>
                                    </div>
                                </div>
                            </TabPanel>

                            <!-- TEACHING QUALIFICATIONS -->
                            <TabPanel value="qualifications">
                                <label class="text-sm font-medium text-slate-700">Assign Subjects</label>
                                <MultiSelect
                                    ref="subjectMultiSelect"
                                    v-model="selectedSubjectIds"
                                    :options="subjectGroups"
                                    optionLabel="subject_code"
                                    optionValue="id"
                                    optionGroupLabel="label"
                                    optionGroupChildren="subjects"
                                    filter
                                    filterPlaceholder="Search subjects"
                                    display="chip"
                                    placeholder="Select subjects this faculty member can teach"
                                    class="w-full mt-1 neu-inset !border-none"
                                    :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                    @filter="onSubjectFilter"
                                >
                                    <template #optiongroup="{ option }">
                                        <span class="text-xs font-semibold tracking-wide text-slate-400 uppercase">{{ option.label }}</span>
                                    </template>
                                    <template #option="{ option }">
                                        <span class="font-medium">{{ option.subject_code }}</span>
                                        <span class="text-slate-400"> — {{ option.subject_title }}</span>
                                        <span v-if="option.other_qualified_faculty?.length" class="block text-[11px] text-sky-600">
                                            Also assigned to {{ option.other_qualified_faculty.join(', ') }}
                                        </span>
                                    </template>
                                </MultiSelect>

                                <div class="flex justify-end mt-3">
                                    <Button
                                        label="Save Qualifications"
                                        icon="pi pi-check"
                                        severity="success"
                                        :loading="savingQualifications"
                                        :disabled="!isQualificationsDirty"
                                        @click="saveQualifications"
                                    />
                                </div>

                                <DataTable
                                    :value="assignedSubjects"
                                    dataKey="id"
                                    class="neu-inset neu-table details-table w-full rounded-xl overflow-hidden mt-4"
                                    :class="isDark ? 'neu-table-dark' : ''"
                                    :rowStyle="subjectRowStyle"
                                    stripedRows
                                >
                                    <template #empty>
                                        <div class="text-center py-8">
                                            <p class="text-slate-500 font-medium text-sm">No subjects assigned yet.</p>
                                        </div>
                                    </template>
                                    <Column header="Subject Code" style="width: 10rem">
                                        <template #body="{ data }">
                                            <span class="inline-flex items-center justify-center whitespace-nowrap rounded-lg bg-slate-500 px-2.5 py-1 text-xs font-bold tracking-wide text-white">{{ data.subject_code }}</span>
                                        </template>
                                    </Column>
                                    <Column header="Subject Title" style="min-width: 16rem">
                                        <template #body="{ data }">
                                            <span class="text-[15px] font-semibold leading-snug text-[#1E293B]">{{ data.subject_title }}</span>
                                        </template>
                                    </Column>
                                    <Column header="Category" style="width: 11rem">
                                        <template #body="{ data }">
                                            <span class="inline-flex whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="data.category === 'Major' ? 'bg-blue-100 text-blue-700' : data.category === 'General Education' ? 'bg-sky-100 text-sky-700' : 'bg-violet-100 text-violet-700'">{{ data.category }}</span>
                                        </template>
                                    </Column>
                                    <Column header="Units" style="width: 7rem" headerClass="details-num" bodyClass="details-num">
                                        <template #body="{ data }"><span class="font-semibold text-[#1E293B]">{{ data.units }}</span></template>
                                    </Column>
                                    <Column header="Remove" style="width: 7rem" headerClass="details-num" bodyClass="details-num">
                                        <template #body="{ data }">
                                            <Button
                                                icon="pi pi-trash"
                                                text
                                                rounded
                                                severity="danger"
                                                size="small"
                                                aria-label="Remove"
                                                @click="removeSubject(data.id)"
                                            />
                                        </template>
                                    </Column>
                                </DataTable>

                                <p v-if="isQualificationsDirty" class="text-xs text-amber-600 mt-2">
                                    You have unsaved changes — click "Save Qualifications" to apply them.
                                </p>
                            </TabPanel>

                            <!-- AVAILABILITY -->
                            <TabPanel value="availability">
                                <!-- Mode card: Any time vs Set times only -->
                                <div class="neu-inset flex flex-wrap items-center justify-between gap-4 rounded-xl p-4">
                                    <div class="flex min-w-0 flex-1 basis-96 items-start gap-3">
                                        <span class="mt-0.5 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl" :class="hasAvailabilityLimit ? 'bg-teal-100 text-teal-700' : 'bg-emerald-100 text-emerald-700'">
                                            <i class="pi" :class="hasAvailabilityLimit ? 'pi-clock' : 'pi-check-circle'"></i>
                                        </span>
                                        <div class="min-w-0">
                                            <p class="font-semibold text-[#1E293B]">Teaching Availability</p>
                                            <p class="mt-0.5 text-sm text-slate-500">
                                                Days and times this faculty member can teach (e.g. a part-timer who is only free in the
                                                morning). Auto Generate and manual scheduling only use these times. Class hours in Term
                                                Setup are
                                                <span class="font-semibold text-slate-600">{{ to12Hour(classStart) }} – {{ to12Hour(classEnd) }}</span>;
                                                anything outside is adjusted automatically.
                                            </p>
                                        </div>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-3 rounded-full px-3 py-1.5" :class="hasAvailabilityLimit ? 'bg-teal-50' : 'bg-emerald-50'">
                                        <span class="text-xs font-semibold" :class="hasAvailabilityLimit ? 'text-teal-700' : 'text-emerald-700'">{{ hasAvailabilityLimit ? 'Set times only' : 'Any time' }}</span>
                                        <ToggleSwitch v-model="hasAvailabilityLimit" :disabled="!canEdit" />
                                    </div>
                                </div>

                                <div v-if="hasAvailabilityLimit" class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-5">
                                    <!-- Time windows -->
                                    <div class="flex flex-col gap-3 xl:col-span-3">
                                        <div
                                            v-for="(row, index) in availabilityForm.availability"
                                            :key="index"
                                            class="neu-inset flex flex-col gap-3 rounded-xl border-l-4 border-teal-500 p-4"
                                        >
                                            <div class="flex items-center justify-between">
                                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Time window {{ index + 1 }}</p>
                                                <Button
                                                    v-if="canEdit"
                                                    icon="pi pi-trash"
                                                    severity="danger"
                                                    text
                                                    rounded
                                                    size="small"
                                                    aria-label="Remove time window"
                                                    @click="removeAvailabilityWindow(index)"
                                                />
                                            </div>
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <button
                                                    v-for="day in availabilityDayOptions"
                                                    :key="day"
                                                    type="button"
                                                    :disabled="!canEdit"
                                                    class="rounded-full border px-3.5 py-1 text-xs font-semibold transition-colors"
                                                    :class="row.days.includes(day)
                                                        ? 'bg-teal-600 border-teal-600 text-white'
                                                        : 'bg-white border-slate-300 text-slate-500 hover:border-teal-400'"
                                                    @click="toggleAvailabilityDay(row, day)"
                                                >
                                                    {{ day }}
                                                </button>
                                            </div>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <input v-model="row.start_time" type="time" :min="classStart" :max="classEnd" :disabled="!canEdit" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-800" @change="clampRow(row)" />
                                                <span class="text-sm text-slate-400">to</span>
                                                <input v-model="row.end_time" type="time" :min="classStart" :max="classEnd" :disabled="!canEdit" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-800" @change="clampRow(row)" />
                                            </div>
                                            <small v-if="availabilityForm.errors[`availability.${index}.days`]" class="text-red-500">Pick at least one day.</small>
                                            <small v-if="availabilityForm.errors[`availability.${index}.end_time`]" class="text-red-500">End time must be after the start time.</small>
                                            <small v-if="availabilityForm.errors[`availability.${index}.start_time`]" class="text-red-500">Enter a valid start time.</small>
                                        </div>

                                        <div v-if="canEdit" class="flex flex-wrap items-center gap-3">
                                            <Button label="Add another time window" icon="pi pi-plus" size="small" severity="secondary" outlined @click="addAvailabilityWindow" />
                                            <p class="text-xs text-slate-400">
                                                Only needed if the schedule differs by day (e.g. Mon/Wed/Fri mornings, Tue/Thu afternoons).
                                            </p>
                                        </div>
                                    </div>

                                    <!-- Weekly preview -->
                                    <div class="neu-inset rounded-xl p-4 xl:col-span-2">
                                        <p class="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-400">Weekly preview</p>
                                        <div class="flex flex-col gap-2">
                                            <div v-for="item in availabilityPreview" :key="item.day" class="flex items-center gap-3">
                                                <span class="w-10 text-xs font-semibold text-slate-500">{{ item.day }}</span>
                                                <div class="relative h-6 flex-1 overflow-hidden rounded-md bg-slate-200/70">
                                                    <div
                                                        v-for="(block, i) in item.blocks"
                                                        :key="i"
                                                        class="absolute top-0 h-6 rounded-md bg-teal-500/80"
                                                        :style="{ left: block.left + '%', width: block.width + '%' }"
                                                        :title="block.label"
                                                    ></div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="mt-2 flex items-center justify-between pl-[3.25rem] text-[11px] text-slate-400">
                                            <span>{{ to12Hour(schedulingWindow.start_time) }}</span>
                                            <span>{{ to12Hour(schedulingWindow.end_time) }}</span>
                                        </div>
                                        <div class="mt-3 flex items-center gap-4 text-xs text-slate-500">
                                            <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded bg-teal-500/80"></span>Available</span>
                                            <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded bg-slate-300"></span>Not available</span>
                                        </div>
                                    </div>
                                </div>
                                <div v-else class="mt-4 flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                                    <i class="pi pi-check-circle"></i>
                                    No restriction. This faculty member can be scheduled any time within the school's class hours.
                                </div>

                                <small v-if="availabilityForm.errors.availability" class="mt-2 block text-red-500">{{ availabilityForm.errors.availability }}</small>

                                <div class="mt-4 flex items-center justify-end gap-3">
                                    <p v-if="isAvailabilityDirty" class="text-xs text-amber-600">You have unsaved availability changes.</p>
                                    <Button
                                        v-if="canEdit"
                                        label="Save Availability"
                                        icon="pi pi-check"
                                        severity="success"
                                        :loading="availabilityForm.processing"
                                        :disabled="!isAvailabilityDirty"
                                        @click="saveAvailability"
                                    />
                                    <Tag v-else value="View only — outside your College" severity="secondary" />
                                </div>
                            </TabPanel>

                            <!-- WORKLOAD -->
                            <TabPanel value="workload">
                                <div class="mb-4 flex items-center justify-end gap-2">
                                    <Button
                                        label="Print"
                                        icon="pi pi-print"
                                        severity="secondary"
                                        outlined
                                        :disabled="!scheduleMeta.academic_year"
                                        title="Print this faculty member's schedule (Schedule by Faculty report)."
                                        @click="printFacultySchedule"
                                    />
                                    <Button
                                        label="Send via Email"
                                        icon="pi pi-send"
                                        severity="success"
                                        :disabled="!scheduleMeta.academic_year"
                                        @click="showSendScheduleModal = true"
                                    />
                                </div>
                                <div
                                    class="mb-6 flex items-center justify-between rounded-xl p-4 neu-inset"
                                    :class="workloadStatusMeta.class"
                                >
                                    <div>
                                        <p class="text-xs font-semibold tracking-wide uppercase opacity-70">Current Load</p>
                                        <p class="mt-1 text-2xl font-bold">
                                            {{ workload?.current ?? 0 }} / {{ workload?.max ?? 0 }} {{ workload?.unit_label ?? 'Units' }}
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-3xl leading-none">{{ workloadStatusMeta.emoji }}</p>
                                        <p class="mt-1 text-sm font-semibold">{{ workloadStatusMeta.label }} — {{ workloadPercent }}%</p>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wide text-slate-400 uppercase">Maximum Load</p>
                                        <p class="mt-1 text-2xl font-bold text-[#1E293B]">{{ workload?.max ?? 0 }}</p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wide text-slate-400 uppercase">Current Assigned</p>
                                        <p class="mt-1 text-2xl font-bold text-[#1E293B]">{{ workload?.current ?? 0 }}</p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wide text-slate-400 uppercase">Remaining</p>
                                        <p
                                            class="mt-1 text-2xl font-bold"
                                            :class="(workload?.remaining ?? 0) < 0 ? 'text-red-600' : 'text-[#1E293B]'"
                                        >
                                            {{ workload?.remaining ?? 0 }}
                                        </p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wide text-slate-400 uppercase">Assigned Subjects</p>
                                        <p class="mt-1 text-2xl font-bold text-[#1E293B]">{{ workload?.assigned_subjects ?? 0 }}</p>
                                    </div>
                                </div>

                                <ProgressBar
                                    :value="workloadPercent"
                                    :showValue="false"
                                    class="mb-6 h-2"
                                    :pt="{
                                        value: {
                                            class:
                                                workload?.status === 'overloaded'
                                                    ? '!bg-red-500'
                                                    : workload?.status === 'high'
                                                      ? '!bg-amber-500'
                                                      : '!bg-emerald-500',
                                        },
                                    }"
                                />

                                <p class="text-xs text-slate-400">
                                    <i class="pi pi-info-circle mr-1"></i>
                                    Current Load reflects every 'Scheduled' or 'Draft' subject this faculty member is actually
                                    assigned to teach in the active semester (Auto Generate + manually saved schedules alike),
                                    measured in {{ workload?.unit_label ?? 'Units' }} — the same figure Auto Generate Schedule,
                                    Recommend Faculty, Manual Assignment, and Save Schedule all validate against. Reference:
                                    qualified to teach {{ qualifiedUnits }} unit(s) worth of subjects in total (Teaching
                                    Qualifications tab).
                                </p>

                                <!-- Assigned Subjects list — what actually makes up Current Load -->
                                <div class="mt-8">
                                    <div class="mb-3 flex items-center justify-between gap-3">
                                        <h3 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">
                                            Assigned Subjects — {{ assignedPlacements.length }}
                                        </h3>
                                        <div v-if="assignedPlacements.length" class="flex gap-1 rounded-xl p-1 neu-inset">
                                            <button type="button" class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors" :class="workloadView === 'table' ? 'bg-emerald-500 text-white' : (isDark ? 'text-slate-400' : 'text-slate-500')" @click="workloadView = 'table'">Table</button>
                                            <button type="button" class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors" :class="workloadView === 'grid' ? 'bg-emerald-500 text-white' : (isDark ? 'text-slate-400' : 'text-slate-500')" @click="workloadView = 'grid'">Grid</button>
                                        </div>
                                    </div>

                                    <DataTable
                                        v-if="assignedPlacements.length && workloadView === 'table'"
                                        :value="assignedPlacements"
                                        dataKey="id"
                                        class="text-sm neu-inset neu-table details-table w-full rounded-xl overflow-hidden"
                                        :class="isDark ? 'neu-table-dark' : ''"
                                        stripedRows
                                    >
                                        <Column field="edp_code" header="EDP Code">
                                            <template #body="{ data }">
                                                <span class="font-mono text-xs text-slate-600">{{ data.edp_code || '—' }}</span>
                                            </template>
                                        </Column>
                                        <Column field="subject_code" header="Subject">
                                            <template #body="{ data }">
                                                <div class="font-semibold text-[#1E293B]">{{ data.subject_code }}</div>
                                                <div class="text-xs text-slate-500">{{ data.subject_title }}</div>
                                            </template>
                                        </Column>
                                        <Column field="section_code" header="Section">
                                            <template #body="{ data }">{{ data.section_code || '—' }}</template>
                                        </Column>
                                        <Column header="Schedule">
                                            <template #body="{ data }">
                                                <div class="flex flex-col gap-2.5">
                                                    <div v-for="schedule in data.schedules" :key="schedule.id">
                                                        <div v-if="editingPlacementId === schedule.id" class="flex flex-col gap-1.5">
                                                            <MultiSelect
                                                                v-model="editForm.days"
                                                                :options="DAY_TOKENS"
                                                                placeholder="Days"
                                                                class="w-full text-xs"
                                                                display="chip"
                                                            />
                                                            <div class="flex items-center gap-1.5">
                                                                <input v-model="editForm.start_time" type="time" class="w-full rounded border border-slate-300 px-2 py-1 text-xs" />
                                                                <span class="text-slate-400">–</span>
                                                                <input v-model="editForm.end_time" type="time" class="w-full rounded border border-slate-300 px-2 py-1 text-xs" />
                                                            </div>
                                                            <small v-if="editErrors.days" class="text-red-600">{{ editErrors.days }}</small>
                                                            <small v-if="editErrors.start_time" class="text-red-600">{{ editErrors.start_time }}</small>
                                                            <small v-if="editErrors.end_time" class="text-red-600">{{ editErrors.end_time }}</small>
                                                            <small v-if="editErrors.hours" class="text-red-600">{{ editErrors.hours }}</small>

                                                            <Button
                                                                label="Suggest Available Time"
                                                                icon="pi pi-sparkles"
                                                                size="small"
                                                                text
                                                                :loading="editTimeSuggestionsLoading"
                                                                class="!text-xs !p-0 !justify-start"
                                                                @click="fetchTimeSuggestions(scheduleEditTarget(data, schedule))"
                                                            />
                                                            <div v-if="editTimeSuggestions.length" class="flex flex-col gap-1">
                                                                <button
                                                                    v-for="(s, idx) in editTimeSuggestions"
                                                                    :key="idx"
                                                                    type="button"
                                                                    class="rounded border border-emerald-200 bg-emerald-50 px-2 py-1 text-left text-xs text-emerald-700 hover:bg-emerald-100"
                                                                    @click="applyTimeSuggestion(s)"
                                                                >
                                                                    {{ s.days.join(', ') }} &middot; {{ formatTime(s.start_time) }}–{{ formatTime(s.end_time) }}
                                                                </button>
                                                            </div>
                                                            <small v-else-if="editTimeSuggestionsMessage" class="text-slate-400">{{ editTimeSuggestionsMessage }}</small>
                                                        </div>
                                                        <span v-else-if="schedule.days">
                                                            {{ schedule.days }} &middot; {{ formatTime(schedule.start_time) }}–{{ formatTime(schedule.end_time) }}
                                                            <span
                                                                v-if="schedule.shared_with_section"
                                                                class="ml-1 inline-block rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700"
                                                                :title="`Shared Online session — one Faculty covering both this Section and ${schedule.shared_with_section}`"
                                                            >
                                                                Shared with {{ schedule.shared_with_section }}
                                                            </span>
                                                        </span>
                                                        <span v-else class="text-slate-400">Not yet scheduled</span>
                                                    </div>
                                                </div>
                                            </template>
                                        </Column>
                                        <Column field="room_name" header="Room">
                                            <template #body="{ data }">
                                                <div class="flex flex-col gap-2.5">
                                                    <div v-for="schedule in data.schedules" :key="schedule.id">
                                                        <div v-if="editingPlacementId === schedule.id" class="flex flex-col gap-1.5">
                                                            <Select
                                                                v-model="editForm.room_id"
                                                                :options="editRoomOptions"
                                                                optionLabel="room_name"
                                                                optionValue="id"
                                                                optionGroupLabel="label"
                                                                optionGroupChildren="rooms"
                                                                :loading="editRoomsLoading"
                                                                placeholder="Select room"
                                                                showClear
                                                                class="w-full text-xs"
                                                            >
                                                                <template #optiongroup="{ option }">
                                                                    <span class="text-xs font-semibold tracking-wide text-slate-400 uppercase">{{ option.label }}</span>
                                                                </template>
                                                            </Select>
                                                            <small v-if="editErrors.room_id" class="text-red-600">{{ editErrors.room_id }}</small>
                                                            <small v-if="editErrors.room_type" class="text-red-600">{{ editErrors.room_type }}</small>
                                                            <small v-if="editErrors.room_college" class="text-red-600">{{ editErrors.room_college }}</small>
                                                        </div>
                                                        <span v-else>{{ schedule.room_name || '—' }}</span>
                                                    </div>
                                                </div>
                                            </template>
                                        </Column>
                                        <Column header="Load">
                                            <template #body="{ data }">{{ data.load }} {{ workload?.unit_label ?? 'Units' }}</template>
                                        </Column>
                                        <Column field="status" header="Status">
                                            <template #body="{ data }">
                                                <Tag :value="data.status" :severity="placementStatusSeverity(data.status)" />
                                            </template>
                                        </Column>
                                        <Column header="Actions" style="width: 6rem">
                                            <template #body="{ data }">
                                                <div class="flex flex-col gap-2.5">
                                                    <div v-for="schedule in data.schedules" :key="schedule.id">
                                                        <div v-if="editingPlacementId === schedule.id" class="flex items-center gap-1">
                                                            <Button
                                                                icon="pi pi-check"
                                                                severity="success"
                                                                text
                                                                rounded
                                                                :loading="editSaving"
                                                                @click="saveEditPlacement(scheduleEditTarget(data, schedule))"
                                                            />
                                                            <Button icon="pi pi-times" severity="secondary" text rounded :disabled="editSaving" @click="cancelEditPlacement" />
                                                        </div>
                                                        <Button
                                                            v-else-if="canEdit"
                                                            icon="pi pi-pencil"
                                                            text
                                                            rounded
                                                            aria-label="Edit schedule"
                                                            @click="startEditPlacement(scheduleEditTarget(data, schedule))"
                                                        />
                                                    </div>
                                                </div>
                                            </template>
                                        </Column>
                                    </DataTable>

                                    <!-- Grid view — weekly timetable, blocks colored by College. -->
                                    <FacultyWeekGrid
                                        v-else-if="assignedPlacements.length"
                                        :placements="assignedPlacements"
                                        :scheduling-window="schedulingWindow"
                                        :is-dark="isDark"
                                    />

                                    <div
                                        v-else
                                        class="rounded-xl border border-dashed border-slate-200 p-6 text-center text-sm text-slate-400"
                                    >
                                        No subjects assigned to this faculty member in the active semester yet.
                                    </div>
                                </div>
                            </TabPanel>
                        </TabPanels>
                    </Tabs>
                </template>
            </Card>
            </div>
        </div>

        <!-- Edit Faculty Information Dialog -->
        <Dialog
            v-model:visible="editVisible"
            modal
            header="Edit Faculty"
            :style="{ width: '760px' }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            :pt="{
                root: { class: isDark ? '!bg-[#141D33] !border !border-white/10 !text-white !rounded-2xl !shadow-2xl dark-scope' : '!border !border-[rgba(30,41,59,0.06)] !rounded-2xl !shadow-2xl' },
                header: { class: isDark ? '!bg-[#141D33] !border-b !border-white/10 !rounded-t-2xl' : '!rounded-t-2xl' },
                content: { class: isDark ? '!bg-[#141D33]' : '' },
                footer: { class: isDark ? '!bg-[#141D33] !border-t !border-white/10 !rounded-b-2xl' : '!rounded-b-2xl' },
            }"
            @hide="closeEdit"
        >
            <form class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4 neu-form" @submit.prevent="onSaveFaculty">
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Faculty ID <span class="text-red-500">*</span></label>
                    <InputText v-uppercase v-model="facultyForm.faculty_id" :invalid="!!facultyForm.errors.faculty_id" class="w-full" />
                    <small v-if="facultyForm.errors.faculty_id" class="text-red-500">{{ facultyForm.errors.faculty_id }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Employment Type <span class="text-red-500">*</span></label>
                    <Select
                        v-model="facultyForm.employment_type"
                        :options="employmentTypeOptions"
                        placeholder="Select employment type"
                        :invalid="!!facultyForm.errors.employment_type"
                        class="w-full"
                        :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                    />
                    <small v-if="facultyForm.errors.employment_type" class="text-red-500">{{ facultyForm.errors.employment_type }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">First Name <span class="text-red-500">*</span></label>
                    <InputText v-uppercase v-model="facultyForm.first_name" :invalid="!!facultyForm.errors.first_name" class="w-full" />
                    <small v-if="facultyForm.errors.first_name" class="text-red-500">{{ facultyForm.errors.first_name }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Middle Name</label>
                    <InputText v-uppercase v-model="facultyForm.middle_name" :invalid="!!facultyForm.errors.middle_name" class="w-full" />
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Last Name <span class="text-red-500">*</span></label>
                    <InputText v-uppercase v-model="facultyForm.last_name" :invalid="!!facultyForm.errors.last_name" class="w-full" />
                    <small v-if="facultyForm.errors.last_name" class="text-red-500">{{ facultyForm.errors.last_name }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Suffix</label>
                    <InputText v-uppercase v-model="facultyForm.suffix" :invalid="!!facultyForm.errors.suffix" class="w-full" />
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">College</label>
                    <Select
                        v-model="facultyForm.college_id"
                        :options="colleges"
                        optionLabel="name"
                        optionValue="id"
                        placeholder="Leave blank for General Education"
                        showClear
                        :invalid="!!facultyForm.errors.college_id"
                        class="w-full"
                        :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                    />
                    <small v-if="facultyForm.errors.college_id" class="text-red-500">{{ facultyForm.errors.college_id }}</small>
                    <p v-else class="text-xs text-slate-400">
                        Leave blank if this faculty member is General Education (no department).
                    </p>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Maximum Teaching Units <span class="text-red-500">*</span></label>
                    <InputNumber
                        v-model="facultyForm.max_teaching_units"
                        :min="0"
                        :max="hardCapUnits"
                        :disabled="!canChangeMaxLoad"
                        showButtons
                        buttonLayout="horizontal"
                        :invalid="!!facultyForm.errors.max_teaching_units"
                        class="w-full"
                        inputClass="w-full"
                    />
                    <small v-if="facultyForm.errors.max_teaching_units" class="text-red-500">{{ facultyForm.errors.max_teaching_units }}</small>
                    <!-- Only Admin/Registrar may raise this ceiling directly, same as the Faculty Master edit modal. -->
                    <p v-if="!canChangeMaxLoad" class="text-xs text-slate-400">
                        Only Admin/Registrar can change this directly.
                        <Link :href="route('scheduling.faculty')" class="text-teal-600 hover:underline font-medium">
                            Request a load increase
                        </Link>
                    </p>
                    <p v-else class="text-xs text-slate-400">Current teaching load ceiling: {{ hardCapUnits }} units.</p>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Workload Measurement</label>
                    <Select
                        v-model="facultyForm.workload_type"
                        :options="workloadTypeOptions"
                        optionLabel="label"
                        optionValue="value"
                        :invalid="!!facultyForm.errors.workload_type"
                        class="w-full"
                        :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                    />
                    <p class="text-xs text-slate-400">Whichever the institution uses to cap this faculty member's load.</p>
                </div>

                <div v-if="facultyForm.workload_type === 'hours'" class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Maximum Weekly Hours</label>
                    <InputNumber
                        v-model="facultyForm.max_weekly_hours"
                        :min="0"
                        showButtons
                        buttonLayout="horizontal"
                        :invalid="!!facultyForm.errors.max_weekly_hours"
                        class="w-full"
                        inputClass="w-full"
                    />
                    <small v-if="facultyForm.errors.max_weekly_hours" class="text-red-500">{{ facultyForm.errors.max_weekly_hours }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Status <span class="text-red-500">*</span></label>
                    <Select
                        v-model="facultyForm.status"
                        :options="statusOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select status"
                        :invalid="!!facultyForm.errors.status"
                        class="w-full"
                        :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                    />
                    <small v-if="facultyForm.errors.status" class="text-red-500">{{ facultyForm.errors.status }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Email</label>
                    <InputText v-model="facultyForm.email" type="email" :invalid="!!facultyForm.errors.email" class="w-full" />
                    <small v-if="facultyForm.errors.email" class="text-red-500">{{ facultyForm.errors.email }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Contact Number</label>
                    <InputText v-uppercase v-model="facultyForm.contact_number" :invalid="!!facultyForm.errors.contact_number" class="w-full" />
                </div>

                <div class="flex flex-col gap-1 sm:col-span-2">
                    <label class="text-sm font-medium text-slate-700">Remarks</label>
                    <Textarea v-model="facultyForm.remarks" autoResize rows="3" :invalid="!!facultyForm.errors.remarks" class="w-full" />
                </div>

                <!-- SYSTEM ROLES — Administrator only -->
                <div v-if="systemRoles.canManage" class="sm:col-span-2 rounded-xl border border-slate-200 p-4">
                    <p class="text-sm font-semibold text-slate-700">System roles</p>
                    <p class="mb-3 text-xs text-slate-400">
                        Make this person a Registrar, Dean, OIC or Assistant Dean too. They stay faculty and keep a “My Schedule” page.
                    </p>

                    <p v-if="systemRoles.isAdministratorLogin" class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">
                        This person's login is an Administrator account — manage its roles from Users instead.
                    </p>
                    <template v-else>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <label
                                v-for="role in systemRoles.assignable"
                                :key="role"
                                class="flex cursor-pointer items-start gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm"
                            >
                                <input
                                    type="checkbox"
                                    class="mt-1 accent-emerald-500"
                                    :checked="facultyForm.system_roles.includes(role)"
                                    @change="toggleSystemRole(role, $event.target.checked)"
                                />
                                <span>
                                    <span class="block font-medium text-slate-700">{{ role }}</span>
                                    <span class="block text-xs text-slate-400">{{ ROLE_HINTS[role] }}</span>
                                </span>
                            </label>
                        </div>
                        <small v-if="facultyForm.errors.system_roles" class="mt-2 block text-red-500">{{ facultyForm.errors.system_roles }}</small>

                        <div v-if="needsRoleCollege" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div class="flex flex-col gap-1">
                                <label class="text-sm font-medium text-slate-700">College they lead <span class="text-red-500">*</span></label>
                                <Select
                                    v-model="facultyForm.role_college_id"
                                    :options="colleges"
                                    optionLabel="name"
                                    optionValue="id"
                                    placeholder="Select college"
                                    :invalid="!!facultyForm.errors.role_college_id"
                                    class="w-full"
                                    :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                />
                                <small v-if="facultyForm.errors.role_college_id" class="text-red-500">{{ facultyForm.errors.role_college_id }}</small>
                            </div>

                            <div v-if="isOicSelected" class="flex flex-col gap-1">
                                <label class="text-sm font-medium text-slate-700">Departments</label>
                                <label class="flex items-center gap-2 text-sm text-slate-600">
                                    <ToggleSwitch v-model="facultyForm.role_oversees_all_departments" />
                                    All departments in the college
                                </label>
                                <MultiSelect
                                    v-if="!facultyForm.role_oversees_all_departments"
                                    v-model="facultyForm.role_department_ids"
                                    :options="roleDepartments"
                                    optionLabel="name"
                                    optionValue="id"
                                    placeholder="Select departments"
                                    :invalid="!!facultyForm.errors.role_department_ids"
                                    class="w-full"
                                    :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                />
                                <small v-if="facultyForm.errors.role_department_ids" class="text-red-500">{{ facultyForm.errors.role_department_ids }}</small>
                            </div>
                        </div>

                        <p class="mt-3 text-xs text-slate-400"><i class="pi pi-info-circle mr-1"></i>{{ loginNote }}</p>
                    </template>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="closeEdit" />
                <Button label="Save Changes" icon="pi pi-check" severity="success" :loading="facultyForm.processing" @click="onSaveFaculty" />
            </template>
        </Dialog>

        <SendScheduleModal
            :show="showSendScheduleModal"
            :faculty="sendScheduleFaculty"
            :academic-term="sendScheduleTerm"
            :is-finalized="scheduleMeta.is_finalized"
            @close="showSendScheduleModal = false"
            @sent="showSendScheduleModal = false"
        />

    </AppLayout>
</template>

<style scoped>
.details-table :deep(.p-datatable-tbody > tr > td) {
    padding-top: 0.85rem;
    padding-bottom: 0.85rem;
}
.details-table :deep(th.details-num),
.details-table :deep(td.details-num) {
    text-align: center;
}
.details-table :deep(th.details-num .p-datatable-column-header-content) {
    justify-content: center;
}

/* Dark-mode overrides. Wrapping an element in the "dark-scope" class
   (added conditionally via isDark) recolors the shared light-mode
   Tailwind utility classes and PrimeVue component chrome used
   throughout this page. Dialogs and overlay panels (Select/MultiSelect/
   DatePicker) are teleported to <body> by PrimeVue, so their rules use
   :global() with a compound selector instead of :deep() — Vue's
   scoped-CSS attribute doesn't reliably travel through the teleport
   boundary. */
.dark-scope :deep(.text-\[\#1E293B\]) { color: #F8FAFC !important; }
.dark-scope :deep(.text-slate-700) { color: #CBD5E1 !important; }
.dark-scope :deep(.text-slate-600) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-500) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-400) { color: #64748B !important; }
.dark-scope :deep(.border-slate-100) { border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.border-slate-200) { border-color: rgba(255, 255, 255, 0.12) !important; }

.dark-scope :deep(.p-card) { background: #101A35 !important; color: #F8FAFC; border: 1px solid rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.p-card .p-card-body) { background: transparent !important; }

.dark-scope :deep(.p-tablist) { background: transparent !important; border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.p-tabpanels) { background: transparent !important; color: #F1F5F9 !important; padding: 0 !important; }
.dark-scope :deep(.p-tabpanel) { background: transparent !important; color: #F1F5F9 !important; }
.dark-scope :deep(.p-tab) { color: #94A3B8 !important; }
.dark-scope :deep(.p-tab-active) { color: #F8FAFC !important; }

.dark-scope :deep(.p-inputtext),
.dark-scope :deep(.p-textarea),
.dark-scope :deep(.p-select),
.dark-scope :deep(.p-multiselect),
.dark-scope :deep(.p-inputnumber-input) {
    background: rgba(255, 255, 255, 0.06) !important;
    border-color: rgba(255, 255, 255, 0.15) !important;
    color: #F8FAFC !important;
}
.dark-scope :deep(.p-inputtext::placeholder),
.dark-scope :deep(.p-textarea::placeholder) { color: #7C8CA8 !important; }
.dark-scope :deep(.p-select-label),
.dark-scope :deep(.p-multiselect-label) { color: #F8FAFC !important; }
.dark-scope :deep(.p-multiselect-label.p-placeholder) { color: #7C8CA8 !important; }
.dark-scope :deep(.p-inputnumber-button) { background: rgba(255, 255, 255, 0.06) !important; border-color: rgba(255, 255, 255, 0.15) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-checkbox .p-checkbox-box) { background: rgba(255, 255, 255, 0.06) !important; border-color: rgba(255, 255, 255, 0.25) !important; }
.dark-scope :deep(.p-progressbar) { background: rgba(255, 255, 255, 0.08) !important; }

.dark-scope :deep(.p-datatable) { background: transparent !important; color: #F1F5F9 !important; }
.dark-scope :deep(.p-datatable-thead > tr > th) { background: rgba(255, 255, 255, 0.06) !important; color: #F1F5F9 !important; border-color: rgba(255, 255, 255, 0.12) !important; font-weight: 600; }
.dark-scope :deep(.p-datatable-tbody > tr) { background: transparent !important; color: #F1F5F9 !important; }
.dark-scope :deep(.p-datatable-tbody > tr > td) { color: #F1F5F9 !important; border-color: rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.p-datatable-tbody > tr:hover) { background: rgba(255, 255, 255, 0.07) !important; }
.dark-scope :deep(.p-datatable-tbody > tr:hover > td) { background: transparent !important; }
.dark-scope :deep(.p-datatable-emptymessage) { color: #CBD5E1 !important; }

.dark-scope :deep(.p-button-text.p-button-secondary) { color: #CBD5E1 !important; }
.dark-scope :deep(.p-button-text.p-button-secondary:hover) { background: rgba(255, 255, 255, 0.08) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-button-text.p-button-danger) { color: #FCA5A5 !important; }
.dark-scope :deep(.p-button-text.p-button-danger:hover) { background: rgba(248, 113, 113, 0.12) !important; color: #FECACA !important; }
.dark-scope :deep(.p-button-outlined.p-button-secondary) { color: #CBD5E1 !important; border-color: rgba(255, 255, 255, 0.2) !important; }
.dark-scope :deep(.p-button-outlined.p-button-secondary:hover) { background: rgba(255, 255, 255, 0.08) !important; }

/* Amber "General Education" info banner (Information tab) */
.dark-scope :deep(.bg-amber-50) { background: rgba(245, 158, 11, 0.12) !important; }
.dark-scope :deep(.border-amber-200) { border-color: rgba(245, 158, 11, 0.3) !important; }
.dark-scope :deep(.text-amber-700),
.dark-scope :deep(.text-amber-800) { color: #FCD34D !important; }

/* Dialogs (teleported to <body>) */
:global(.dark-scope.p-dialog) { background: #0F1730 !important; color: #F8FAFC !important; border: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-dialog .p-dialog-header) { background: #0F1730 !important; border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-title) { color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-content) { background: #0F1730 !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-footer) { background: #0F1730 !important; border-top: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-dialog .p-dialog-close-button) { color: #CBD5E1 !important; }
:global(.dark-scope.p-dialog .p-dialog-close-button:hover) { background: rgba(255, 255, 255, 0.08) !important; color: #F8FAFC !important; }

:global(.dark-scope.p-dialog label) { color: #CBD5E1 !important; }
:global(.dark-scope.p-dialog p.text-xs.text-slate-400) { color: #94A3B8 !important; }
:global(.dark-scope.p-dialog .p-inputtext),
:global(.dark-scope.p-dialog .p-textarea),
:global(.dark-scope.p-dialog .p-select),
:global(.dark-scope.p-dialog .p-multiselect),
:global(.dark-scope.p-dialog .p-inputnumber-input) {
    background: rgba(255, 255, 255, 0.06) !important;
    border-color: rgba(255, 255, 255, 0.18) !important;
    color: #F8FAFC !important;
}
:global(.dark-scope.p-dialog .p-inputtext::placeholder) { color: #7C8CA8 !important; }
:global(.dark-scope.p-dialog .p-select-label),
:global(.dark-scope.p-dialog .p-multiselect-label) { color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-select-label.p-placeholder) { color: #7C8CA8 !important; }
:global(.dark-scope.p-dialog .p-inputnumber-button) { background: rgba(255, 255, 255, 0.06) !important; border-color: rgba(255, 255, 255, 0.18) !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-checkbox .p-checkbox-box) { background: rgba(255, 255, 255, 0.06) !important; border-color: rgba(255, 255, 255, 0.3) !important; }

/* Select / MultiSelect dropdown overlays + DatePicker time panel
   (also teleported) */
:global(.p-select-overlay.dark-scope),
:global(.p-multiselect-overlay.dark-scope) { background: #0F1730 !important; border: 1px solid rgba(255, 255, 255, 0.12) !important; color: #F8FAFC !important; }
:global(.p-select-overlay.dark-scope .p-select-option),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-option) { color: #F1F5F9 !important; }
:global(.p-select-overlay.dark-scope .p-select-option:hover),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-option:hover) { background: rgba(255, 255, 255, 0.08) !important; }
:global(.p-select-overlay.dark-scope .p-select-filter),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-filter) { background: rgba(255, 255, 255, 0.06) !important; border-color: rgba(255, 255, 255, 0.15) !important; color: #F8FAFC !important; }

:global(.p-datepicker-panel.dark-scope) { background: #0F1730 !important; border: 1px solid rgba(255, 255, 255, 0.12) !important; color: #F8FAFC !important; }
:global(.p-datepicker-panel.dark-scope .p-datepicker-time-picker) { border-color: rgba(255, 255, 255, 0.1) !important; }
:global(.p-datepicker-panel.dark-scope .p-datepicker-time-picker span) { color: #F8FAFC !important; }
:global(.p-datepicker-panel.dark-scope .p-button-text) { color: #CBD5E1 !important; }
</style>