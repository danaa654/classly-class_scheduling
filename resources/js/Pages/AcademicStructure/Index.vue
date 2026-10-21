<script setup>
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import Swal from 'sweetalert2';
import { useToast } from 'primevue/usetoast';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Tabs from 'primevue/tabs';
import TabList from 'primevue/tablist';
import Tab from 'primevue/tab';
import TabPanels from 'primevue/tabpanels';
import TabPanel from 'primevue/tabpanel';
import InputText from 'primevue/inputtext';
import Textarea from 'primevue/textarea';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import Paginator from 'primevue/paginator';
import Select from 'primevue/select';
import FloatLabel from 'primevue/floatlabel';
import Checkbox from 'primevue/checkbox';
import Toast from 'primevue/toast';
import InfoPopover from '@/Components/InfoPopover.vue';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const props = defineProps({
    colleges: { type: Object, default: () => ({ data: [], total: 0, per_page: 10, current_page: 1 }) },
    departments: { type: Object, default: () => ({ data: [], total: 0, per_page: 10, current_page: 1 }) },
    majors: { type: Object, default: () => ({ data: [], total: 0, per_page: 10, current_page: 1 }) },
    activeColleges: { type: Array, default: () => [] },
    activeDepartments: { type: Array, default: () => [] },
    filters: {
        type: Object,
        default: () => ({ college_search: '', department_search: '', major_search: '' }),
    },
});

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

const statusOptions = [
    { label: 'Active', value: 'Active' },
    { label: 'Inactive', value: 'Inactive' },
];

/* ------------------------------------------------------------------ */
/* Display helpers (list rows)                                          */
/* ------------------------------------------------------------------ */

// Remember which tab was open — saving in Departments/Majors redirects
// back to this page, which used to snap back to Colleges every time.
const TAB_KEY = 'academic-structure-tab';
const VALID_TABS = ['colleges', 'departments', 'majors'];
const readSavedTab = () => {
    try {
        const saved = sessionStorage.getItem(TAB_KEY);
        return VALID_TABS.includes(saved) ? saved : 'colleges';
    } catch {
        return 'colleges';
    }
};
const activeTab = ref(readSavedTab());
watch(activeTab, (value) => {
    try {
        sessionStorage.setItem(TAB_KEY, value);
    } catch {
        /* storage unavailable — tab just won't be remembered */
    }
});

// Code badge uses the owning College's own color (the same color the
// Room Grid and Section Grid use), with white or dark text depending
// on how light that color is. No color on file -> neutral slate.
const badgeStyle = (hex) => {
    const color = /^#[0-9a-f]{6}$/i.test(hex ?? '') ? hex : '#64748B';
    const r = parseInt(color.slice(1, 3), 16);
    const g = parseInt(color.slice(3, 5), 16);
    const b = parseInt(color.slice(5, 7), 16);
    const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;

    return { backgroundColor: color, color: luminance > 0.62 ? '#1E293B' : '#FFFFFF' };
};

// Active = green edge on a plain row (most rows are Active, so a full
// green wash would just be noise); Inactive = yellow row; Deleted = red.
const rowTheme = (row) => {
    const d = isDark.value;
    if (row.deleted_at) {
        return {
            label: 'Deleted',
            edge: 'border-l-red-500',
            card: d ? 'bg-red-500/10 ring-1 ring-red-500/30' : 'bg-red-50 ring-1 ring-red-200',
            pill: d ? 'bg-red-500/20 text-red-300' : 'bg-red-100 text-red-700',
        };
    }
    if (row.status === 'Active') {
        return {
            label: 'Active',
            edge: 'border-l-emerald-500',
            card: d ? 'bg-white/5 ring-1 ring-white/10' : 'bg-white ring-1 ring-slate-200/70',
            pill: d ? 'bg-emerald-500/20 text-emerald-300' : 'bg-emerald-100 text-emerald-700',
        };
    }
    return {
        label: row.status,
        edge: 'border-l-yellow-400',
        card: d ? 'bg-yellow-500/10 ring-1 ring-yellow-500/30' : 'bg-yellow-50 ring-1 ring-yellow-200',
        pill: d ? 'bg-yellow-500/20 text-yellow-300' : 'bg-yellow-200/80 text-yellow-900',
    };
};

/* ------------------------------------------------------------------ */
/* Colleges tab                                                        */
/* ------------------------------------------------------------------ */

const collegeSearch = ref(props.filters.college_search ?? '');
const collegeLoading = ref(false);
let collegeSearchDebounce = null;

const reloadColleges = (extra = {}) => {
    collegeLoading.value = true;

    router.get(
        route('academic-structure'),
        { college_search: collegeSearch.value, ...extra },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['colleges'],
            onFinish: () => {
                collegeLoading.value = false;
            },
        },
    );
};

watch(collegeSearch, () => {
    clearTimeout(collegeSearchDebounce);
    collegeSearchDebounce = setTimeout(() => {
        reloadColleges({ college_page: 1 });
    }, 350);
});

const onCollegePage = (event) => {
    reloadColleges({ college_page: event.page + 1 });
};

const onRefreshColleges = () => {
    reloadColleges({ college_page: props.colleges.current_page });
};

const collegeDialogVisible = ref(false);
const collegeDialogMode = ref('add'); // 'add' | 'edit'
const editingCollege = ref(null);

const collegeForm = useForm({
    code: '',
    name: '',
    short_name: '',
    description: '',
    status: 'Active',
    counts_as_gened: false,
});

const openAddCollege = () => {
    collegeDialogMode.value = 'add';
    editingCollege.value = null;
    collegeForm.reset();
    collegeForm.clearErrors();
    collegeDialogVisible.value = true;
};

const openEditCollege = (college) => {
    collegeDialogMode.value = 'edit';
    editingCollege.value = college;
    collegeForm.clearErrors();
    collegeForm.code = college.code;
    collegeForm.name = college.name;
    collegeForm.short_name = college.short_name ?? '';
    collegeForm.description = college.description ?? '';
    collegeForm.status = college.status;
    collegeForm.counts_as_gened = !!college.counts_as_gened;
    collegeDialogVisible.value = true;
};

const closeCollegeDialog = () => {
    collegeDialogVisible.value = false;
    collegeForm.reset();
    collegeForm.clearErrors();
    editingCollege.value = null;
};

const onSaveCollege = () => {
    const onError = () => {
        toast.add({
            severity: 'warn',
            summary: 'Missing information',
            detail: 'Please check the highlighted fields and try again.',
            life: 3000,
        });
    };

    if (collegeDialogMode.value === 'add') {
        collegeForm.post(route('colleges.store'), {
            preserveScroll: true,
            onSuccess: () => closeCollegeDialog(),
            onError,
        });
    } else {
        collegeForm.put(route('colleges.update', editingCollege.value.id), {
            preserveScroll: true,
            onSuccess: () => closeCollegeDialog(),
            onError,
        });
    }
};

