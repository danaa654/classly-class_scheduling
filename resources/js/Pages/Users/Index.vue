<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from 'primevue/card';
import Toolbar from 'primevue/toolbar';
import InputText from 'primevue/inputtext';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import Password from 'primevue/password';
import Select from 'primevue/select';
import MultiSelect from 'primevue/multiselect';
import Divider from 'primevue/divider';
import FloatLabel from 'primevue/floatlabel';
import Checkbox from 'primevue/checkbox';
import Toast from 'primevue/toast';
import Tabs from 'primevue/tabs';
import TabList from 'primevue/tablist';
import Tab from 'primevue/tab';
import TabPanels from 'primevue/tabpanels';
import TabPanel from 'primevue/tabpanel';
import Menu from 'primevue/menu';
import Swal from 'sweetalert2';
import InfoPopover from '@/Components/InfoPopover.vue';
import AccountForm from '@/Components/Account/AccountForm.vue';
import PasswordRequirementsChecklist from '@/Components/PasswordRequirementsChecklist.vue';
import { useTheme } from '@/composables/useTheme';
import { useCollegeColors, collegeSolidStyle } from '@/composables/useCollegeColors';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const props = defineProps({
    users: { type: Array, default: () => [] },
    colleges: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
    passwordPolicy: { type: Object, default: () => ({ minLength: 8, requireUppercase: false, requireNumber: false, requireSymbol: false }) },
});

const toast = useToast();
const page = usePage();

// Show a toast whenever the backend flashes a success/error message
// (e.g. right after Create/Edit User actually saves).
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

const search = ref('');

/* ------------------------------------------------------------------ */
/* Roster view: role filter, cards/table toggle, ID-card helpers       */
/* ------------------------------------------------------------------ */

// Each ID card takes the colour of the College it belongs to (the same
// colours set in Settings -> College Colors and used on the Room/Section
// grids). Accounts with no College (Administrator, Registrar) share one
// "system" colour.
const { colorFor } = useCollegeColors();
const SYSTEM_COLOR = '#4338ca';
const cardColor = (user) => (user.collegeId != null ? colorFor(user.collegeId) : SYSTEM_COLOR);
// White or dark text, whichever reads on that colour (yellow needs dark).
const onColor = (hex) => collegeSolidStyle(hex).color;
const groupKey = (user) => (user.collegeId != null ? `c${user.collegeId}` : 'sys');

const roleFilter = ref('All');
const groupFilter = ref('All');

const VIEW_KEY = 'classly.users.view';
const readView = () => {
    try {
        return localStorage.getItem(VIEW_KEY) === 'table' ? 'table' : 'cards';
    } catch {
        return 'cards';
    }
};
const viewMode = ref(readView());
watch(viewMode, (mode) => {
    try {
        localStorage.setItem(VIEW_KEY, mode);
    } catch {
        /* storage unavailable — the choice just won't persist */
    }
});

