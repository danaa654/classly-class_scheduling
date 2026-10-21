<script setup>
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import Swal from 'sweetalert2';
import { useToast } from 'primevue/usetoast';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackLink from '@/Components/BackLink.vue';
import InputText from 'primevue/inputtext';
import Textarea from 'primevue/textarea';
import Select from 'primevue/select';
import MultiSelect from 'primevue/multiselect';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import Toast from 'primevue/toast';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const props = defineProps({
    curriculum: { type: Object, required: true },
    items: { type: Array, default: () => [] },
    availableSubjects: { type: Array, default: () => [] },
    allSubjects: { type: Array, default: () => [] },
});

const toast = useToast();
const page = usePage();

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

/* ------------------------------------------------------------------ */
/* Static option lists (fixed display order)                           */
/* ------------------------------------------------------------------ */

const yearLevels = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
const semesters = ['First Semester', 'Second Semester', 'Summer'];

const yearLevelOptions = yearLevels.map((label) => ({ label, value: label }));
const semesterOptions = semesters.map((label) => ({ label, value: label }));

/* ------------------------------------------------------------------ */
/* Grouping / sorting                                                  */
/* ------------------------------------------------------------------ */

// "Allow sorting by Year Level, Semester" — this toggles which level of
// the grouped display is the outer grouping.
const sortBy = ref('year_level'); // 'year_level' | 'semester'

const sortByOptions = [
    { label: 'Year Level', value: 'year_level' },
    { label: 'Semester', value: 'semester' },
];

const groupedSections = computed(() => {
    const outer = sortBy.value === 'year_level' ? yearLevels : semesters;
    const inner = sortBy.value === 'year_level' ? semesters : yearLevels;
    const outerField = sortBy.value === 'year_level' ? 'year_level' : 'semester';
    const innerField = sortBy.value === 'year_level' ? 'semester' : 'year_level';

    return outer.map((outerValue) => ({
        label: outerValue,
        subsections: inner.map((innerValue) => ({
            label: innerValue,
            rows: props.items
                .filter((item) => item[outerField] === outerValue && item[innerField] === innerValue)
                .sort((a, b) => (a.subject?.subject_code ?? '').localeCompare(b.subject?.subject_code ?? '')),
        })),
    }));
});

/* ------------------------------------------------------------------ */
/* Add / Edit dialog                                                   */
/* ------------------------------------------------------------------ */

const dialogVisible = ref(false);
const editingItem = ref(null); // null => Add mode, otherwise the item being edited

// Add mode places one or more Subjects at once (checkbox multi-select),
// sharing one Year Level / Semester / Remarks, via addForm. Edit mode
// still targets a single CurriculumItem with its own Subject +
// Prerequisite fields, via editForm. Two separate useForm instances keep
// their field shapes (and Inertia's per-request transforms) independent.
const addForm = useForm({
    subject_ids: [],
    year_level: null,
    semester: null,
    remarks: '',
});

const editForm = useForm({
    subject_id: null,
    year_level: null,
    semester: null,
    prerequisite_subject_id: null,
    remarks: '',
});

// `form` is a read-only alias to whichever form is active, used by the
// shared Year Level / Semester / Remarks fields and error messages in the
// template so those markup blocks don't need to branch on mode.
const form = computed(() => (editingItem.value ? editForm : addForm));

// Subject options for Add mode (checkbox multi-select): every Subject not
// yet placed in this curriculum.
const subjectOptions = computed(() =>
    props.availableSubjects.map((subject) => ({
        label: `${subject.subject_code} — ${subject.subject_title}`,
        value: subject.id,
    })),
);

// Subject options for Edit mode: same list, but keep the item's own
// current subject available since it's excluded from availableSubjects.
const editSubjectOptions = computed(() => {
    const base = props.availableSubjects;
    const options = editingItem.value && editingItem.value.subject
        ? [editingItem.value.subject, ...base]
        : base;

    return options.map((subject) => ({
        label: `${subject.subject_code} — ${subject.subject_title}`,
        value: subject.id,
    }));
});