// DELETE GATE — CollegeController::destroy() blocks this (errors.code)
// when the college still has active Departments/Faculty/Subjects/
// Rooms/Users attached — see that method's docblock. Confirmation
// here is just a heads-up; the follow-up alert on rejection names
// exactly what's still attached.
const onDeleteCollege = (college) => {
    Swal.fire({
        title: 'Delete this college?',
        html: `<p>${college.name} will be archived, not permanently deleted — it can be restored later.</p>
               <p style="margin-top:8px;">If it still has departments, faculty, subjects, rooms, or users attached, deletion will be blocked until those are removed or reassigned.</p>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, delete it',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('colleges.destroy', college.id), {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => {
                    Swal.fire({
                        title: "Can't delete this college",
                        text: errors.code ?? 'Please try again.',
                        icon: 'error',
                        confirmButtonColor: '#DC2626',
                        confirmButtonText: 'Got it',
                    });
                },
            });
        }
    });
};

const onRestoreCollege = (college) => {
    router.put(route('colleges.restore', college.id), {}, {
        preserveScroll: true,
        preserveState: true,
    });
};

// PERMANENT DELETE — only ever shown for a row already in the Deleted
// state (see the row Actions column below). CollegeController::forceDelete()
// re-checks the same attachment guard as the soft delete, since something
// could have been reassigned to this college's id since it was archived.
const onForceDeleteCollege = (college) => {
    Swal.fire({
        title: 'Permanently delete this college?',
        html: `<p><strong>${college.name}</strong> will be permanently removed. This cannot be undone.</p>
               <p style="margin-top:8px;">If it still has departments, faculty, subjects, rooms, or users attached, this will be blocked until those are removed or reassigned.</p>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, delete permanently',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('colleges.force-delete', college.id), {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => {
                    Swal.fire({
                        title: "Can't permanently delete this college",
                        text: errors.code ?? 'Please try again.',
                        icon: 'error',
                        confirmButtonColor: '#DC2626',
                        confirmButtonText: 'Got it',
                    });
                },
            });
        }
    });
};

/* ------------------------------------------------------------------ */
/* Departments tab                                                     */
/* ------------------------------------------------------------------ */

const departmentSearch = ref(props.filters.department_search ?? '');
const departmentLoading = ref(false);
let departmentSearchDebounce = null;

const reloadDepartments = (extra = {}) => {
    departmentLoading.value = true;

    router.get(
        route('academic-structure'),
        { department_search: departmentSearch.value, ...extra },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['departments'],
            onFinish: () => {
                departmentLoading.value = false;
            },
        },
    );
};

watch(departmentSearch, () => {
    clearTimeout(departmentSearchDebounce);
    departmentSearchDebounce = setTimeout(() => {
        reloadDepartments({ department_page: 1 });
    }, 350);
});

const onDepartmentPage = (event) => {
    reloadDepartments({ department_page: event.page + 1 });
};

const onRefreshDepartments = () => {
    reloadDepartments({ department_page: props.departments.current_page });
};

// Active colleges only, sorted alphabetically, for the dropdown.
const collegeOptions = computed(() =>
    [...props.activeColleges]
        .sort((a, b) => a.name.localeCompare(b.name))
        .map((college) => ({ label: college.name, value: college.id })),
);

const departmentDialogVisible = ref(false);
const departmentDialogMode = ref('add'); // 'add' | 'edit'
const editingDepartment = ref(null);

const departmentForm = useForm({
    college_id: null,
    code: '',
    name: '',
    short_name: '',
    description: '',
    status: 'Active',
});

const openAddDepartment = () => {
    departmentDialogMode.value = 'add';
    editingDepartment.value = null;
    departmentForm.reset();
    departmentForm.clearErrors();
    departmentDialogVisible.value = true;
};

const openEditDepartment = (department) => {
    departmentDialogMode.value = 'edit';
    editingDepartment.value = department;
    departmentForm.clearErrors();
    departmentForm.college_id = department.college_id;
    departmentForm.code = department.code;
    departmentForm.name = department.name;
    departmentForm.short_name = department.short_name ?? '';
    departmentForm.description = department.description ?? '';
    departmentForm.status = department.status;
    departmentDialogVisible.value = true;
};

const closeDepartmentDialog = () => {
    departmentDialogVisible.value = false;
    departmentForm.reset();
    departmentForm.clearErrors();
    editingDepartment.value = null;
};

const onSaveDepartment = () => {
    const onError = () => {
        toast.add({
            severity: 'warn',
            summary: 'Missing information',
            detail: 'Please check the highlighted fields and try again.',
            life: 3000,
        });
    };

    if (departmentDialogMode.value === 'add') {
        departmentForm.post(route('departments.store'), {
            preserveScroll: true,
            onSuccess: () => closeDepartmentDialog(),
            onError,
        });
    } else {
        departmentForm.put(route('departments.update', editingDepartment.value.id), {
            preserveScroll: true,
            onSuccess: () => closeDepartmentDialog(),
            onError,
        });
    }
};

// DELETE GATE — DepartmentController::destroy() blocks this
// (errors.code) when the department still has active Majors/Rooms/
// Users attached — see that method's docblock.
const onDeleteDepartment = (department) => {
    Swal.fire({
        title: 'Delete this department?',
        html: `<p>${department.name} will be archived, not permanently deleted — it can be restored later.</p>
               <p style="margin-top:8px;">If it still has majors, rooms, or users attached, deletion will be blocked until those are removed or reassigned.</p>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, delete it',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('departments.destroy', department.id), {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => {
                    Swal.fire({
                        title: "Can't delete this department",
                        text: errors.code ?? 'Please try again.',
                        icon: 'error',
                        confirmButtonColor: '#DC2626',
                        confirmButtonText: 'Got it',
                    });
                },
            });
        }
    });
};

const onRestoreDepartment = (department) => {
    router.put(route('departments.restore', department.id), {}, {
        preserveScroll: true,
        preserveState: true,
    });
};

// PERMANENT DELETE — only ever shown for a row already in the Deleted
// state (see the row Actions column below). DepartmentController::forceDelete()
// re-checks the same attachment guard as the soft delete.
const onForceDeleteDepartment = (department) => {
    Swal.fire({
        title: 'Permanently delete this department?',
        html: `<p><strong>${department.name}</strong> will be permanently removed. This cannot be undone.</p>
               <p style="margin-top:8px;">If it still has majors, rooms, or users attached, this will be blocked until those are removed or reassigned.</p>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, delete permanently',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('departments.force-delete', department.id), {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => {
                    Swal.fire({
                        title: "Can't permanently delete this department",
                        text: errors.code ?? 'Please try again.',
                        icon: 'error',
                        confirmButtonColor: '#DC2626',
                        confirmButtonText: 'Got it',
                    });
                },
            });
        }
    });
};

/* ------------------------------------------------------------------ */
/* Majors tab                                                          */
/* ------------------------------------------------------------------ */

const majorSearch = ref(props.filters.major_search ?? '');
const majorLoading = ref(false);
let majorSearchDebounce = null;