// Names arrive in capitals ("NENITA S. CANOMON"); show them in sentence
// form, but keep roman-numeral suffixes (III) in capitals.
const titleCase = (value) =>
    (value ?? '')
        .toLowerCase()
        .replace(/(^|[\s\-'(])([a-zà-ÿ])/g, (m, lead, ch) => lead + ch.toUpperCase())
        .replace(/\b(Ii|Iii|Iv)\b/g, (m) => m.toUpperCase());

const initialsFor = (user) => {
    const first = user.firstName?.trim()?.[0] ?? '';
    const last = user.lastName?.trim()?.[0] ?? '';
    if (first || last) return (first + last).toUpperCase();
    return (user.fullName ?? '?').trim().slice(0, 2).toUpperCase();
};

const groupSegments = computed(() => {
    const groups = new Map();
    props.users.forEach((u) => {
        const key = groupKey(u);
        if (!groups.has(key)) {
            groups.set(key, {
                key,
                label: u.collegeId != null ? (u.college || 'College') : 'System-wide',
                color: cardColor(u),
                count: 0,
            });
        }
        groups.get(key).count += 1;
    });
    return [...groups.values()].sort((x, y) => (x.key === 'sys') - (y.key === 'sys') || x.label.localeCompare(y.label));
});

const brokenPhotos = ref({});
const onPhotoError = (id) => {
    brokenPhotos.value = { ...brokenPhotos.value, [id]: true };
};

const activeCount = computed(() => props.users.filter((u) => u.status === 'Active').length);

const filteredUsers = computed(() => {
    const term = search.value.trim().toLowerCase();
    return props.users.filter((u) => {
        if (roleFilter.value !== 'All' && u.role !== roleFilter.value) return false;
        if (groupFilter.value !== 'All' && groupKey(u) !== groupFilter.value) return false;
        if (!term) return true;
        return (u.fullName ?? '').toLowerCase().includes(term) || (u.email ?? '').toLowerCase().includes(term);
    });
});

const clearFilters = () => {
    search.value = '';
    roleFilter.value = 'All';
    groupFilter.value = 'All';
};

const copyEmail = async (user) => {
    try {
        await navigator.clipboard.writeText(user.email);
        toast.add({ severity: 'success', summary: 'Copied', detail: `${user.email} copied`, life: 2500 });
    } catch {
        toast.add({ severity: 'warn', summary: 'Copy failed', detail: 'Select the email and copy it manually.', life: 3500 });
    }
};

// Only Administrators get the "Manage Account" tab — everyone else on
// this page (which is itself Administrator-only per the sidebar) still
// only sees the Users tab.
const authRoles = computed(() => page.props.auth?.roles ?? []);
const isAdministrator = computed(() => authRoles.value.includes('Administrator'));
// Lets a notification (e.g. "Password Change Required") deep-link
// straight into the Manage Account tab via ?tab=account.
const activeTab = ref(new URLSearchParams(window.location.search).get('tab') ?? 'users');

const roleOptions = [
    { label: 'Administrator', value: 'Administrator' },
    { label: 'Registrar', value: 'Registrar' },
    { label: 'Assistant Dean', value: 'Assistant Dean' },
    { label: 'Dean', value: 'Dean' },
    { label: 'OIC', value: 'OIC' },
];

const statusOptions = [
    { label: 'Active', value: 'Active' },
    { label: 'Inactive', value: 'Inactive' },
];

const collegeOptions = computed(() =>
    props.colleges.map((college) => ({ label: college.name, value: college.id })),
);

// Only show departments belonging to the selected college.
const departmentOptionsFor = (collegeId) =>
    props.departments
        .filter((department) => department.college_id === collegeId)
        .map((department) => ({ label: department.name, value: department.id }));

/* ------------------------------------------------------------------ */
/* Add User modal                                                      */
/* ------------------------------------------------------------------ */

const addUserVisible = ref(false);

const departmentOptions = computed(() => departmentOptionsFor(form.college_id));

const form = useForm({
    role: null,
    first_name: '',
    middle_name: '',
    last_name: '',
    suffix: '',
    email: '',
    password: '',
    password_confirmation: '',
    college_id: null,
    department_ids: [],
    oversees_all_departments: false,
    is_gened_assistant_dean: false,
    status: 'Active',
});

// Dean oversees the whole college — only College is needed.
// OIC may cover any subset of departments — College + Department(s) needed.
const rolesRequiringCollege = ['Dean', 'OIC'];
const rolesRequiringDepartment = ['OIC'];
// Dean/OIC only — lets one of them ALSO carry GenEd/Minor authority
// across every college (on top of, not instead of, their own College
// scope). Assistant Dean is excluded since that role already has this
// reach by default.
const rolesEligibleForGenedFlag = ['Dean', 'OIC'];

const showCollege = computed(() => rolesRequiringCollege.includes(form.role));
const showDepartment = computed(
    () => rolesRequiringDepartment.includes(form.role) && !form.oversees_all_departments,
);
const showGenedFlag = computed(() => rolesEligibleForGenedFlag.includes(form.role));

// Clear department selections if the college changes so a stale
// mismatch (departments from a different college) can't be submitted.
watch(
    () => form.college_id,
    () => {
        form.department_ids = [];
    },
);

// Clear college/department entirely if role no longer requires them.
watch(
    () => form.role,
    () => {
        if (!showCollege.value) {
            form.college_id = null;
        }
        if (!rolesRequiringDepartment.includes(form.role)) {
            form.department_ids = [];
            form.oversees_all_departments = false;
        }
        if (!showGenedFlag.value) {
            form.is_gened_assistant_dean = false;
        }
    },
);

// If the admin checks "oversees all departments", clear the specific picks.
watch(
    () => form.oversees_all_departments,
    (checked) => {
        if (checked) {
            form.department_ids = [];
        }
    },
);

const openAddUser = () => {
    form.reset();
    form.clearErrors();
    addUserVisible.value = true;
};

const closeAddUser = () => {
    addUserVisible.value = false;
    form.reset();
    form.clearErrors();
};

const onCreateUser = () => {
    form.post(route('users.store'), {
        preserveScroll: true,
        onSuccess: () => {
            closeAddUser();
        },
        onError: () => {
            toast.add({
                severity: 'warn',
                summary: 'Missing information',
                detail: 'Please check the highlighted fields and try again.',
                life: 3000,
            });
        },
    });
};

/* ------------------------------------------------------------------ */
/* Edit User modal                                                     */
/* ------------------------------------------------------------------ */

const editUserVisible = ref(false);
const editingUserId = ref(null);

const editForm = useForm({
    role: null,
    first_name: '',
    middle_name: '',
    last_name: '',
    suffix: '',
    email: '',
    password: '',
    password_confirmation: '',
    college_id: null,
    department_ids: [],
    oversees_all_departments: false,
    is_gened_assistant_dean: false,
    status: 'Active',
});

const editDepartmentOptions = computed(() => departmentOptionsFor(editForm.college_id));
const editShowCollege = computed(() => rolesRequiringCollege.includes(editForm.role));
const editShowDepartment = computed(
    () => rolesRequiringDepartment.includes(editForm.role) && !editForm.oversees_all_departments,
);
const editShowGenedFlag = computed(() => rolesEligibleForGenedFlag.includes(editForm.role));

// Clear the flag if the role is edited to one no longer eligible for it.
watch(
    () => editForm.role,
    () => {
        if (!editShowGenedFlag.value) {
            editForm.is_gened_assistant_dean = false;
        }
    },
);

const openEditUser = (user) => {
    editingUserId.value = user.id;
    editForm.reset();
    editForm.clearErrors();
    editForm.role = user.role;
    editForm.first_name = user.firstName ?? '';
    editForm.middle_name = user.middleName ?? '';
    editForm.last_name = user.lastName ?? '';
    editForm.suffix = user.suffix ?? '';
    editForm.email = user.email;
    editForm.password = '';
    editForm.password_confirmation = '';
    editForm.college_id = user.collegeId ?? null;
    editForm.department_ids = user.departmentIds ?? [];
    editForm.oversees_all_departments =
        user.role === 'OIC' && user.department === 'All Departments';
    editForm.is_gened_assistant_dean = user.isGenedAssistantDean ?? false;
    editForm.status = user.status;
    editUserVisible.value = true;
};

const closeEditUser = () => {
    editUserVisible.value = false;
    editingUserId.value = null;
    editForm.reset();
    editForm.clearErrors();
};

const onUpdateUser = () => {
    if (!editingUserId.value) return;

    editForm.transform((data) => ({
        ...data,
        _method: 'put',
    })).post(route('users.update', editingUserId.value), {
        preserveScroll: true,
        onSuccess: () => {
            closeEditUser();
        },
        onError: () => {
            toast.add({
                severity: 'warn',
                summary: 'Missing information',
                detail: 'Please check the highlighted fields and try again.',
                life: 3000,
            });
        },
    });
};

/* ------------------------------------------------------------------ */
/* Row actions menu — Deactivate/Activate, Delete                       */
/* ------------------------------------------------------------------ */

const currentUserId = computed(() => page.props.auth?.user?.id ?? null);

const rowMenuRefs = ref({});
const setRowMenuRef = (userId) => (el) => {
    rowMenuRefs.value[userId] = el;
};
const toggleRowMenu = (userId, event) => rowMenuRefs.value[userId]?.toggle(event);

const rowMenuItemsFor = (user) => {
    const isSelf = user.id === currentUserId.value;

    return [
        {
            label: user.status === 'Active' ? 'Deactivate' : 'Activate',
            icon: user.status === 'Active' ? 'pi pi-ban' : 'pi pi-check-circle',
            disabled: isSelf,
            command: () => onToggleStatus(user),
        },
        {
            label: user.mustChangePassword ? 'Cancel required password change' : 'Require password change on next login',
            icon: 'pi pi-key',
            disabled: isSelf,
            command: () => onToggleMustChangePassword(user),
        },
        {
            separator: true,
        },
        {
            label: 'Delete',
            icon: 'pi pi-trash',
            class: 'text-red-500',
            disabled: isSelf,
            command: () => onDeleteUser(user),
        },
    ];
};

const mustChangePasswordForm = useForm({});
const onToggleMustChangePassword = async (user) => {
    const requiring = !user.mustChangePassword;

    const result = await Swal.fire({
        title: requiring ? 'Require a password change?' : 'Cancel the required password change?',
        text: requiring
            ? `${user.fullName} will be prompted to set a new password the next time they log in. Their access is not affected until then.`
            : `${user.fullName} will no longer be prompted to change their password on next login.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: requiring ? 'Require change' : 'Cancel requirement',
        confirmButtonColor: requiring ? '#D97706' : '#64748B',
        cancelButtonText: 'Close',
    });

    if (!result.isConfirmed) return;

    mustChangePasswordForm.patch(route('users.must-change-password', user.id), { preserveScroll: true });
};

const statusForm = useForm({});
const onToggleStatus = async (user) => {
    const activating = user.status !== 'Active';

    const result = await Swal.fire({
        title: activating ? 'Activate this account?' : 'Deactivate this account?',
        text: activating
            ? `${user.fullName} will regain access to the system.`
            : `${user.fullName} will no longer be able to log in.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: activating ? 'Activate' : 'Deactivate',
        confirmButtonColor: activating ? '#16A34A' : '#DC2626',
        cancelButtonText: 'Cancel',
    });

    if (!result.isConfirmed) return;

    statusForm.patch(route('users.status', user.id), { preserveScroll: true });
};

const deleteUserForm = useForm({});
const onDeleteUser = async (user) => {
    const result = await Swal.fire({
        title: 'Delete this account?',
        text: `${user.fullName} will be permanently removed. This cannot be undone.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Delete',
        confirmButtonColor: '#DC2626',
        cancelButtonText: 'Cancel',
    });

    if (!result.isConfirmed) return;

    deleteUserForm.delete(route('users.destroy', user.id), { preserveScroll: true });
};

/* ------------------------------------------------------------------ */
/* Manage Account tab (Administrator only)                             */
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
    accountForm.transform((data) => ({
        ...data,
        _method: 'put',
    })).post(route('account.update'), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            accountForm.password = '';
            accountForm.password_confirmation = '';
            accountForm.photo = null;
            accountForm.remove_photo = false;
            photoPreviewUrl.value = null;
        },
        onError: () => {
            toast.add({
                severity: 'warn',
                summary: 'Missing information',
                detail: 'Please check the highlighted fields and try again.',
                life: 3000,
            });
        },
    });
};
</script>

<template>
    <Head title="User Management" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">User Management</span>
        </template>

        <div class="w-full" :class="isDark ? 'dark-scope' : ''">
            <!-- Page title + roster: the bar shows who holds which role and doubles as the role filter -->
            <div class="neu-card relative mb-8 rounded-2xl">
                <div class="roster-head">
                    <div class="min-w-0">
                        <h1 class="text-2xl font-bold tracking-tight flex items-center gap-2" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                            User Management
                            <InfoPopover
                                title="User Management"
                                :paragraphs="[
                                    'Manages system accounts and role assignments — who can log in to CLASSLY and what they\'re allowed to do.',
                                ]"
                                :bullets="[
                                    'A user\'s role (Admin, Registrar, Dean/OIC, Faculty, etc.) determines which pages and actions they can access.',
                                    'Deactivating an account blocks login without deleting their records or history.',
                                    'College-scoped roles (e.g. Dean) only see and manage data for their assigned college.',
                                ]"
                            />
                        </h1>
                        <p class="mt-1" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                            Manage system accounts and role assignments.
                        </p>
                    </div>
                    <p class="roster-total" :class="isDark ? 'text-slate-300' : 'text-slate-600'">
                        <span class="roster-total-num">{{ users.length }}</span>
                        {{ users.length === 1 ? 'account' : 'accounts' }},
                        <span class="roster-total-active">{{ activeCount }} active</span>
                    </p>
                </div>

                <div v-if="groupSegments.length" class="roster-body">
                    <div class="roster-bar" role="img" :aria-label="groupSegments.map((g) => `${g.count} in ${g.label}`).join(', ')">
                        <span
                            v-for="seg in groupSegments"
                            :key="seg.key"
                            class="roster-seg"
                            :style="{ flexGrow: seg.count, background: seg.color }"
                            :title="`${seg.label}: ${seg.count}`"
                        ></span>
                    </div>
                    <div class="roster-chips" role="group" aria-label="Filter by college">
                        <button
                            type="button"
                            class="roster-chip"
                            :class="{ 'is-on': groupFilter === 'All' }"
                            :aria-pressed="groupFilter === 'All'"
                            :style="{ '--chip': '#334155', '--chip-on': '#ffffff' }"
                            @click="groupFilter = 'All'"
                        >
                            Everyone <b>{{ users.length }}</b>
                        </button>
                        <button
                            v-for="seg in groupSegments"
                            :key="seg.key"
                            type="button"
                            class="roster-chip"
                            :class="{ 'is-on': groupFilter === seg.key }"
                            :aria-pressed="groupFilter === seg.key"
                            :style="{ '--chip': seg.color, '--chip-on': onColor(seg.color) }"
                            @click="groupFilter = groupFilter === seg.key ? 'All' : seg.key"
                        >
                            <i class="roster-dot"></i>{{ seg.label }} <b>{{ seg.count }}</b>
                        </button>
                    </div>
                </div>
            </div>

            <Tabs v-model:value="activeTab">
                <TabList>
                    <Tab value="users">Users</Tab>
                    <Tab v-if="isAdministrator" value="account">Manage Account</Tab>
                </TabList>

                <TabPanels>
                    <!-- Users Tab -->
                    <TabPanel value="users">
                        <div class="neu-card rounded-2xl transition-colors duration-300">
                        <Card
                            class="!rounded-2xl !bg-transparent !border-0 !shadow-none max-w-full"
                            :pt="{ body: { class: '!bg-transparent' } }"
                        >
                            <template #content>
                                <!-- Top Toolbar -->
                                <Toolbar class="!bg-transparent !border-0 !px-0 !pt-0 !pb-4">
                                    <template #start>
                                        <span class="relative w-full sm:w-80 mr-3">
                                            <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-sm" :class="isDark ? 'text-slate-500' : 'text-slate-400'"></i>
                                            <InputText
                                                v-model="search"
                                                placeholder="Search by name or email"
                                                class="neu-inset w-full !rounded-xl !border-none !pl-9"
                                                :class="isDark ? '!text-white placeholder:!text-slate-500' : ''"
                                            />
                                        </span>
                                        <select v-model="roleFilter" class="role-select" aria-label="Filter by role">
                                            <option value="All">All roles</option>
                                            <option v-for="o in roleOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                                        </select>
                                    </template>
                                    <template #end>
                                        <div class="flex items-center gap-3">
                                            <div class="view-toggle" role="group" aria-label="Layout">
                                                <button
                                                    type="button"
                                                    :class="{ 'is-on': viewMode === 'cards' }"
                                                    :aria-pressed="viewMode === 'cards'"
                                                    title="ID cards"
                                                    @click="viewMode = 'cards'"
                                                >
                                                    <i class="pi pi-id-card"></i><span>Cards</span>
                                                </button>
                                                <button
                                                    type="button"
                                                    :class="{ 'is-on': viewMode === 'table' }"
                                                    :aria-pressed="viewMode === 'table'"
                                                    title="Table"
                                                    @click="viewMode = 'table'"
                                                >
                                                    <i class="pi pi-table"></i><span>Table</span>
                                                </button>
                                            </div>
                                            <Button label="Add User" icon="pi pi-plus" @click="openAddUser" />
                                        </div>
                                    </template>
                                </Toolbar>

                                <!-- ID-card layout: one academy ID per account -->
                                <div v-if="viewMode === 'cards'">
                                    <div v-if="filteredUsers.length" class="id-grid">
                                        <article
                                            v-for="data in filteredUsers"
                                            :key="data.id"
                                            class="id-card"
                                            :class="{ 'is-inactive': data.status !== 'Active' }"
                                            :style="{ '--role': cardColor(data), '--on': onColor(cardColor(data)) }"
                                        >
                                            <header class="id-band">
                                                <span class="id-slot" aria-hidden="true"></span>
                                                <span class="id-role">{{ data.role }}</span>
                                                <span
                                                    v-if="data.isFaculty"
                                                    class="id-faculty"
                                                    v-tooltip.top="'Linked to a Faculty record — manage roles from the Faculty page'"
                                                >
                                                    <i class="pi pi-graduation-cap"></i>Faculty
                                                </span>
                                            </header>

                                            <div class="id-avatar">
                                                <img
                                                    v-if="data.photoUrl && !brokenPhotos[data.id]"
                                                    :src="data.photoUrl"
                                                    :alt="`${titleCase(data.fullName)}'s profile photo`"
                                                    class="id-avatar-img"
                                                    loading="lazy"
                                                    @error="onPhotoError(data.id)"
                                                />
                                                <span v-else aria-hidden="true">{{ initialsFor(data) }}</span>
                                            </div>

                                            <div class="id-main">
                                                <h3 class="id-name">{{ titleCase(data.fullName) }}</h3>
                                                <p class="id-college">{{ data.college || 'System-wide' }}</p>
                                                <p v-if="data.department" class="id-dept">{{ data.department }}</p>
                                            </div>

                                            <button type="button" class="id-email" :title="`Copy ${data.email}`" @click="copyEmail(data)">
                                                <i class="pi pi-envelope"></i>
                                                <span>{{ data.email }}</span>
                                                <i class="pi pi-copy id-email-copy"></i>
                                            </button>

                                            <footer class="id-foot">
                                                <div class="id-status">
                                                    <span class="id-state" :class="data.status === 'Active' ? 'is-active' : 'is-off'">
                                                        <i></i>{{ data.status }}
                                                    </span>
                                                    <span v-if="data.mustChangePassword" class="id-pw" title="This user must set a new password at next sign-in">
                                                        <i class="pi pi-key"></i>Password change required
                                                    </span>
                                                </div>
                                                <div class="id-actions">
                                                    <Button
                                                        icon="pi pi-pencil"
                                                        text
                                                        rounded
                                                        severity="secondary"
                                                        size="small"
                                                        :aria-label="`Edit ${titleCase(data.fullName)}`"
                                                        @click="openEditUser(data)"
                                                    />
                                                    <Button
                                                        icon="pi pi-ellipsis-v"
                                                        text
                                                        rounded
                                                        severity="secondary"
                                                        size="small"
                                                        :aria-label="`More actions for ${titleCase(data.fullName)}`"
                                                        @click="toggleRowMenu(data.id, $event)"
                                                    />
                                                    <Menu :ref="setRowMenuRef(data.id)" :model="rowMenuItemsFor(data)" :popup="true" />
                                                </div>
                                            </footer>
                                        </article>
                                    </div>
                                    <div v-else class="id-empty">
                                        <p class="font-medium">No accounts match.</p>
                                        <p class="text-sm mt-1">Clear the search or choose another role.</p>
                                        <Button label="Show everyone" size="small" outlined class="mt-3" @click="clearFilters" />
                                    </div>
                                </div>

                                <!-- Users Table -->
                                <DataTable
                                    v-else
                                    :value="filteredUsers"
                                    class="neu-inset neu-table rounded-xl overflow-hidden"
                                    :class="isDark ? 'neu-table-dark' : ''"
                                    stripedRows
                                    responsiveLayout="scroll"
                                >
                                    <template #empty>
                                        <div class="text-center py-10">
                                            <p class="text-slate-500 font-medium">No users found.</p>
                                            <p class="text-sm text-slate-400 mt-1">Create your first account.</p>
                                        </div>
                                    </template>

                                    <Column field="fullName" header="Full Name" />
                                    <Column field="email" header="Email" />
                                    <Column field="role" header="Role">
                                        <template #body="{ data }">
                                            <span>{{ data.role }}</span>
                                            <Tag
                                                v-if="data.isFaculty"
                                                value="Faculty"
                                                severity="info"
                                                class="ml-2"
                                                v-tooltip.top="'Linked to a Faculty record — manage roles from the Faculty page'"
                                            />
                                        </template>
                                    </Column>
                                    <Column field="college" header="College" />
                                    <Column field="department" header="Department" />
                                    <Column field="status" header="Status">
                                        <template #body="{ data }">
                                            <Tag
                                                :value="data.status"
                                                :severity="data.status === 'Active' ? 'success' : 'secondary'"
                                            />
                                            <Tag
                                                v-if="data.mustChangePassword"
                                                value="Password change required"
                                                severity="warn"
                                                class="ml-1"
                                            />
                                        </template>
                                    </Column>
                                    <Column header="Actions" style="width: 9rem">
                                        <template #body="{ data }">
                                            <div class="flex gap-1">
                                                <Button
                                                    icon="pi pi-pencil"
                                                    text
                                                    rounded
                                                    severity="secondary"
                                                    size="small"
                                                    @click="openEditUser(data)"
                                                />
                                                <Button
                                                    icon="pi pi-ellipsis-v"
                                                    text
                                                    rounded
                                                    severity="secondary"
                                                    size="small"
                                                    @click="toggleRowMenu(data.id, $event)"
                                                />
                                                <Menu :ref="setRowMenuRef(data.id)" :model="rowMenuItemsFor(data)" :popup="true" />
                                            </div>
                                        </template>
                                    </Column>
                                </DataTable>
                            </template>
                        </Card>
                        </div>
                    </TabPanel>

                    <!-- Manage Account Tab (Administrator only) -->
                    <TabPanel v-if="isAdministrator" value="account">
                        <div class="pt-2">
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
                        </div>
                    </TabPanel>
                </TabPanels>
            </Tabs>
        </div>

        <!-- Add User Modal -->
        <Dialog
            v-model:visible="addUserVisible"
            modal
            :style="{ width: '800px', ...(isDark ? {} : { backgroundColor: 'var(--neu-card-light)' }) }"
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
                <span class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Create System User</span>
            </template>

            <form class="pt-2 neu-form" autocomplete="off" @submit.prevent="onCreateUser">
                <!-- Role -->
                <div class="grid grid-cols-1 gap-5">
                    <FloatLabel variant="on">
                        <Select
                            id="role"
                            v-model="form.role"
                            :options="roleOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :invalid="!!form.errors.role"
                        />
                        <label for="role">Role *</label>
                    </FloatLabel>
                </div>
                <small v-if="form.errors.role" class="text-red-500">{{ form.errors.role }}</small>
                <small class="block text-slate-500 mt-1">
                    Already a faculty member? Assign this role from the Faculty page instead. Use this form only for
                    people who are not on the Faculty roster.
                </small>

                <Divider class="!my-5" />

                <!-- First Name / Middle Name -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <FloatLabel variant="on">
                        <InputText
                            id="firstName"
                            v-model="form.first_name"
                            v-uppercase
                            class="w-full"
                            autocomplete="off"
                            :invalid="!!form.errors.first_name"
                        />
                        <label for="firstName">First Name *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <InputText
                            id="middleName"
                            v-model="form.middle_name"
                            v-uppercase
                            class="w-full"
                            autocomplete="off"
                        />
                        <label for="middleName">Middle Name</label>
                    </FloatLabel>
                </div>

                <!-- Last Name / Suffix -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            id="lastName"
                            v-model="form.last_name"
                            v-uppercase
                            class="w-full"
                            autocomplete="off"
                            :invalid="!!form.errors.last_name"
                        />
                        <label for="lastName">Last Name *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <InputText
                            id="suffix"
                            v-model="form.suffix"
                            v-uppercase
                            class="w-full"
                            autocomplete="off"
                        />
                        <label for="suffix">Suffix</label>
                    </FloatLabel>
                </div>

                <Divider class="!my-5" />

                <!-- Email -->
                <div class="grid grid-cols-1 gap-5">
                    <FloatLabel variant="on">
                        <InputText
                            id="email"
                            v-model="form.email"
                            type="email"
                            class="w-full"
                            autocomplete="off"
                            :invalid="!!form.errors.email"
                        />
                        <label for="email">Email *</label>
                    </FloatLabel>
                    <small v-if="form.errors.email" class="text-red-500 -mt-4">{{ form.errors.email }}</small>
                </div>

                <!-- Password / Confirm Password -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <Password
                            id="password"
                            v-model="form.password"
                            toggleMask
                            :feedback="true"
                            inputClass="w-full"
                            class="w-full"
                            autocomplete="new-password"
                            :invalid="!!form.errors.password"
                        />
                        <label for="password">Password *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <Password
                            id="confirmPassword"
                            v-model="form.password_confirmation"
                            toggleMask
                            :feedback="false"
                            inputClass="w-full"
                            class="w-full"
                            autocomplete="new-password"
                            :invalid="!!form.errors.password"
                        />
                        <label for="confirmPassword">Confirm Password *</label>
                    </FloatLabel>
                </div>
                <small v-if="form.errors.password" class="text-red-500">{{ form.errors.password }}</small>

                <template v-if="showCollege">
                    <Divider class="!my-5" />

                    <!-- College (Dean / OIC) / Department (OIC only) -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <FloatLabel variant="on">
                            <Select
                                id="college"
                                v-model="form.college_id"
                                :options="collegeOptions"
                                optionLabel="label"
                                optionValue="value"
                                class="w-full"
                                :invalid="!!form.errors.college_id"
                            />
                            <label for="college">College *</label>
                        </FloatLabel>

                        <FloatLabel v-if="showDepartment" variant="on">
                            <MultiSelect
                                id="department"
                                v-model="form.department_ids"
                                :options="departmentOptions"
                                optionLabel="label"
                                optionValue="value"
                                display="chip"
                                class="w-full"
                                :disabled="!form.college_id"
                                :invalid="!!form.errors.department_ids"
                            />
                            <label for="department">Department(s) *</label>
                        </FloatLabel>
                    </div>
                    <small v-if="form.errors.department_ids" class="text-red-500">{{ form.errors.department_ids }}</small>

                    <!-- OIC scope: whole college vs a specific subset of departments -->
                    <div v-if="rolesRequiringDepartment.includes(form.role)" class="flex items-center gap-2 mt-4">
                        <Checkbox
                            id="oversees_all_departments"
                            v-model="form.oversees_all_departments"
                            binary
                            :disabled="!form.college_id"
                        />
                        <label for="oversees_all_departments" class="text-sm text-slate-600">
                            Oversees all departments in this college
                        </label>
                    </div>
                    <p class="text-xs text-slate-400 mt-1" v-if="showDepartment">
                        Select one or more departments this OIC will cover.
                    </p>

                    <!-- Additive GenEd/Minor authority (Dean/OIC only) -->
                    <div v-if="showGenedFlag" class="flex items-center gap-2 mt-4">
                        <Checkbox
                            id="is_gened_assistant_dean"
                            v-model="form.is_gened_assistant_dean"
                            binary
                            :disabled="!form.college_id"
                        />
                        <label for="is_gened_assistant_dean" class="text-sm text-slate-600">
                            Also manage GenEd/Minor subjects across all colleges
                        </label>
                    </div>
                    <p class="text-xs text-slate-400 mt-1" v-if="showGenedFlag">
                        Keeps this user's own College scope and additionally grants Assistant
                        Dean authority over GenEd/Minor subjects institution-wide.
                    </p>
                </template>

                <Divider class="!my-5" />

                <!-- Status -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <FloatLabel variant="on">
                        <Select
                            id="status"
                            v-model="form.status"
                            :options="statusOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :invalid="!!form.errors.status"
                        />
                        <label for="status">Status *</label>
                    </FloatLabel>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" text severity="secondary" :disabled="form.processing" @click="closeAddUser" />
                <Button
                    label="Create User"
                    icon="pi pi-check"
                    :loading="form.processing"
                    @click="onCreateUser"
                />
            </template>
        </Dialog>

        <!-- Edit User Modal -->
        <Dialog
            v-model:visible="editUserVisible"
            modal
            :style="{ width: '800px', ...(isDark ? {} : { backgroundColor: 'var(--neu-card-light)' }) }"
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
                <span class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Edit System User</span>
            </template>

            <form class="pt-2 neu-form" autocomplete="off" @submit.prevent="onUpdateUser">
                <!-- Role -->
                <div class="grid grid-cols-1 gap-5">
                    <FloatLabel variant="on">
                        <Select
                            id="editRole"
                            v-model="editForm.role"
                            :options="roleOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :invalid="!!editForm.errors.role"
                        />
                        <label for="editRole">Role *</label>
                    </FloatLabel>
                </div>
                <small v-if="editForm.errors.role" class="text-red-500">{{ editForm.errors.role }}</small>

                <Divider class="!my-5" />

                <!-- First Name / Middle Name -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <FloatLabel variant="on">
                        <InputText
                            id="editFirstName"
                            v-model="editForm.first_name"
                            v-uppercase
                            class="w-full"
                            autocomplete="off"
                            :invalid="!!editForm.errors.first_name"
                        />
                        <label for="editFirstName">First Name *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <InputText
                            id="editMiddleName"
                            v-model="editForm.middle_name"
                            v-uppercase
                            class="w-full"
                            autocomplete="off"
                        />
                        <label for="editMiddleName">Middle Name</label>
                    </FloatLabel>
                </div>

                <!-- Last Name / Suffix -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            id="editLastName"
                            v-model="editForm.last_name"
                            v-uppercase
                            class="w-full"
                            autocomplete="off"
                            :invalid="!!editForm.errors.last_name"
                        />
                        <label for="editLastName">Last Name *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <InputText
                            id="editSuffix"
                            v-model="editForm.suffix"
                            v-uppercase
                            class="w-full"
                            autocomplete="off"
                        />
                        <label for="editSuffix">Suffix</label>
                    </FloatLabel>
                </div>

                <Divider class="!my-5" />

                <!-- Email -->
                <div class="grid grid-cols-1 gap-5">
                    <FloatLabel variant="on">
                        <InputText
                            id="editEmail"
                            v-model="editForm.email"
                            type="email"
                            class="w-full"
                            autocomplete="off"
                            :invalid="!!editForm.errors.email"
                        />
                        <label for="editEmail">Email *</label>
                    </FloatLabel>
                    <small v-if="editForm.errors.email" class="text-red-500 -mt-4">{{ editForm.errors.email }}</small>
                </div>

                <!-- Password / Confirm Password (optional on edit) -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <Password
                            id="editPassword"
                            v-model="editForm.password"
                            toggleMask
                            :feedback="true"
                            inputClass="w-full"
                            class="w-full"
                            autocomplete="new-password"
                            :invalid="!!editForm.errors.password"
                        />
                        <label for="editPassword">New Password</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <Password
                            id="editConfirmPassword"
                            v-model="editForm.password_confirmation"
                            toggleMask
                            :feedback="false"
                            inputClass="w-full"
                            class="w-full"
                            autocomplete="new-password"
                            :invalid="!!editForm.errors.password"
                        />
                        <label for="editConfirmPassword">Confirm New Password</label>
                    </FloatLabel>
                </div>
                <small v-if="editForm.errors.password" class="text-red-500">{{ editForm.errors.password }}</small>
                <p class="text-xs text-slate-400 mt-1">Leave blank to keep the current password.</p>

                <template v-if="editShowCollege">
                    <Divider class="!my-5" />

                    <!-- College (Dean / OIC) / Department (OIC only) -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <FloatLabel variant="on">
                            <Select
                                id="editCollege"
                                v-model="editForm.college_id"
                                :options="collegeOptions"
                                optionLabel="label"
                                optionValue="value"
                                class="w-full"
                                :invalid="!!editForm.errors.college_id"
                            />
                            <label for="editCollege">College *</label>
                        </FloatLabel>

                        <FloatLabel v-if="editShowDepartment" variant="on">
                            <MultiSelect
                                id="editDepartment"
                                v-model="editForm.department_ids"
                                :options="editDepartmentOptions"
                                optionLabel="label"
                                optionValue="value"
                                display="chip"
                                class="w-full"
                                :disabled="!editForm.college_id"
                                :invalid="!!editForm.errors.department_ids"
                            />
                            <label for="editDepartment">Department(s) *</label>
                        </FloatLabel>
                    </div>
                    <small v-if="editForm.errors.department_ids" class="text-red-500">{{ editForm.errors.department_ids }}</small>

                    <!-- OIC scope: whole college vs a specific subset of departments -->
                    <div v-if="rolesRequiringDepartment.includes(editForm.role)" class="flex items-center gap-2 mt-4">
                        <Checkbox
                            id="editOverseesAllDepartments"
                            v-model="editForm.oversees_all_departments"
                            binary
                            :disabled="!editForm.college_id"
                        />
                        <label for="editOverseesAllDepartments" class="text-sm text-slate-600">
                            Oversees all departments in this college
                        </label>
                    </div>
                    <p class="text-xs text-slate-400 mt-1" v-if="editShowDepartment">
                        Select one or more departments this OIC will cover.
                    </p>

                    <!-- Additive GenEd/Minor authority (Dean/OIC only) -->
                    <div v-if="editShowGenedFlag" class="flex items-center gap-2 mt-4">
                        <Checkbox
                            id="editIsGenedAssistantDean"
                            v-model="editForm.is_gened_assistant_dean"
                            binary
                            :disabled="!editForm.college_id"
                        />
                        <label for="editIsGenedAssistantDean" class="text-sm text-slate-600">
                            Also manage GenEd/Minor subjects across all colleges
                        </label>
                    </div>
                    <p class="text-xs text-slate-400 mt-1" v-if="editShowGenedFlag">
                        Keeps this user's own College scope and additionally grants Assistant
                        Dean authority over GenEd/Minor subjects institution-wide.
                    </p>
                </template>

                <Divider class="!my-5" />

                <!-- Status -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <FloatLabel variant="on">
                        <Select
                            id="editStatus"
                            v-model="editForm.status"
                            :options="statusOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :invalid="!!editForm.errors.status"
                        />
                        <label for="editStatus">Status *</label>
                    </FloatLabel>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" text severity="secondary" :disabled="editForm.processing" @click="closeEditUser" />
                <Button
                    label="Save Changes"
                    icon="pi pi-check"
                    :loading="editForm.processing"
                    @click="onUpdateUser"
                />
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

/* ------------------------------------------------------------------ */
/* Glassmorphism — Card                                                */
/* Plain CSS (no Tailwind arbitrary-value classes) so this reliably    */
/* beats PrimeVue's own injected component styles regardless of        */
/* cascade-layer/build quirks. app-glass-card sits on the Card's own   */
/* root element (it IS .p-card), so plain class selectors below target */
/* it directly — no :deep() needed for the root itself.                */
/* ------------------------------------------------------------------ */
.app-glass-card {
    border-radius: 1rem !important;
    border-width: 1px !important;
    border-style: solid !important;
    margin-top: 1.25rem;
    backdrop-filter: blur(24px);
    -webkit-backdrop-filter: blur(24px);
    transition: background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
}
.app-glass-card.is-light {
    background: rgba(255, 255, 255, 0.6) !important;
    border-color: rgba(255, 255, 255, 0.7) !important;
    box-shadow: 0 8px 32px rgba(30, 41, 59, 0.08);
}
.app-glass-card.is-dark {
    background: rgba(255, 255, 255, 0.05) !important;
    border-color: rgba(255, 255, 255, 0.1) !important;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.35);
}
/* The Card body/content wrapper inside it must stay transparent so the
   translucency above actually shows through. */
:deep(.app-glass-card .p-card-body),
:deep(.app-glass-card .p-card-content) {
    background: transparent !important;
}

/* ------------------------------------------------------------------ */
/* Glassmorphism — Dialogs (Add/Edit User)                             */
/* Dialog is teleported through PrimeVue's internal Portal/Transition   */
/* wrappers, several component layers removed from this file's own     */
/* template. Vue's scoped "data-v-xxxx" attribute isn't guaranteed to   */
/* propagate that deep, so scoped selectors (even :deep()) can silently */
/* match nothing. :global() sidesteps that entirely — these rules key   */
/* off the app-glass-dialog/is-dark/is-light classes we pass in via pt, */
/* with no dependency on Vue's scope attribute being present.           */
/* ------------------------------------------------------------------ */
:global(.app-glass-dialog) {
    backdrop-filter: blur(24px);
    -webkit-backdrop-filter: blur(24px);
    border-width: 1px !important;
    border-style: solid !important;
    border-radius: 1rem !important;
}
:global(.app-glass-dialog.is-light) {
    background: rgba(255, 255, 255, 0.8) !important;
    border-color: rgba(255, 255, 255, 0.6) !important;
    box-shadow: 0 8px 32px rgba(30, 41, 59, 0.18);
}
:global(.app-glass-dialog.is-dark) {
    background: rgba(15, 23, 48, 0.8) !important;
    border-color: rgba(255, 255, 255, 0.12) !important;
    box-shadow: 0 8px 40px rgba(0, 0, 0, 0.5);
    color: #F8FAFC !important;
}
:global(.app-glass-dialog .p-dialog-header),
:global(.app-glass-dialog .p-dialog-content),
:global(.app-glass-dialog .p-dialog-footer) {
    background: transparent !important;
}
:global(.app-glass-dialog.is-light .p-dialog-header) { border-bottom: 1px solid rgba(30, 41, 59, 0.08) !important; }
:global(.app-glass-dialog.is-light .p-dialog-footer) { border-top: 1px solid rgba(30, 41, 59, 0.08) !important; }
:global(.app-glass-dialog.is-dark .p-dialog-header) { border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
:global(.app-glass-dialog.is-dark .p-dialog-content) { color: #F8FAFC !important; }
:global(.app-glass-dialog.is-dark .p-dialog-footer) { border-top: 1px solid rgba(255, 255, 255, 0.1) !important; }

/* Inputs inside the dialog get a frosted look matching each mode. */
:global(.app-glass-dialog.is-light .p-inputtext),
:global(.app-glass-dialog.is-light .p-password-input),
:global(.app-glass-dialog.is-light .p-select),
:global(.app-glass-dialog.is-light .p-multiselect) {
    background: rgba(255, 255, 255, 0.6) !important;
    border-color: rgba(30, 41, 59, 0.12) !important;
}
:global(.app-glass-dialog.is-dark .p-inputtext),
:global(.app-glass-dialog.is-dark .p-password-input),
:global(.app-glass-dialog.is-dark .p-select),
:global(.app-glass-dialog.is-dark .p-multiselect),
:global(.app-glass-dialog.is-dark .p-inputnumber-input) {
    background: rgba(255, 255, 255, 0.06) !important;
    border-color: rgba(255, 255, 255, 0.15) !important;
    color: #F8FAFC !important;
}
:global(.app-glass-dialog.is-dark .p-select-label),
:global(.app-glass-dialog.is-dark .p-multiselect-label) { color: #F8FAFC !important; }
:global(.app-glass-dialog.is-dark .p-divider.p-divider-horizontal:before) { border-color: rgba(255, 255, 255, 0.1) !important; }

.dark-scope :deep(.p-tablist) { background: transparent !important; border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.p-tab) { color: #94A3B8 !important; }
.dark-scope :deep(.p-tab-active) { color: #F8FAFC !important; }

/* ------------------------------------------------------------------ */
/* Roster header: role bar + filter chips                              */
/* ------------------------------------------------------------------ */
.roster-head {
    display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between;
    gap: 0.75rem 1.5rem; padding: 1.5rem 1.5rem 1rem;
}
.roster-total { font-size: 0.9rem; }
.roster-total-num { font-size: 1.5rem; font-weight: 700; margin-right: 0.15rem; color: #1E293B; }
.roster-total-active { font-weight: 600; color: #047857; }
.dark-scope .roster-total-num { color: #F8FAFC; }
.dark-scope .roster-total-active { color: #34D399; }
.roster-body { padding: 0 1.5rem 1.35rem; }
.roster-bar { display: flex; gap: 3px; height: 0.5rem; border-radius: 9999px; overflow: hidden; }
.roster-seg { min-width: 0.75rem; border-radius: 9999px; }
.roster-chips { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.9rem; }
.roster-chip {
    display: inline-flex; align-items: center; gap: 0.45rem;
    padding: 0.35rem 0.8rem; border-radius: 9999px;
    font-size: 0.8rem; font-weight: 600; cursor: pointer;
    --chip-on: #fff;
    color: color-mix(in srgb, var(--chip) 55%, #000);
    background: color-mix(in srgb, var(--chip) 12%, transparent);
    border: 1px solid color-mix(in srgb, var(--chip) 30%, transparent);
    transition: background-color .15s, color .15s, border-color .15s;
}
.roster-chip b { font-weight: 700; padding-left: 0.35rem; border-left: 1px solid color-mix(in srgb, currentColor 30%, transparent); }
.roster-chip:hover { background: color-mix(in srgb, var(--chip) 18%, transparent); }
.roster-chip.is-on { background: var(--chip); border-color: var(--chip); color: var(--chip-on); }
.roster-chip:focus-visible { outline: 2px solid var(--chip); outline-offset: 2px; }
.roster-dot { width: 0.6rem; height: 0.6rem; border-radius: 9999px; background: var(--chip); box-shadow: 0 0 0 1px color-mix(in srgb, var(--chip) 50%, #000 20%); }
.roster-chip.is-on .roster-dot { background: var(--chip-on); }

.role-select {
    height: 2.5rem; padding: 0 0.75rem; border-radius: 0.75rem; cursor: pointer;
    font-size: 0.85rem; font-weight: 500; color: #334155;
    background: rgba(100, 116, 139, 0.1); border: 1px solid transparent;
}
.role-select:focus-visible { outline: 2px solid #4f46e5; outline-offset: 1px; }
.dark-scope .role-select { color: #E2E8F0; background: rgba(255, 255, 255, 0.08); }
.dark-scope .role-select option { color: #0F172A; }

/* Cards / Table toggle */
.view-toggle { display: inline-flex; padding: 3px; border-radius: 0.75rem; background: rgba(100, 116, 139, 0.12); }
.view-toggle button {
    display: inline-flex; align-items: center; gap: 0.4rem;
    padding: 0.4rem 0.75rem; border-radius: 0.55rem; border: 0; background: transparent; cursor: pointer;
    font-size: 0.8rem; font-weight: 600; color: #475569; transition: background-color .15s, color .15s;
}
.view-toggle button.is-on { background: #fff; color: #1E293B; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.18); }
.view-toggle button:focus-visible { outline: 2px solid #4f46e5; outline-offset: 1px; }
.dark-scope .view-toggle { background: rgba(255, 255, 255, 0.08); }
.dark-scope .view-toggle button { color: #94A3B8; }
.dark-scope .view-toggle button.is-on { background: rgba(255, 255, 255, 0.16); color: #F8FAFC; box-shadow: none; }
@media (max-width: 640px) { .view-toggle button span { display: none; } }

/* ------------------------------------------------------------------ */
/* ID cards — one per account. The band carries the role colour and a  */
/* lanyard slot; the avatar straddles the band edge like a badge photo. */
/* ------------------------------------------------------------------ */
.id-grid { display: grid; gap: 1.25rem; grid-template-columns: repeat(auto-fill, minmax(18rem, 1fr)); }
.id-card {
    --role: #475569;
    position: relative; display: flex; flex-direction: column;
    border-radius: 1rem; overflow: hidden;
    background: #fff;
    border: 1px solid rgba(100, 116, 139, 0.22);
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
}
.id-card:focus-within { border-color: var(--role); }
.id-band {
    position: relative; display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;
    height: 4.5rem; padding: 1.3rem 1rem 0; align-items: flex-start;
    background:
        linear-gradient(180deg, rgba(255, 255, 255, 0.14), transparent 70%),
        var(--role);
    color: var(--on, #fff);
}
.id-slot {
    position: absolute; top: 0.5rem; left: 50%; transform: translateX(-50%);
    width: 2.6rem; height: 0.5rem; border-radius: 9999px;
    background: color-mix(in srgb, var(--role) 55%, #000);
    box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.35);
}
.id-role { font-size: 0.85rem; font-weight: 700; letter-spacing: 0.01em; }
.id-faculty {
    display: inline-flex; align-items: center; gap: 0.3rem;
    padding: 0.15rem 0.55rem; border-radius: 9999px;
    font-size: 0.7rem; font-weight: 600;
    background: color-mix(in srgb, var(--on, #fff) 22%, transparent);
}
.id-avatar {
    position: relative; z-index: 1;
    display: flex; align-items: center; justify-content: center;
    width: 3.75rem; height: 3.75rem; margin: -1.5rem 0 0 1rem;
    border-radius: 9999px;
    font-size: 1.15rem; font-weight: 700; color: color-mix(in srgb, var(--role) 60%, #000);
    overflow: hidden;
    background: color-mix(in srgb, var(--role) 12%, #fff);
    border: 3px solid #fff;
    box-shadow: 0 0 0 1px color-mix(in srgb, var(--role) 35%, transparent);
}
.id-avatar-img { width: 100%; height: 100%; object-fit: cover; display: block; }
.id-main { padding: 0.65rem 1rem 0; min-height: 4.6rem; }
.id-name { font-size: 1.05rem; font-weight: 700; line-height: 1.25; color: #1E293B; overflow-wrap: anywhere; }
.id-college { margin-top: 0.2rem; font-size: 0.85rem; font-weight: 500; color: #334155; }
.id-dept { font-size: 0.8rem; color: #64748B; }
.id-email {
    display: flex; align-items: center; gap: 0.5rem; text-align: left;
    margin: 0.85rem 1rem 0.9rem; padding: 0.5rem 0.65rem; border-radius: 0.6rem;
    font-size: 0.8rem; color: #334155; cursor: pointer;
    background: rgba(100, 116, 139, 0.08);
    border: 1px dashed rgba(100, 116, 139, 0.35);
    transition: border-color .15s, background-color .15s;
}
.id-email span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.id-email .pi { font-size: 0.8rem; color: #64748B; }
.id-email-copy { opacity: 0; transition: opacity .15s; }
.id-email:hover, .id-email:focus-visible { border-color: var(--role); background: color-mix(in srgb, var(--role) 8%, transparent); outline: none; }
.id-email:hover .id-email-copy, .id-email:focus-visible .id-email-copy { opacity: 1; }
.id-foot {
    display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;
    padding: 0.7rem 0.6rem 0.7rem 1rem;
    border-top: 1px solid rgba(100, 116, 139, 0.18);
    margin-top: auto;
}
.id-status { display: flex; flex-direction: column; gap: 0.2rem; min-width: 0; }
.id-state { display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.8rem; font-weight: 600; }
.id-state i { width: 0.55rem; height: 0.55rem; border-radius: 9999px; background: currentColor; }
.id-state.is-active { color: #047857; }
.id-state.is-off { color: #64748B; }
.id-pw { display: inline-flex; align-items: center; gap: 0.3rem; font-size: 0.72rem; font-weight: 600; color: #b45309; }
.id-actions { display: flex; align-items: center; flex-shrink: 0; }
.id-card.is-inactive .id-band { filter: grayscale(0.85); opacity: 0.8; }
.id-card.is-inactive .id-avatar { filter: grayscale(0.85); }
.id-card.is-inactive .id-name { color: #64748B; }
.id-empty { padding: 3rem 1rem; text-align: center; color: #64748B; }

.dark-scope .id-card { background: rgba(255, 255, 255, 0.05); border-color: rgba(255, 255, 255, 0.12); box-shadow: none; }
.dark-scope .id-avatar { background: color-mix(in srgb, var(--role) 30%, #0F1730); color: #fff; border-color: #17213f; }
.dark-scope .roster-chip:not(.is-on) { color: color-mix(in srgb, var(--chip) 40%, #fff); }
.dark-scope .id-name { color: #F8FAFC; }
.dark-scope .id-college { color: #CBD5E1; }
.dark-scope .id-dept { color: #94A3B8; }
.dark-scope .id-email { color: #CBD5E1; background: rgba(255, 255, 255, 0.05); border-color: rgba(255, 255, 255, 0.2); }
.dark-scope .id-email .pi { color: #94A3B8; }
.dark-scope .id-foot { border-top-color: rgba(255, 255, 255, 0.1); }
.dark-scope .id-state.is-active { color: #34D399; }
.dark-scope .id-state.is-off { color: #94A3B8; }
.dark-scope .id-pw { color: #FBBF24; }
.dark-scope .id-card.is-inactive .id-name { color: #94A3B8; }
.dark-scope .id-empty { color: #94A3B8; }

/* The TabPanels wrapper paints its own opaque white background + padding
   by default — that was hiding the glass Card sitting inside it. Strip
   it out in both modes so the Card's own translucent surface is what
   the user actually sees. */
:deep(.p-tabpanels) {
    background: transparent !important;
    padding: 0 !important;
}
:deep(.p-tabpanel) {
    background: transparent !important;
}

.dark-scope :deep(.p-datatable-thead > tr > th) { background: rgba(255, 255, 255, 0.04) !important; color: #CBD5E1 !important; border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.p-datatable-tbody > tr) { background: transparent !important; color: #E2E8F0 !important; }
.dark-scope :deep(.p-datatable-tbody > tr.p-datatable-row-striped) { background: rgba(255, 255, 255, 0.03) !important; }
.dark-scope :deep(.p-datatable-tbody > tr > td) { border-color: rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.p-datatable-tbody > tr:hover) { background: rgba(255, 255, 255, 0.06) !important; }
.dark-scope :deep(.p-paginator) { background: transparent !important; color: #CBD5E1 !important; }

.dark-scope :deep(.p-menu) { background: #0F1730 !important; border-color: rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-menu .p-menu-item-link) { color: #E2E8F0 !important; }
.dark-scope :deep(.p-menu .p-menu-item-link:hover) { background: rgba(255, 255, 255, 0.06) !important; }
</style>