// Prerequisite dropdown: any master subject except the one currently
// selected as the Subject (a subject can't be its own prerequisite).
const prerequisiteOptions = computed(() =>
    props.allSubjects
        .filter((subject) => subject.id !== editForm.subject_id)
        .map((subject) => ({
            label: `${subject.subject_code} — ${subject.subject_title}`,
            value: subject.id,
        })),
);

const openAdd = () => {
    editingItem.value = null;
    addForm.clearErrors();
    addForm.reset();
    dialogVisible.value = true;
};

const openEdit = (item) => {
    editingItem.value = item;
    editForm.clearErrors();
    editForm.subject_id = item.subject_id;
    editForm.year_level = item.year_level;
    editForm.semester = item.semester;
    editForm.prerequisite_subject_id = item.prerequisite_subject_id;
    editForm.remarks = item.remarks ?? '';
    dialogVisible.value = true;
};

const closeDialog = () => {
    dialogVisible.value = false;
    editingItem.value = null;
    addForm.clearErrors();
    addForm.reset();
    editForm.clearErrors();
    editForm.reset();
};

const onSave = () => {
    if (editingItem.value) {
        editForm.put(route('curriculums.subjects.update', [props.curriculum.id, editingItem.value.id]), {
            preserveScroll: true,
            onSuccess: () => closeDialog(),
        });
    } else {
        addForm.post(route('curriculums.subjects.store', props.curriculum.id), {
            preserveScroll: true,
            onSuccess: () => closeDialog(),
        });
    }
};