const reloadMajors = (extra = {}) => {
    majorLoading.value = true;

    router.get(
        route('academic-structure'),
        { major_search: majorSearch.value, ...extra },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['majors'],
            onFinish: () => {
                majorLoading.value = false;
            },
        },
    );
};

watch(majorSearch, () => {
    clearTimeout(majorSearchDebounce);
    majorSearchDebounce = setTimeout(() => {
        reloadMajors({ major_page: 1 });
    }, 350);
});

const onMajorPage = (event) => {
    reloadMajors({ major_page: event.page + 1 });
};

const onRefreshMajors = () => {
    reloadMajors({ major_page: props.majors.current_page });
};

// Departments dropdown for the Major dialog is filtered by the selected
// College, so it's scoped to whichever college the form currently holds.
const majorDepartmentOptions = computed(() => {
    if (!majorForm.college_id) {
        return [];
    }

    return [...props.activeDepartments]
        .filter((department) => department.college_id === majorForm.college_id)
        .sort((a, b) => a.name.localeCompare(b.name))
        .map((department) => ({ label: department.name, value: department.id }));
});

const majorDialogVisible = ref(false);
const majorDialogMode = ref('add'); // 'add' | 'edit'
const editingMajor = ref(null);

const majorForm = useForm({
    college_id: null,
    department_id: null,
    code: '',
    name: '',
    short_name: '',
    years: 4,
    description: '',
    status: 'Active',
});

// Clearing the College resets the Department, and switching College also
// clears whichever Department no longer belongs to it.
watch(
    () => majorForm.college_id,
    (newCollegeId, oldCollegeId) => {
        if (newCollegeId !== oldCollegeId) {
            majorForm.department_id = null;
        }
    },
);

const openAddMajor = () => {
    majorDialogMode.value = 'add';
    editingMajor.value = null;
    majorForm.reset();
    majorForm.clearErrors();
    majorDialogVisible.value = true;
};

const openEditMajor = (major) => {
    majorDialogMode.value = 'edit';
    editingMajor.value = major;
    majorForm.clearErrors();
    majorForm.college_id = major.department?.college_id ?? null;
    majorForm.department_id = major.department_id;
    majorForm.code = major.code;
    majorForm.name = major.name;
    majorForm.short_name = major.short_name ?? '';
    majorForm.years = major.years;
    majorForm.description = major.description ?? '';
    majorForm.status = major.status;
    majorDialogVisible.value = true;
};

const closeMajorDialog = () => {
    majorDialogVisible.value = false;
    majorForm.reset();
    majorForm.clearErrors();
    editingMajor.value = null;
};

const onSaveMajor = () => {
    const onError = () => {
        toast.add({
            severity: 'warn',
            summary: 'Missing information',
            detail: 'Please check the highlighted fields and try again.',
            life: 3000,
        });
    };

    // college_id only drives the cascading dropdown on the frontend;
    // the backend only needs department_id.
    const payload = {
        department_id: majorForm.department_id,
        code: majorForm.code,
        name: majorForm.name,
        short_name: majorForm.short_name,
        years: majorForm.years,
        description: majorForm.description,
        status: majorForm.status,
    };

    if (majorDialogMode.value === 'add') {
        majorForm.transform(() => payload).post(route('majors.store'), {
            preserveScroll: true,
            onSuccess: () => closeMajorDialog(),
            onError,
        });
    } else {
        majorForm.transform(() => payload).put(route('majors.update', editingMajor.value.id), {
            preserveScroll: true,
            onSuccess: () => closeMajorDialog(),
            onError,
        });
    }
};

// DELETE GATE — MajorController::destroy() blocks this (errors.code)
// when the major still has active Sections/Curriculums/Subjects
// attached — see that method's docblock.
const onDeleteMajor = (major) => {
    Swal.fire({
        title: 'Delete this major?',
        html: `<p>${major.name} will be archived, not permanently deleted — it can be restored later.</p>
               <p style="margin-top:8px;">If it still has sections, curriculums, or subjects attached, deletion will be blocked until those are removed or reassigned.</p>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, delete it',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('majors.destroy', major.id), {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => {
                    Swal.fire({
                        title: "Can't delete this major",
                        text: errors.code ?? 'Please try again.',
                        icon: 'error',
                        confirmButtonColor: '#DC2626',
                        confirmButtonText: 'Got it',
                    });
                },
            });
        }
    });
};

const onRestoreMajor = (major) => {
    router.put(route('majors.restore', major.id), {}, {
        preserveScroll: true,
        preserveState: true,
    });
};

// PERMANENT DELETE — only ever shown for a row already in the Deleted
// state (see the row Actions column below). MajorController::forceDelete()
// re-checks the same attachment guard as the soft delete.
const onForceDeleteMajor = (major) => {
    Swal.fire({
        title: 'Permanently delete this major?',
        html: `<p><strong>${major.name}</strong> will be permanently removed. This cannot be undone.</p>
               <p style="margin-top:8px;">If it still has sections, curriculums, or subjects attached, this will be blocked until those are removed or reassigned.</p>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, delete permanently',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('majors.force-delete', major.id), {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => {
                    Swal.fire({
                        title: "Can't permanently delete this major",
                        text: errors.code ?? 'Please try again.',
                        icon: 'error',
                        confirmButtonColor: '#DC2626',
                        confirmButtonText: 'Got it',
                    });
                },
            });
        }
    });
};
</script>

<template>
    <Head title="Academic Structure" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Academic Structure</span>
        </template>

        <div class="w-full" :class="isDark ? 'dark-scope' : ''">
            <!-- Page Title -->
            <PageHeader title="Academic Structure" subtitle="Manage colleges, departments, and majors." icon="pi pi-sitemap" tone="violet">
                <template #info>
                    <InfoPopover
                        title="Academic Structure"
                        :paragraphs="[
                            'Defines the institution\'s Colleges, Departments, and Majors — the organizational hierarchy the rest of CLASSLY is built on.',
                        ]"
                        :bullets="[
                            'Colleges group Departments; Departments group Majors.',
                            'These records are referenced throughout the system — Subjects, Sections, and Faculty are all tied back to a College/Major.',
                            'Renaming or removing an entry here can affect any Subjects, Sections, or Faculty already linked to it.',
                        ]"
                    />
                </template>
            </PageHeader>

            <!-- Tabs -->
            <Tabs v-model:value="activeTab">
                <TabList>
                    <Tab value="colleges">Colleges<span class="ml-2 rounded-full px-2 py-0.5 text-xs font-semibold" :class="isDark ? 'bg-white/10 text-slate-300' : 'bg-slate-100 text-slate-600'">{{ colleges.total }}</span></Tab>
                    <Tab value="departments">Departments<span class="ml-2 rounded-full px-2 py-0.5 text-xs font-semibold" :class="isDark ? 'bg-white/10 text-slate-300' : 'bg-slate-100 text-slate-600'">{{ departments.total }}</span></Tab>
                    <Tab value="majors">Majors<span class="ml-2 rounded-full px-2 py-0.5 text-xs font-semibold" :class="isDark ? 'bg-white/10 text-slate-300' : 'bg-slate-100 text-slate-600'">{{ majors.total }}</span></Tab>
                </TabList>

                <TabPanels>
                    <!-- Colleges -->
                    <TabPanel value="colleges">
                        <div class="neu-card mt-4 rounded-2xl p-4 transition-colors duration-300">
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                                <span class="relative w-full sm:w-96">
                                    <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-sm" :class="isDark ? 'text-slate-500' : 'text-slate-400'"></i>
                                    <InputText
                                        v-uppercase
                                        v-model="collegeSearch"
                                        placeholder="Search by code, name or short name"
                                        class="neu-inset w-full !rounded-xl !border-none !pl-9"
                                        :class="isDark ? '!text-white placeholder:!text-slate-500' : ''"
                                    />
                                </span>
                                <div class="flex items-center gap-2">
                                    <Button icon="pi pi-refresh" severity="secondary" text class="neu-icon-well !rounded-full" :loading="collegeLoading" @click="onRefreshColleges" aria-label="Refresh" />
                                    <Button label="Add college" icon="pi pi-plus" @click="openAddCollege" />
                                </div>
                            </div>

                            <div v-if="!colleges.data.length" class="py-12 text-center">
                                <i class="pi pi-building text-4xl" :class="isDark ? 'text-slate-600' : 'text-slate-300'"></i>
                                <p class="mt-3 font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-600'">
                                    {{ collegeSearch ? 'No colleges match your search.' : 'No colleges yet.' }}
                                </p>
                                <p class="mt-1 text-sm" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                                    {{ collegeSearch ? 'Try a different code or name.' : 'Add a college to start building your academic structure.' }}
                                </p>
                                <Button v-if="!collegeSearch" label="Add college" icon="pi pi-plus" class="mt-4" @click="openAddCollege" />
                            </div>

                            <ul v-else class="space-y-2" :class="collegeLoading ? 'opacity-60 transition-opacity' : ''">
                                <li
                                    v-for="row in colleges.data"
                                    :key="row.id"
                                    class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border-l-4 px-3 py-2.5"
                                    :class="[rowTheme(row).edge, rowTheme(row).card, row.deleted_at ? 'opacity-75' : '']"
                                >
                                    <span
                                        class="inline-flex min-w-[4.5rem] max-w-[11rem] items-center justify-center truncate rounded-lg px-2.5 py-1 text-xs font-bold tracking-wide"
                                        :style="badgeStyle(row.color)"
                                        :title="row.code"
                                    >{{ row.code }}</span>

                                    <div class="min-w-0 flex-1 basis-56">
                                        <p class="break-words text-[15px] font-semibold leading-snug" :class="isDark ? 'text-white' : 'text-[#1E293B]'">{{ row.name }}</p>
                                        <p class="mt-0.5 flex flex-wrap items-center gap-x-3 text-xs" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                                            <span v-if="row.short_name">{{ row.short_name }}</span>
                                            <span v-else class="italic opacity-70">No short name</span>
                                        </p>
                                    </div>

                                    <div class="flex items-center gap-1.5">
                                        <span v-if="row.counts_as_gened" class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="isDark ? 'bg-sky-500/20 text-sky-300' : 'bg-sky-100 text-sky-700'" title="Offers General Education subjects">GenEd provider</span>
                                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="rowTheme(row).pill">{{ rowTheme(row).label }}</span>
                                    </div>

                                    <div class="flex items-center gap-1">
                                        <template v-if="!row.deleted_at">
                                            <Button icon="pi pi-pencil" text rounded severity="secondary" size="small" aria-label="Edit college" title="Edit" @click="openEditCollege(row)" />
                                            <Button icon="pi pi-trash" text rounded severity="danger" size="small" aria-label="Delete college" title="Delete" @click="onDeleteCollege(row)" />
                                        </template>
                                        <template v-else>
                                            <Button icon="pi pi-refresh" label="Restore" text severity="success" size="small" @click="onRestoreCollege(row)" />
                                            <Button icon="pi pi-trash" label="Delete permanently" text severity="danger" size="small" @click="onForceDeleteCollege(row)" />
                                        </template>
                                    </div>
                                </li>
                            </ul>

                            <Paginator
                                v-if="colleges.total > colleges.per_page"
                                class="!mt-4 !bg-transparent"
                                template="PrevPageLink PageLinks NextPageLink"
                                :rows="colleges.per_page"
                                :totalRecords="colleges.total"
                                :first="(colleges.current_page - 1) * colleges.per_page"
                                @page="onCollegePage"
                            />
                        </div>
                    </TabPanel>

                    <!-- Departments -->
                    <TabPanel value="departments">
                        <div class="neu-card mt-4 rounded-2xl p-4 transition-colors duration-300">
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                                <span class="relative w-full sm:w-96">
                                    <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-sm" :class="isDark ? 'text-slate-500' : 'text-slate-400'"></i>
                                    <InputText
                                        v-uppercase
                                        v-model="departmentSearch"
                                        placeholder="Search by code, name or college"
                                        class="neu-inset w-full !rounded-xl !border-none !pl-9"
                                        :class="isDark ? '!text-white placeholder:!text-slate-500' : ''"
                                    />
                                </span>
                                <div class="flex items-center gap-2">
                                    <Button icon="pi pi-refresh" severity="secondary" text class="neu-icon-well !rounded-full" :loading="departmentLoading" @click="onRefreshDepartments" aria-label="Refresh" />
                                    <Button label="Add department" icon="pi pi-plus" @click="openAddDepartment" />
                                </div>
                            </div>

                            <div v-if="!departments.data.length" class="py-12 text-center">
                                <i class="pi pi-sitemap text-4xl" :class="isDark ? 'text-slate-600' : 'text-slate-300'"></i>
                                <p class="mt-3 font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-600'">
                                    {{ departmentSearch ? 'No departments match your search.' : 'No departments yet.' }}
                                </p>
                                <p class="mt-1 text-sm" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                                    {{ departmentSearch ? 'Try a different code or name.' : 'Add a department under a college.' }}
                                </p>
                                <Button v-if="!departmentSearch" label="Add department" icon="pi pi-plus" class="mt-4" @click="openAddDepartment" />
                            </div>

                            <ul v-else class="space-y-2" :class="departmentLoading ? 'opacity-60 transition-opacity' : ''">
                                <li
                                    v-for="row in departments.data"
                                    :key="row.id"
                                    class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border-l-4 px-3 py-2.5"
                                    :class="[rowTheme(row).edge, rowTheme(row).card, row.deleted_at ? 'opacity-75' : '']"
                                >
                                    <span
                                        class="inline-flex min-w-[4.5rem] max-w-[11rem] items-center justify-center truncate rounded-lg px-2.5 py-1 text-xs font-bold tracking-wide"
                                        :style="badgeStyle(row.college?.color)"
                                        :title="row.code"
                                    >{{ row.code }}</span>

                                    <div class="min-w-0 flex-1 basis-56">
                                        <p class="break-words text-[15px] font-semibold leading-snug" :class="isDark ? 'text-white' : 'text-[#1E293B]'">{{ row.name }}</p>
                                        <p class="mt-0.5 flex flex-wrap items-center gap-x-3 text-xs" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                                            <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full" :style="{ backgroundColor: badgeStyle(row.college?.color).backgroundColor }"></span>{{ row.college?.name || 'No college' }}</span>
                                            <span v-if="row.short_name">{{ row.short_name }}</span>
                                        </p>
                                    </div>

                                    <div class="flex items-center gap-1.5">

                                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="rowTheme(row).pill">{{ rowTheme(row).label }}</span>
                                    </div>

                                    <div class="flex items-center gap-1">
                                        <template v-if="!row.deleted_at">
                                            <Button icon="pi pi-pencil" text rounded severity="secondary" size="small" aria-label="Edit department" title="Edit" @click="openEditDepartment(row)" />
                                            <Button icon="pi pi-trash" text rounded severity="danger" size="small" aria-label="Delete department" title="Delete" @click="onDeleteDepartment(row)" />
                                        </template>
                                        <template v-else>
                                            <Button icon="pi pi-refresh" label="Restore" text severity="success" size="small" @click="onRestoreDepartment(row)" />
                                            <Button icon="pi pi-trash" label="Delete permanently" text severity="danger" size="small" @click="onForceDeleteDepartment(row)" />
                                        </template>
                                    </div>
                                </li>
                            </ul>

                            <Paginator
                                v-if="departments.total > departments.per_page"
                                class="!mt-4 !bg-transparent"
                                template="PrevPageLink PageLinks NextPageLink"
                                :rows="departments.per_page"
                                :totalRecords="departments.total"
                                :first="(departments.current_page - 1) * departments.per_page"
                                @page="onDepartmentPage"
                            />
                        </div>
                    </TabPanel>

                    <!-- Majors -->
                    <TabPanel value="majors">
                        <div class="neu-card mt-4 rounded-2xl p-4 transition-colors duration-300">
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                                <span class="relative w-full sm:w-96">
                                    <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-sm" :class="isDark ? 'text-slate-500' : 'text-slate-400'"></i>
                                    <InputText
                                        v-uppercase
                                        v-model="majorSearch"
                                        placeholder="Search by code, name, department or college"
                                        class="neu-inset w-full !rounded-xl !border-none !pl-9"
                                        :class="isDark ? '!text-white placeholder:!text-slate-500' : ''"
                                    />
                                </span>
                                <div class="flex items-center gap-2">
                                    <Button icon="pi pi-refresh" severity="secondary" text class="neu-icon-well !rounded-full" :loading="majorLoading" @click="onRefreshMajors" aria-label="Refresh" />
                                    <Button label="Add major" icon="pi pi-plus" @click="openAddMajor" />
                                </div>
                            </div>

                            <div v-if="!majors.data.length" class="py-12 text-center">
                                <i class="pi pi-book text-4xl" :class="isDark ? 'text-slate-600' : 'text-slate-300'"></i>
                                <p class="mt-3 font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-600'">
                                    {{ majorSearch ? 'No majors match your search.' : 'No majors yet.' }}
                                </p>
                                <p class="mt-1 text-sm" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                                    {{ majorSearch ? 'Try a different code or name.' : 'Add a major under a department.' }}
                                </p>
                                <Button v-if="!majorSearch" label="Add major" icon="pi pi-plus" class="mt-4" @click="openAddMajor" />
                            </div>

                            <ul v-else class="space-y-2" :class="majorLoading ? 'opacity-60 transition-opacity' : ''">
                                <li
                                    v-for="row in majors.data"
                                    :key="row.id"
                                    class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border-l-4 px-3 py-2.5"
                                    :class="[rowTheme(row).edge, rowTheme(row).card, row.deleted_at ? 'opacity-75' : '']"
                                >
                                    <span
                                        class="inline-flex min-w-[4.5rem] max-w-[11rem] items-center justify-center truncate rounded-lg px-2.5 py-1 text-xs font-bold tracking-wide"
                                        :style="badgeStyle(row.department?.college?.color)"
                                        :title="row.code"
                                    >{{ row.code }}</span>

                                    <div class="min-w-0 flex-1 basis-56">
                                        <p class="break-words text-[15px] font-semibold leading-snug" :class="isDark ? 'text-white' : 'text-[#1E293B]'">{{ row.name }}</p>
                                        <p class="mt-0.5 flex flex-wrap items-center gap-x-3 text-xs" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                                            <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full" :style="{ backgroundColor: badgeStyle(row.department?.college?.color).backgroundColor }"></span>{{ row.department?.college?.name || 'No college' }}</span>
                                            <span>{{ row.department?.name || 'No department' }}</span>
                                        </p>
                                    </div>

                                    <div class="flex items-center gap-1.5">
                                        <span v-if="row.years" class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="isDark ? 'bg-white/10 text-slate-300' : 'bg-slate-100 text-slate-600'">{{ row.years }} {{ Number(row.years) === 1 ? 'year' : 'years' }}</span>
                                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="rowTheme(row).pill">{{ rowTheme(row).label }}</span>
                                    </div>

                                    <div class="flex items-center gap-1">
                                        <template v-if="!row.deleted_at">
                                            <Button icon="pi pi-pencil" text rounded severity="secondary" size="small" aria-label="Edit major" title="Edit" @click="openEditMajor(row)" />
                                            <Button icon="pi pi-trash" text rounded severity="danger" size="small" aria-label="Delete major" title="Delete" @click="onDeleteMajor(row)" />
                                        </template>
                                        <template v-else>
                                            <Button icon="pi pi-refresh" label="Restore" text severity="success" size="small" @click="onRestoreMajor(row)" />
                                            <Button icon="pi pi-trash" label="Delete permanently" text severity="danger" size="small" @click="onForceDeleteMajor(row)" />
                                        </template>
                                    </div>
                                </li>
                            </ul>

                            <Paginator
                                v-if="majors.total > majors.per_page"
                                class="!mt-4 !bg-transparent"
                                template="PrevPageLink PageLinks NextPageLink"
                                :rows="majors.per_page"
                                :totalRecords="majors.total"
                                :first="(majors.current_page - 1) * majors.per_page"
                                @page="onMajorPage"
                            />
                        </div>
                    </TabPanel>
                </TabPanels>
            </Tabs>
        </div>

        <!-- Add / Edit College Modal -->
        <Dialog
            v-model:visible="collegeDialogVisible"
            modal
            :style="{ width: '600px', ...(isDark ? {} : { backgroundColor: 'var(--neu-card-light)' }) }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            :pt="{
                root: { class: isDark ? '!bg-[#141D33] !border !border-white/10 !text-white !rounded-2xl !shadow-2xl dark-scope' : '!border !border-[rgba(30,41,59,0.06)] !rounded-2xl !shadow-2xl' },
                header: { class: isDark ? '!bg-[#141D33] !border-b !border-white/10 !rounded-t-2xl' : '!rounded-t-2xl' },
                content: { class: isDark ? '!bg-[#141D33]' : '' },
                footer: { class: isDark ? '!bg-[#141D33] !border-t !border-white/10 !rounded-b-2xl' : '!rounded-b-2xl' },
            }"
        >
            <template #header>
                <span class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                    {{ collegeDialogMode === 'add' ? 'Add College' : 'Edit College' }}
                </span>
            </template>

            <form class="pt-2 neu-form" autocomplete="off" @submit.prevent="onSaveCollege">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <FloatLabel variant="on">
                        <InputText
                            v-uppercase
                            id="collegeCode"
                            v-model="collegeForm.code"
                            class="w-full"
                            maxlength="20"
                            autocomplete="off"
                            placeholder="e.g. CCS"
                            :invalid="!!collegeForm.errors.code"
                        />
                        <label for="collegeCode">College Code *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <Select
                            id="collegeStatus"
                            v-model="collegeForm.status"
                            :options="statusOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :invalid="!!collegeForm.errors.status"
                        />
                        <label for="collegeStatus">Status *</label>
                    </FloatLabel>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-1 mt-1">
                    <small v-if="collegeForm.errors.code" class="text-red-500">{{ collegeForm.errors.code }}</small>
                    <small v-if="collegeForm.errors.status" class="text-red-500">{{ collegeForm.errors.status }}</small>
                </div>

                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            v-uppercase
                            id="collegeName"
                            v-model="collegeForm.name"
                            class="w-full"
                            maxlength="255"
                            autocomplete="off"
                            placeholder="e.g. College of Computer Studies"
                            :invalid="!!collegeForm.errors.name"
                        />
                        <label for="collegeName">College Name *</label>
                    </FloatLabel>
                    <small v-if="collegeForm.errors.name" class="text-red-500 -mt-4">{{ collegeForm.errors.name }}</small>
                </div>

                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            v-uppercase
                            id="collegeShortName"
                            v-model="collegeForm.short_name"
                            class="w-full"
                            maxlength="100"
                            autocomplete="off"
                            placeholder="e.g. CCS"
                            :invalid="!!collegeForm.errors.short_name"
                        />
                        <label for="collegeShortName">Short Name</label>
                    </FloatLabel>
                    <small v-if="collegeForm.errors.short_name" class="text-red-500 -mt-4">{{ collegeForm.errors.short_name }}</small>
                </div>

                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <Textarea
                            id="collegeDescription"
                            v-model="collegeForm.description"
                            class="w-full"
                            rows="3"
                            autoResize
                            placeholder="e.g. Handles Information Technology and Computer Science programs"
                            :invalid="!!collegeForm.errors.description"
                        />
                        <label for="collegeDescription">Description</label>
                    </FloatLabel>
                    <small v-if="collegeForm.errors.description" class="text-red-500 -mt-4">{{ collegeForm.errors.description }}</small>
                </div>

                <!-- Counts as GenEd provider -->
                <div class="flex items-start gap-2 mt-5">
                    <Checkbox
                        v-model="collegeForm.counts_as_gened"
                        inputId="collegeCountsAsGened"
                        binary
                    />
                    <label for="collegeCountsAsGened" class="text-sm" :class="isDark ? 'text-slate-200' : 'text-[#1E293B]'">
                        Counts as General Education provider
                        <span class="block text-xs opacity-70">
                            Faculty in this College will also be recommended and matched for General Education / Minor subjects, in addition to their own College's subjects.
                        </span>
                    </label>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" text severity="secondary" :disabled="collegeForm.processing" @click="closeCollegeDialog" />
                <Button label="Save" icon="pi pi-check" :loading="collegeForm.processing" @click="onSaveCollege" />
            </template>
        </Dialog>

        <!-- Add / Edit Department Modal -->
        <Dialog
            v-model:visible="departmentDialogVisible"
            modal
            :style="{ width: '600px', ...(isDark ? {} : { backgroundColor: 'var(--neu-card-light)' }) }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            :pt="{
                root: { class: isDark ? '!bg-[#141D33] !border !border-white/10 !text-white !rounded-2xl !shadow-2xl dark-scope' : '!border !border-[rgba(30,41,59,0.06)] !rounded-2xl !shadow-2xl' },
                header: { class: isDark ? '!bg-[#141D33] !border-b !border-white/10 !rounded-t-2xl' : '!rounded-t-2xl' },
                content: { class: isDark ? '!bg-[#141D33]' : '' },
                footer: { class: isDark ? '!bg-[#141D33] !border-t !border-white/10 !rounded-b-2xl' : '!rounded-b-2xl' },
            }"
        >
            <template #header>
                <span class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                    {{ departmentDialogMode === 'add' ? 'Add Department' : 'Edit Department' }}
                </span>
            </template>

            <form class="pt-2 neu-form" autocomplete="off" @submit.prevent="onSaveDepartment">
                <div class="grid grid-cols-1 gap-5">
                    <FloatLabel variant="on">
                        <Select
                            id="departmentCollege"
                            v-model="departmentForm.college_id"
                            :options="collegeOptions"
                            optionLabel="label"
                            optionValue="value"
                            filter
                            class="w-full"
                            :invalid="!!departmentForm.errors.college_id"
                        />
                        <label for="departmentCollege">College *</label>
                    </FloatLabel>
                    <small v-if="departmentForm.errors.college_id" class="text-red-500 -mt-4">{{ departmentForm.errors.college_id }}</small>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            v-uppercase
                            id="departmentCode"
                            v-model="departmentForm.code"
                            class="w-full"
                            maxlength="20"
                            autocomplete="off"
                            placeholder="e.g. CCS-BSIT"
                            :invalid="!!departmentForm.errors.code"
                        />
                        <label for="departmentCode">Department Code *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <Select
                            id="departmentStatus"
                            v-model="departmentForm.status"
                            :options="statusOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :invalid="!!departmentForm.errors.status"
                        />
                        <label for="departmentStatus">Status *</label>
                    </FloatLabel>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-1 mt-1">
                    <small v-if="departmentForm.errors.code" class="text-red-500">{{ departmentForm.errors.code }}</small>
                    <small v-if="departmentForm.errors.status" class="text-red-500">{{ departmentForm.errors.status }}</small>
                </div>

                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            v-uppercase
                            id="departmentName"
                            v-model="departmentForm.name"
                            class="w-full"
                            maxlength="255"
                            autocomplete="off"
                            placeholder="e.g. BSIT"
                            :invalid="!!departmentForm.errors.name"
                        />
                        <label for="departmentName">Department Name *</label>
                    </FloatLabel>
                    <small v-if="departmentForm.errors.name" class="text-red-500 -mt-4">{{ departmentForm.errors.name }}</small>
                </div>

                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            v-uppercase
                            id="departmentShortName"
                            v-model="departmentForm.short_name"
                            class="w-full"
                            maxlength="100"
                            autocomplete="off"
                            placeholder="e.g. BSIT"
                            :invalid="!!departmentForm.errors.short_name"
                        />
                        <label for="departmentShortName">Short Name</label>
                    </FloatLabel>
                    <small v-if="departmentForm.errors.short_name" class="text-red-500 -mt-4">{{ departmentForm.errors.short_name }}</small>
                </div>

                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <Textarea
                            id="departmentDescription"
                            v-model="departmentForm.description"
                            class="w-full"
                            rows="3"
                            autoResize
                            placeholder="e.g. Bachelor of Science in Information Technology"
                            :invalid="!!departmentForm.errors.description"
                        />
                        <label for="departmentDescription">Description</label>
                    </FloatLabel>
                    <small v-if="departmentForm.errors.description" class="text-red-500 -mt-4">{{ departmentForm.errors.description }}</small>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" text severity="secondary" :disabled="departmentForm.processing" @click="closeDepartmentDialog" />
                <Button label="Save" icon="pi pi-check" :loading="departmentForm.processing" @click="onSaveDepartment" />
            </template>
        </Dialog>

        <!-- Add / Edit Major Modal -->
        <Dialog
            v-model:visible="majorDialogVisible"
            modal
            :style="{ width: '600px', ...(isDark ? {} : { backgroundColor: 'var(--neu-card-light)' }) }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            :pt="{
                root: { class: isDark ? '!bg-[#141D33] !border !border-white/10 !text-white !rounded-2xl !shadow-2xl dark-scope' : '!border !border-[rgba(30,41,59,0.06)] !rounded-2xl !shadow-2xl' },
                header: { class: isDark ? '!bg-[#141D33] !border-b !border-white/10 !rounded-t-2xl' : '!rounded-t-2xl' },
                content: { class: isDark ? '!bg-[#141D33]' : '' },
                footer: { class: isDark ? '!bg-[#141D33] !border-t !border-white/10 !rounded-b-2xl' : '!rounded-b-2xl' },
            }"
        >
            <template #header>
                <span class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                    {{ majorDialogMode === 'add' ? 'Add Major' : 'Edit Major' }}
                </span>
            </template>

            <form class="pt-2 neu-form" autocomplete="off" @submit.prevent="onSaveMajor">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <FloatLabel variant="on">
                        <Select
                            id="majorCollege"
                            v-model="majorForm.college_id"
                            :options="collegeOptions"
                            optionLabel="label"
                            optionValue="value"
                            filter
                            class="w-full"
                        />
                        <label for="majorCollege">College *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <Select
                            id="majorDepartment"
                            v-model="majorForm.department_id"
                            :options="majorDepartmentOptions"
                            optionLabel="label"
                            optionValue="value"
                            filter
                            :disabled="!majorForm.college_id"
                            class="w-full"
                            :invalid="!!majorForm.errors.department_id"
                        />
                        <label for="majorDepartment">Department *</label>
                    </FloatLabel>
                </div>
                <small v-if="majorForm.errors.department_id" class="text-red-500 block mt-1">{{ majorForm.errors.department_id }}</small>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            v-uppercase
                            id="majorCode"
                            v-model="majorForm.code"
                            class="w-full"
                            maxlength="20"
                            autocomplete="off"
                            placeholder="e.g. IT"
                            :invalid="!!majorForm.errors.code"
                        />
                        <label for="majorCode">Major Code *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <Select
                            id="majorStatus"
                            v-model="majorForm.status"
                            :options="statusOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :invalid="!!majorForm.errors.status"
                        />
                        <label for="majorStatus">Status *</label>
                    </FloatLabel>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-1 mt-1">
                    <small v-if="majorForm.errors.code" class="text-red-500">{{ majorForm.errors.code }}</small>
                    <small v-if="majorForm.errors.status" class="text-red-500">{{ majorForm.errors.status }}</small>
                </div>

                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            v-uppercase
                            id="majorName"
                            v-model="majorForm.name"
                            class="w-full"
                            maxlength="255"
                            autocomplete="off"
                            placeholder="e.g. Bachelor of Science in Information Technology"
                            :invalid="!!majorForm.errors.name"
                        />
                        <label for="majorName">Major Name *</label>
                    </FloatLabel>
                    <small v-if="majorForm.errors.name" class="text-red-500 -mt-4">{{ majorForm.errors.name }}</small>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            v-uppercase
                            id="majorShortName"
                            v-model="majorForm.short_name"
                            class="w-full"
                            maxlength="100"
                            autocomplete="off"
                            placeholder="e.g. BSIT"
                            :invalid="!!majorForm.errors.short_name"
                        />
                        <label for="majorShortName">Short Name</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <InputText
                            id="majorYears"
                            v-model.number="majorForm.years"
                            type="number"
                            :min="1"
                            :max="6"
                            class="w-full"
                            :invalid="!!majorForm.errors.years"
                        />
                        <label for="majorYears">Years *</label>
                    </FloatLabel>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-1 mt-1">
                    <small v-if="majorForm.errors.short_name" class="text-red-500">{{ majorForm.errors.short_name }}</small>
                    <small v-if="majorForm.errors.years" class="text-red-500">{{ majorForm.errors.years }}</small>
                </div>

                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <Textarea
                            id="majorDescription"
                            v-model="majorForm.description"
                            class="w-full"
                            rows="3"
                            autoResize
                            placeholder="e.g. 4-year IT program under the College of Computer Studies"
                            :invalid="!!majorForm.errors.description"
                        />
                        <label for="majorDescription">Description</label>
                    </FloatLabel>
                    <small v-if="majorForm.errors.description" class="text-red-500 -mt-4">{{ majorForm.errors.description }}</small>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" text severity="secondary" :disabled="majorForm.processing" @click="closeMajorDialog" />
                <Button label="Save" icon="pi pi-check" :loading="majorForm.processing" @click="onSaveMajor" />
            </template>
        </Dialog>
    </AppLayout>