const onDelete = (item) => {
    Swal.fire({
        title: 'Remove this subject?',
        text: `${item.subject?.subject_code} will be removed from this curriculum. The subject itself will not be deleted.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, remove it',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('curriculums.subjects.destroy', [props.curriculum.id, item.id]), {
                preserveScroll: true,
            });
        }
    });
};

/* ------------------------------------------------------------------ */
/* Display helpers                                                      */
/* ------------------------------------------------------------------ */

// Empty Summer terms are hidden so the Year Level view isn't padded
// with a blank third column; a Summer that has subjects still shows.
const visibleSubsections = (section) =>
    section.subsections.filter((sub) => !(sub.label === 'Summer' && sub.rows.length === 0));

const unitsOf = (rows) => rows.reduce((sum, row) => sum + (Number(row.subject?.units) || 0), 0);
const formatUnits = (value) => Number(value.toFixed(2));

const sectionTotals = (section) => {
    const rows = section.subsections.flatMap((sub) => sub.rows);
    return { count: rows.length, units: unitsOf(rows) };
};

const totalSubjects = computed(() => props.items.length);
const totalUnits = computed(() => unitsOf(props.items));

// Subject code badge color: Major = blue, General Education / Minor =
// violet (one color for both), Practicum / OJT = orange. Practicum wins
// over the category, so OJT subjects stand out whatever their category.
const categoryLegend = [
    { key: 'Major', label: 'Major' },
    { key: 'General Education', label: 'General Education / Minor' },
    { key: 'practicum', label: 'Practicum / OJT' },
];
const categoryBadge = (category, subjectType = null) => {
    const d = isDark.value;
    if (subjectType === 'practicum' || category === 'practicum') return d ? 'bg-orange-500/25 text-orange-200' : 'bg-orange-100 text-orange-800';
    if (category === 'Major') return d ? 'bg-blue-500/25 text-blue-200' : 'bg-blue-100 text-blue-800';
    if (category === 'General Education' || category === 'Minor') return d ? 'bg-violet-500/25 text-violet-200' : 'bg-violet-100 text-violet-800';
    return d ? 'bg-white/10 text-slate-300' : 'bg-slate-200 text-slate-700';
};

const curriculumStatusPill = (status) => {
    const d = isDark.value;
    if (status === 'Active') return d ? 'bg-emerald-500/20 text-emerald-300' : 'bg-emerald-200/70 text-emerald-800';
    if (status === 'Archived') return d ? 'bg-white/10 text-slate-300' : 'bg-slate-200 text-slate-700';
    return d ? 'bg-yellow-500/20 text-yellow-300' : 'bg-yellow-200/80 text-yellow-900';
};
</script>

<template>
    <Head title="Curriculum Subjects" />

    <AppLayout>
        <template #header>
            <span class="text-lg font-semibold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Curriculum</span>
        </template>

        <div class="w-full" :class="isDark ? 'dark-scope' : ''">
            <!-- Header -->
            <div class="neu-card mb-4 rounded-2xl px-5 py-4 transition-colors duration-300">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="text-2xl font-bold leading-tight tracking-tight" :class="isDark ? 'text-white' : 'text-[#1E293B]'">{{ curriculum.name }}</h1>
                            <span v-if="curriculum.status" class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="curriculumStatusPill(curriculum.status)">{{ curriculum.status }}</span>
                        </div>
                        <p class="mt-1 text-sm" :class="isDark ? 'text-slate-400' : 'text-slate-500'">{{ curriculum.major?.name || 'No major' }}</p>
                    </div>
                    <BackLink :href="route('curriculums')" label="Back to Curriculums" />
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold tabular-nums" :class="isDark ? 'bg-white/10 text-slate-200' : 'bg-slate-100 text-slate-700'">
                        <i class="pi pi-calendar text-[10px]"></i>{{ curriculum.start_year }} – {{ curriculum.end_year }}
                    </span>
                    <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="isDark ? 'bg-blue-500/20 text-blue-200' : 'bg-blue-100 text-blue-800'">
                        {{ totalSubjects }} {{ totalSubjects === 1 ? 'subject' : 'subjects' }}
                    </span>
                    <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="isDark ? 'bg-emerald-500/20 text-emerald-200' : 'bg-emerald-100 text-emerald-800'">
                        {{ formatUnits(totalUnits) }} units
                    </span>
                </div>
            </div>

            <div class="neu-card rounded-2xl p-4 transition-colors duration-300">
                <!-- Toolbar -->
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                        <div class="flex items-center gap-2">
                            <span class="text-sm" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Group by</span>
                            <div class="neu-inset inline-flex rounded-xl p-1" role="group" aria-label="Group subjects by">
                                <button
                                    v-for="option in sortByOptions"
                                    :key="option.value"
                                    type="button"
                                    class="rounded-lg px-3 py-1 text-sm font-medium transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-500"
                                    :class="sortBy === option.value
                                        ? (isDark ? 'bg-blue-500/30 text-blue-100' : 'bg-white text-blue-700 shadow-sm')
                                        : (isDark ? 'text-slate-400 hover:text-slate-200' : 'text-slate-500 hover:text-slate-700')"
                                    :aria-pressed="sortBy === option.value"
                                    @click="sortBy = option.value"
                                >{{ option.label }}</button>
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5 text-xs">
                            <span v-for="legend in categoryLegend" :key="legend.key" class="rounded-md px-2 py-0.5 font-semibold" :class="categoryBadge(legend.key)">{{ legend.label }}</span>
                        </div>
                    </div>
                    <Button label="Add subject" icon="pi pi-plus" severity="success" @click="openAdd" />
                </div>

                <div class="space-y-6">
                    <section v-for="section in groupedSections" :key="section.label">
                        <div class="mb-2 flex flex-wrap items-center gap-2 border-b pb-2" :class="isDark ? 'border-white/10' : 'border-slate-200'">
                            <h2 class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">{{ section.label }}</h2>
                            <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="isDark ? 'bg-white/10 text-slate-300' : 'bg-slate-100 text-slate-600'">
                                {{ sectionTotals(section).count }} {{ sectionTotals(section).count === 1 ? 'subject' : 'subjects' }}
                            </span>
                            <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="isDark ? 'bg-emerald-500/20 text-emerald-300' : 'bg-emerald-100 text-emerald-700'">
                                {{ formatUnits(sectionTotals(section).units) }} units
                            </span>
                        </div>

                        <p v-if="sectionTotals(section).count === 0" class="py-2 pl-1 text-sm italic" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                            No subjects added yet.
                        </p>

                        <div v-else class="subject-grid grid gap-3">
                            <div
                                v-for="sub in visibleSubsections(section)"
                                :key="sub.label"
                                class="rounded-xl p-3"
                                :class="isDark ? 'bg-white/[0.03] ring-1 ring-white/10' : 'bg-slate-50 ring-1 ring-slate-200/70'"
                            >
                                <div class="mb-2 flex items-center justify-between gap-2">
                                    <h3 class="text-sm font-semibold" :class="isDark ? 'text-slate-200' : 'text-slate-700'">{{ sub.label }}</h3>
                                    <span class="text-xs tabular-nums" :class="isDark ? 'text-slate-500' : 'text-slate-400'">{{ sub.rows.length }} · {{ formatUnits(unitsOf(sub.rows)) }} units</span>
                                </div>

                                <p v-if="sub.rows.length === 0" class="rounded-lg border border-dashed px-3 py-4 text-center text-sm" :class="isDark ? 'border-white/10 text-slate-500' : 'border-slate-300 text-slate-400'">
                                    No subjects yet.
                                </p>

                                <ul v-else class="space-y-1.5">
                                    <li v-for="row in sub.rows" :key="row.id" class="subject-li">
                                        <div class="subject-row rounded-lg px-2.5 py-2" :class="isDark ? 'bg-white/5 ring-1 ring-white/10' : 'bg-white ring-1 ring-slate-200/70'">
                                            <span
                                                class="subject-code inline-flex min-w-[3.75rem] justify-center rounded-md px-2 py-0.5 text-xs font-bold"
                                                :class="categoryBadge(row.subject?.category, row.subject?.subject_type)"
                                                :title="row.subject?.subject_type === 'practicum' ? 'Practicum / OJT' : row.subject?.category"
                                            >{{ row.subject?.subject_code }}</span>

                                            <p class="subject-title break-words text-sm font-medium leading-snug" :class="isDark ? 'text-slate-100' : 'text-slate-800'">{{ row.subject?.subject_title }}</p>

                                            <div class="subject-meta flex flex-wrap items-center gap-1.5 text-[11px] font-medium">
                                                <span class="rounded px-1.5 py-0.5" :class="isDark ? 'bg-emerald-500/20 text-emerald-300' : 'bg-emerald-100 text-emerald-800'">{{ row.subject?.units }} units</span>
                                                <template v-if="row.subject?.subject_type === 'practicum'">
                                                    <span class="inline-flex items-center gap-1 rounded px-1.5 py-0.5" :class="isDark ? 'bg-orange-500/20 text-orange-300' : 'bg-orange-100 text-orange-800'">
                                                        <i class="pi pi-map-marker text-[9px]"></i>Practicum / OJT
                                                    </span>
                                                    <span v-if="row.subject?.required_hours" class="rounded px-1.5 py-0.5" :class="isDark ? 'bg-white/10 text-slate-300' : 'bg-slate-100 text-slate-600'">{{ row.subject.required_hours }} hrs required</span>
                                                </template>
                                                <template v-else>
                                                    <span class="rounded px-1.5 py-0.5" :class="isDark ? 'bg-white/10 text-slate-300' : 'bg-slate-100 text-slate-600'">Lec {{ row.subject?.lecture_hours }}</span>
                                                    <span class="rounded px-1.5 py-0.5" :class="isDark ? 'bg-white/10 text-slate-300' : 'bg-slate-100 text-slate-600'">Lab {{ row.subject?.laboratory_hours }}</span>
                                                </template>
                                                <span v-if="row.prerequisite" class="inline-flex items-center gap-1 rounded px-1.5 py-0.5" :class="isDark ? 'bg-sky-500/20 text-sky-300' : 'bg-sky-100 text-sky-800'" title="Prerequisite">
                                                    <i class="pi pi-link text-[9px]"></i>{{ row.prerequisite.subject_code }}
                                                </span>
                                            </div>

                                            <div class="subject-actions flex items-center">
                                                <Button icon="pi pi-pencil" text rounded severity="secondary" size="small" aria-label="Edit subject" title="Edit" @click="openEdit(row)" />
                                                <Button icon="pi pi-trash" text rounded severity="danger" size="small" aria-label="Remove subject" title="Remove" @click="onDelete(row)" />
                                            </div>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>

        <!-- Add / Edit Subject Dialog -->
        <Dialog
            v-model:visible="dialogVisible"
            :header="editingItem ? 'Edit Curriculum Subject' : 'Add Subject'"
            modal
            class="w-full max-w-lg"
            :style="isDark ? {} : { backgroundColor: 'var(--neu-card-light)' }"
            :pt="{
                root: { class: isDark ? '!bg-[#141D33] !border !border-white/10 !text-white !rounded-2xl !shadow-2xl dark-scope' : '!border !border-[rgba(30,41,59,0.06)] !rounded-2xl !shadow-2xl' },
                header: { class: isDark ? '!bg-[#141D33] !border-b !border-white/10 !rounded-t-2xl' : '!rounded-t-2xl' },
                content: { class: isDark ? '!bg-[#141D33]' : '' },
                footer: { class: isDark ? '!bg-[#141D33] !border-t !border-white/10 !rounded-b-2xl' : '!rounded-b-2xl' },
            }"
            @hide="closeDialog"
        >
            <div class="flex flex-col gap-4 neu-form">
                <!-- Curriculum (read only) -->
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">Curriculum</label>
                    <InputText
                        :modelValue="`${curriculum.name} (${curriculum.start_year}–${curriculum.end_year})`"
                        readonly
                        disabled
                        class="w-full"
                    />
                </div>

                <!-- Subject: checkbox multi-select in Add mode, single select in Edit mode -->
                <div v-if="!editingItem" class="flex flex-col gap-1">
                    <label for="subject_ids" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        Subjects <span class="text-red-500">*</span>
                    </label>
                    <MultiSelect
                        id="subject_ids"
                        v-model="addForm.subject_ids"
                        :options="subjectOptions"
                        optionLabel="label"
                        optionValue="value"
                        display="chip"
                        filter
                        appendTo="self"
                        filterPlaceholder="Search subject code or title"
                        placeholder="Select one or more subjects"
                        :showToggleAll="true"
                        :invalid="!!addForm.errors.subject_ids"
                        class="w-full"
                        :pt="{
                            overlay: { class: isDark ? 'dark-scope' : '', style: { width: '100%', maxWidth: '100%' } },
                        }"
                    />
                    <small v-if="addForm.errors.subject_ids" class="text-red-500">
                        {{ addForm.errors.subject_ids }}
                    </small>
                    <small :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                        Check every subject that belongs to this Year Level and Semester — they'll all be added at once. Set a prerequisite for a subject afterward using Edit.
                    </small>
                </div>

                <div v-else class="flex flex-col gap-1">
                    <label for="subject_id" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        Subject <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="subject_id"
                        v-model="editForm.subject_id"
                        :options="editSubjectOptions"
                        optionLabel="label"
                        optionValue="value"
                        filter
                        appendTo="self"
                        filterPlaceholder="Search subject code or title"
                        placeholder="Select a subject"
                        :invalid="!!editForm.errors.subject_id"
                        class="w-full"
                        :pt="{
                            overlay: { class: isDark ? 'dark-scope' : '', style: { width: '100%', maxWidth: '100%' } },
                        }"
                    />
                    <small v-if="editForm.errors.subject_id" class="text-red-500">
                        {{ editForm.errors.subject_id }}
                    </small>
                </div>

                <!-- Year Level / Semester -->
                <div class="grid grid-cols-2 gap-4">
                    <div class="flex flex-col gap-1">
                        <label for="year_level" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                            Year Level <span class="text-red-500">*</span>
                        </label>
                        <Select
                            id="year_level"
                            v-model="form.year_level"
                            :options="yearLevelOptions"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Select year level"
                            :invalid="!!form.errors.year_level"
                            class="w-full"
                            :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                        />
                        <small v-if="form.errors.year_level" class="text-red-500">
                            {{ form.errors.year_level }}
                        </small>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="semester" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                            Semester <span class="text-red-500">*</span>
                        </label>
                        <Select
                            id="semester"
                            v-model="form.semester"
                            :options="semesterOptions"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Select semester"
                            :invalid="!!form.errors.semester"
                            class="w-full"
                            :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                        />
                        <small v-if="form.errors.semester" class="text-red-500">
                            {{ form.errors.semester }}
                        </small>
                    </div>
                </div>

                <!-- Prerequisite (Edit mode only — a bulk Add covers several Subjects) -->
                <div v-if="editingItem" class="flex flex-col gap-1">
                    <label for="prerequisite_subject_id" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        Prerequisite
                    </label>
                    <Select
                        id="prerequisite_subject_id"
                        v-model="editForm.prerequisite_subject_id"
                        :options="prerequisiteOptions"
                        optionLabel="label"
                        optionValue="value"
                        filter
                        filterPlaceholder="Search subject code or title"
                        placeholder="None"
                        showClear
                        :invalid="!!editForm.errors.prerequisite_subject_id"
                        class="w-full"
                        :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                    />
                    <small v-if="editForm.errors.prerequisite_subject_id" class="text-red-500">
                        {{ editForm.errors.prerequisite_subject_id }}
                    </small>
                </div>

                <!-- Remarks -->
                <div class="flex flex-col gap-1">
                    <label for="remarks" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">Remarks</label>
                    <Textarea
                        id="remarks"
                        v-model="form.remarks"
                        rows="3"
                        placeholder="Optional notes about this placement"
                        :invalid="!!form.errors.remarks"
                        class="w-full"
                    />
                    <small v-if="form.errors.remarks" class="text-red-500">
                        {{ form.errors.remarks }}
                    </small>
                </div>
            </div>

            <template #footer>
                <Button label="Cancel" text severity="secondary" :disabled="form.processing" @click="closeDialog" />
                <Button label="Save" icon="pi pi-check" :loading="form.processing" @click="onSave" />
            </template>
        </Dialog>

        <Toast />
    </AppLayout>
</template>

<style scoped>

/* Semester columns: as many as fit the space beside the sidebar (each at
   least 26rem), stretching to share the full width. */
.subject-grid {
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 26rem), 1fr));
}

/* Subject row: stacked (title, then chips) while narrow; once the row
   itself is wide enough, the chips move up beside the title so there is
   no empty gap. Uses a container query on the row, not the viewport, so
   it adapts to the sidebar and the number of columns automatically. */
.subject-li { container-type: inline-size; }
.subject-row {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    grid-template-areas:
        "code title actions"
        ".    meta  actions";
    column-gap: 0.625rem;
    row-gap: 0.25rem;
    align-items: start;
}
.subject-code { grid-area: code; margin-top: 0.125rem; }
.subject-title { grid-area: title; }
.subject-meta { grid-area: meta; }
.subject-actions { grid-area: actions; align-self: center; }

@container (min-width: 40rem) {
    .subject-row {
        grid-template-columns: auto minmax(0, 1fr) auto auto;
        grid-template-areas: "code title meta actions";
        align-items: center;
    }
    .subject-meta { justify-content: flex-end; }
}
/* Dark-mode overrides. Wrapping an element in the "dark-scope" class
   (added conditionally via isDark) recolors the shared light-mode
   Tailwind utility classes and PrimeVue component chrome used
   throughout this page. The Dialog is teleported to <body> by
   PrimeVue, so its rules use :global() with a compound selector
   instead of :deep() — Vue's scoped-CSS attribute doesn't reliably
   travel through the teleport boundary. */
.dark-scope :deep(.text-\[\#1E293B\]) { color: #F8FAFC !important; }
.dark-scope :deep(.text-slate-700) { color: #CBD5E1 !important; }
.dark-scope :deep(.text-slate-600) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-500) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-400) { color: #64748B !important; }
.dark-scope :deep(.bg-slate-50) { background-color: rgba(255, 255, 255, 0.05) !important; }
.dark-scope :deep(.border-slate-100) { border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.border-slate-200) { border-color: rgba(255, 255, 255, 0.12) !important; }
.dark-scope :deep(.divide-slate-100 > :not([hidden]) ~ :not([hidden])) { border-color: rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(tr.hover\:bg-slate-50:hover) { background-color: rgba(255, 255, 255, 0.06) !important; }

.dark-scope :deep(.p-card) { background: #101A35 !important; color: #F8FAFC; border: 1px solid rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.p-card .p-card-body) { background: transparent !important; }

.dark-scope :deep(.p-inputtext),
.dark-scope :deep(.p-select),
.dark-scope :deep(.p-multiselect) {
    background: rgba(255, 255, 255, 0.06) !important;
    border-color: rgba(255, 255, 255, 0.15) !important;
    color: #F8FAFC !important;
}
.dark-scope :deep(.p-select-label),
.dark-scope :deep(.p-multiselect-label) { color: #F8FAFC !important; }

.dark-scope :deep(.p-button-text.p-button-secondary) { color: #CBD5E1 !important; }
.dark-scope :deep(.p-button-text.p-button-secondary:hover) { background: rgba(255, 255, 255, 0.08) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-button-text.p-button-danger) { color: #FCA5A5 !important; }
.dark-scope :deep(.p-button-text.p-button-danger:hover) { background: rgba(248, 113, 113, 0.12) !important; color: #FECACA !important; }

/* Add / Edit Subject modal (teleported to <body>) */
:global(.dark-scope.p-dialog) { background: #0F1730 !important; color: #F8FAFC !important; border: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-dialog .p-dialog-header) { background: #0F1730 !important; border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-title) { color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-content) { background: #0F1730 !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-footer) { background: #0F1730 !important; border-top: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-dialog .p-dialog-close-button) { color: #CBD5E1 !important; }
:global(.dark-scope.p-dialog .p-dialog-close-button:hover) { background: rgba(255, 255, 255, 0.08) !important; color: #F8FAFC !important; }

:global(.dark-scope.p-dialog label) { color: #CBD5E1 !important; }
:global(.dark-scope.p-dialog .p-inputtext),
:global(.dark-scope.p-dialog .p-textarea),
:global(.dark-scope.p-dialog .p-select),
:global(.dark-scope.p-dialog .p-multiselect) {
    background: rgba(255, 255, 255, 0.06) !important;
    border-color: rgba(255, 255, 255, 0.18) !important;
    color: #F8FAFC !important;
}
:global(.dark-scope.p-dialog .p-inputtext::placeholder),
:global(.dark-scope.p-dialog .p-textarea::placeholder) { color: #7C8CA8 !important; }
:global(.dark-scope.p-dialog .p-inputtext:disabled) { background: rgba(255, 255, 255, 0.03) !important; color: #94A3B8 !important; }
:global(.dark-scope.p-dialog .p-select-label),
:global(.dark-scope.p-dialog .p-multiselect-label) { color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-select-label.p-placeholder),
:global(.dark-scope.p-dialog .p-multiselect-label.p-placeholder) { color: #7C8CA8 !important; }

:global(.p-select-overlay.dark-scope),
:global(.p-multiselect-overlay.dark-scope) { background: #0F1730 !important; border: 1px solid rgba(255, 255, 255, 0.12) !important; color: #F8FAFC !important; }
:global(.p-select-overlay.dark-scope .p-select-option),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-option) { color: #F1F5F9 !important; }
:global(.p-select-overlay.dark-scope .p-select-option:hover),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-option:hover) { background: rgba(255, 255, 255, 0.08) !important; }
:global(.p-select-overlay.dark-scope .p-select-filter),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-filter) { background: rgba(255, 255, 255, 0.06) !important; border-color: rgba(255, 255, 255, 0.15) !important; color: #F8FAFC !important; }
</style>