</template>
<style scoped>
/* Dark-mode overrides. Wrapping an element in the "dark-scope" class
   (added conditionally via isDark) recolors the shared light-mode
   Tailwind utility classes and PrimeVue component chrome used
   throughout this page — including inside Dialogs, which PrimeVue
   teleports to <body> but keeps as one contiguous subtree, so these
   descendant selectors still reach them. */
.dark-scope :deep(.text-\[\#1E293B\]) { color: #F8FAFC !important; }
.dark-scope :deep(.text-slate-700) { color: #CBD5E1 !important; }
.dark-scope :deep(.text-slate-600) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-500) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-400) { color: #64748B !important; }
.dark-scope :deep(.bg-white) { background-color: rgba(255, 255, 255, 0.06) !important; }
.dark-scope :deep(.bg-slate-50) { background-color: rgba(255, 255, 255, 0.04) !important; }
.dark-scope :deep(.bg-slate-100) { background-color: rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.border-slate-100) { border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.border-slate-200) { border-color: rgba(255, 255, 255, 0.12) !important; }

.dark-scope :deep(.p-card) { background: #101A35 !important; color: #F8FAFC; border: 1px solid rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.p-card .p-card-body) { background: transparent !important; }

.dark-scope :deep(.p-inputtext::placeholder),
.dark-scope :deep(.p-select-label.p-placeholder) { color: #7C8CA8 !important; }
.dark-scope :deep(.pi) { color: inherit; }

:global(.dark-scope.p-dialog) { background: #0F1730 !important; color: #F8FAFC !important; border: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-dialog .p-dialog-header) { background: #0F1730 !important; border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-content) { background: #0F1730 !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-footer) { background: #0F1730 !important; border-top: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-dialog .p-dialog-title) { color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-close-button) { color: #CBD5E1 !important; }
:global(.dark-scope.p-dialog .p-dialog-close-button:hover) { background: rgba(255, 255, 255, 0.08) !important; color: #F8FAFC !important; }

:global(.dark-scope.p-dialog .p-inputtext),
:global(.dark-scope.p-dialog .p-textarea),
:global(.dark-scope.p-dialog .p-select),
:global(.dark-scope.p-dialog .p-multiselect),
:global(.dark-scope.p-dialog .p-inputnumber-input) {
    background: rgba(255, 255, 255, 0.06) !important;
    border-color: rgba(255, 255, 255, 0.18) !important;
    color: #F8FAFC !important;
}
:global(.dark-scope.p-dialog .p-inputtext::placeholder),
:global(.dark-scope.p-dialog .p-textarea::placeholder) { color: #7C8CA8 !important; }
:global(.dark-scope.p-dialog .p-select-label),
:global(.dark-scope.p-dialog .p-multiselect-label) { color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-floatlabel label) { color: #94A3B8 !important; background: #0F1730 !important; }
:global(.dark-scope.p-dialog .p-select-overlay),
:global(.dark-scope.p-dialog .p-multiselect-overlay) { background: #0F1730 !important; border: 1px solid rgba(255, 255, 255, 0.12) !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-select-option),
:global(.dark-scope.p-dialog .p-multiselect-option) { color: #F1F5F9 !important; }
:global(.dark-scope.p-dialog .p-select-option:hover),
:global(.dark-scope.p-dialog .p-multiselect-option:hover) { background: rgba(255, 255, 255, 0.08) !important; }
:global(.p-select-overlay.dark-scope),
:global(.p-multiselect-overlay.dark-scope) { background: #0F1730 !important; border: 1px solid rgba(255, 255, 255, 0.12) !important; color: #F8FAFC !important; }

.dark-scope :deep(.p-divider.p-divider-horizontal:before) { border-color: rgba(255, 255, 255, 0.1) !important; }

.dark-scope :deep(.p-tablist) { background: transparent !important; border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.p-tabpanels) { background: transparent !important; color: #F1F5F9 !important; padding: 0 !important; }
.dark-scope :deep(.p-tabpanel) { background: transparent !important; color: #F1F5F9 !important; }

.dark-scope :deep(.p-inputtext),
.dark-scope :deep(.p-select),
.dark-scope :deep(.p-multiselect),
.dark-scope :deep(.p-inputnumber-input) {
    background: rgba(255, 255, 255, 0.06) !important;
    border-color: rgba(255, 255, 255, 0.15) !important;
    color: #F8FAFC !important;
}
.dark-scope :deep(.p-inputtext::placeholder) { color: #7C8CA8 !important; }
.dark-scope :deep(.p-select-label),
.dark-scope :deep(.p-multiselect-label) { color: #F8FAFC !important; }
.dark-scope :deep(.p-tab) { color: #94A3B8 !important; }
.dark-scope :deep(.p-tab-active) { color: #F8FAFC !important; }

.dark-scope :deep(.p-datatable) { background: transparent !important; color: #F1F5F9 !important; }
.dark-scope :deep(.p-datatable-thead > tr > th) { background: rgba(255, 255, 255, 0.06) !important; color: #F1F5F9 !important; border-color: rgba(255, 255, 255, 0.12) !important; font-weight: 600; }
.dark-scope :deep(.p-datatable-tbody > tr) { background: transparent !important; color: #F1F5F9 !important; }
.dark-scope :deep(.p-datatable-tbody > tr > td) { color: #F1F5F9 !important; border-color: rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.p-datatable-tbody > tr.p-datatable-row-striped) { background: rgba(255, 255, 255, 0.035) !important; }
.dark-scope :deep(.p-datatable-tbody > tr.p-datatable-row-striped > td) { background: transparent !important; }
.dark-scope :deep(.p-datatable-tbody > tr:hover) { background: rgba(255, 255, 255, 0.07) !important; }
.dark-scope :deep(.p-datatable-tbody > tr:hover > td) { background: transparent !important; }
.dark-scope :deep(.p-datatable-emptymessage) { color: #CBD5E1 !important; }
.dark-scope :deep(.p-paginator) { background: transparent !important; color: #F1F5F9 !important; }
.dark-scope :deep(.p-paginator .p-paginator-page),
.dark-scope :deep(.p-paginator .p-paginator-prev),
.dark-scope :deep(.p-paginator .p-paginator-next),
.dark-scope :deep(.p-paginator .p-paginator-first),
.dark-scope :deep(.p-paginator .p-paginator-last) { color: #CBD5E1 !important; }
.dark-scope :deep(.p-paginator .p-paginator-page.p-paginator-page-selected) { background: rgba(37, 99, 235, 0.9) !important; color: #fff !important; }

/* Buttons: text/secondary icon buttons (edit/delete/refresh) default to
   low-contrast grays in the light theme — brighten them for dark mode. */
.dark-scope :deep(.p-button-text.p-button-secondary) { color: #CBD5E1 !important; }
.dark-scope :deep(.p-button-text.p-button-secondary:hover) { background: rgba(255, 255, 255, 0.08) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-button-text.p-button-danger) { color: #FCA5A5 !important; }
.dark-scope :deep(.p-button-text.p-button-danger:hover) { background: rgba(248, 113, 113, 0.12) !important; color: #FECACA !important; }
.dark-scope :deep(.p-button-text.p-button-success) { color: #6EE7B7 !important; }
.dark-scope :deep(.p-button-outlined.p-button-secondary) { color: #CBD5E1 !important; border-color: rgba(255, 255, 255, 0.2) !important; }
.dark-scope :deep(.p-button-outlined.p-button-secondary:hover) { background: rgba(255, 255, 255, 0.08) !important; }

.dark-scope :deep(.p-menu) { background: #0F1730 !important; border-color: rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-menu .p-menu-item-link) { color: #E2E8F0 !important; }
.dark-scope :deep(.p-menu .p-menu-item-link:hover) { background: rgba(255, 255, 255, 0.06) !important; }

.dark-scope :deep(.p-paginator) { background: transparent !important; color: #CBD5E1; }
.dark-scope :deep(.p-paginator-page),
.dark-scope :deep(.p-paginator-prev),
.dark-scope :deep(.p-paginator-next) { color: #CBD5E1 !important; }
.dark-scope :deep(.p-paginator-page.p-paginator-page-selected) { background: rgba(96, 165, 250, 0.2) !important; color: #BFDBFE !important; }
</style>