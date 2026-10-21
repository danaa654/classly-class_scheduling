<script setup>
/**
 * AVAILABILITY PANEL — Section Subjects page header "Faculty" / "Rooms".
 *
 * A floating, draggable, NON-modal side panel (about the size of the
 * notification dropdown) so the scheduler can keep the subject table in
 * view while looking up who/what is free — no trip to the Faculty or Room
 * pages, and no modal covering the page.
 *
 * Read-only look-up. Each Faculty member / Room is a compact card: name,
 * workload, a one-row availability bar for the chosen day (one segment per
 * 30 minutes, occupied segments hatched so state never relies on colour
 * alone) and an expandable list of occupied + free times. Optionally enter a
 * start/end to see who is free for exactly that time, and tick "Only
 * available" to hide the rest.
 *
 * Data + rules are identical to AvailabilityModal.vue: one read of
 * scheduling.section-subjects.availability, this Section's own unsaved rows
 * overlaid so they block slots too, and "occupied" uses the same overlap
 * rule Save validates with (existing.start < new.end AND existing.end >
 * new.start).
 */
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import Select from 'primevue/select';
import InputText from 'primevue/inputtext';
import ProgressSpinner from 'primevue/progressspinner';

const props = defineProps({
    visible: { type: Boolean, default: false },
    view: { type: String, default: 'faculty' }, // 'faculty' | 'rooms'
    sectionId: { type: Number, required: true },
    sectionCode: { type: String, default: '' },
    sectionRows: { type: Array, default: () => [] },
    schedulingWindow: { type: Object, required: true },
    sectionCollegeId: { type: Number, default: null },
    sectionCollegeName: { type: String, default: null },
    isDark: { type: Boolean, default: false },
});
const emit = defineEmits(['update:visible', 'update:view']);

/* ------------------------------------------------------------------ */
/* Time helpers                                                        */
/* ------------------------------------------------------------------ */

const STEP = 30;
const ALL_DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const toMin = (hhmm) => {
    if (!hhmm) return null;
    const [h, m] = String(hhmm).slice(0, 5).split(':').map(Number);
    return h * 60 + m;
};
const fromMin = (min) => `${String(Math.floor(min / 60)).padStart(2, '0')}:${String(min % 60).padStart(2, '0')}`;
const fmt = (v) => {
    const min = typeof v === 'number' ? v : toMin(v);
    if (min == null) return '—';
    const h = Math.floor(min / 60);
    const m = min % 60;
    return `${h % 12 === 0 ? 12 : h % 12}:${String(m).padStart(2, '0')} ${h >= 12 ? 'PM' : 'AM'}`;
};
const fmtRange = (s, e) => `${fmt(s)}–${fmt(e)}`;

const windowStart = computed(() => toMin(props.schedulingWindow.start_time));
const windowEnd = computed(() => toMin(props.schedulingWindow.end_time));
const allowedDays = computed(() => {
    const allowed = props.schedulingWindow?.available_days;
    return Array.isArray(allowed) && allowed.length ? ALL_DAYS.filter((d) => allowed.includes(d)) : ALL_DAYS.slice(0, 6);
});
const slots = computed(() => {
    const out = [];
    for (let m = windowStart.value; m < windowEnd.value; m += STEP) out.push(m);
    return out;
});
const timeChoices = computed(() => {
    const out = [];
    for (let m = windowStart.value; m <= windowEnd.value; m += STEP) out.push({ label: fmt(m), value: fromMin(m) });
    return out;
});

/* ------------------------------------------------------------------ */
/* Data                                                                */
/* ------------------------------------------------------------------ */

const loading = ref(false);
const loadError = ref('');
const data = reactive({ faculty: [], rooms: [] });

const load = async () => {
    loading.value = true;
    loadError.value = '';
    try {
        const url = new URL(route('scheduling.section-subjects.availability', props.sectionId), window.location.origin);
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const json = await response.json();
        data.faculty = json.faculty ?? [];
        data.rooms = json.rooms ?? [];
    } catch (e) {
        loadError.value = 'Could not load availability. Please try again.';
    } finally {
        loading.value = false;
    }
};

const filters = reactive({ day: 'Mon', start: null, end: null, search: '', building: null, floor: null, onlyAvailable: false });
const showMore = reactive({});
const expanded = reactive({});

const todayDay = () => ALL_DAYS[(new Date().getDay() + 6) % 7];
watch(
    () => props.visible,
    (open) => {
        if (!open) return;
        if (!filters.day || !allowedDays.value.includes(filters.day)) filters.day = allowedDays.value.includes(todayDay()) ? todayDay() : allowedDays.value[0];
        load();
    },
    { immediate: true }
);

const tab = computed({ get: () => props.view, set: (v) => emit('update:view', v) });
watch(tab, () => { filters.search = ''; filters.building = null; filters.floor = null; });
watch(() => filters.building, () => { filters.floor = null; });
watch(() => filters.start, (v) => { if (v && filters.end && filters.end <= v) filters.end = null; });

/* ------------------------------------------------------------------ */
/* Timetable (this Section's unsaved rows overlaid)                    */
/* ------------------------------------------------------------------ */

const subjectCode = (r) => r?.subject?.subject_code ?? 'Class';

const blocksFor = (kind, res) => {
    const liveIds = new Set(props.sectionRows.map((r) => r.id));
    const kept = res.blocks.filter((b) => !liveIds.has(b.id));
    const live = [];
    for (const r of props.sectionRows) {
        if (r.is_merged) continue;
        const matches = kind === 'f' ? r.faculty_id === res.id : r.room_id === res.id;
        if (!matches || !r.days?.length || !r.start_time || !r.end_time) continue;
        for (const d of r.days) live.push({ id: r.id, d, s: String(r.start_time).slice(0, 5), e: String(r.end_time).slice(0, 5), l: `${subjectCode(r)} · ${props.sectionCode}` });
    }
    return [...kept, ...live];
};

// key -> [block|null per 30-min slot] for the chosen day
const occupancy = computed(() => {
    const map = new Map();
    const build = (kind, res) => {
        const arr = new Array(slots.value.length).fill(null);
        for (const b of blocksFor(kind, res)) {
            if (b.d !== filters.day) continue;
            const bs = toMin(b.s);
            const be = toMin(b.e);
            slots.value.forEach((t, i) => {
                if (bs < t + STEP && be > t && !arr[i]) arr[i] = b;
            });
        }
        map.set(`${kind}-${res.id}`, arr);
    };
    data.faculty.forEach((f) => build('f', f));
    data.rooms.forEach((r) => build('r', r));
    return map;
});
const stripFor = (kind, res) => occupancy.value.get(`${kind}-${res.id}`) ?? [];

const range = computed(() => {
    const s = toMin(filters.start);
    const e = toMin(filters.end);
    return s != null && e != null && e > s ? { s, e } : null;
});
const inRange = (i) => !!range.value && slots.value[i] >= range.value.s && slots.value[i] < range.value.e;
// 'free' | 'busy' | null (no range chosen)
const statusFor = (kind, res) => {
    if (!range.value) return null;
    const strip = stripFor(kind, res);
    return slots.value.some((t, i) => t >= range.value.s && t < range.value.e && strip[i]) ? 'busy' : 'free';
};

const freeRanges = (kind, res) => {
    const strip = stripFor(kind, res);
    const out = [];
    let cur = null;
    slots.value.forEach((t, i) => {
        if (strip[i]) { cur = null; return; }
        if (cur && cur.end === t) cur.end = t + STEP;
        else { cur = { start: t, end: t + STEP }; out.push(cur); }
    });
    return out;
};
const weekBlocks = (kind, res) =>
    blocksFor(kind, res).sort((a, b) => ALL_DAYS.indexOf(a.d) - ALL_DAYS.indexOf(b.d) || toMin(a.s) - toMin(b.s));
const dayBlocks = (kind, res) => weekBlocks(kind, res).filter((b) => b.d === filters.day);

/* ------------------------------------------------------------------ */
/* Scope groups + workload                                             */
/* ------------------------------------------------------------------ */

const isGenEdFaculty = (f) => f.faculty_category === 'General Education Faculty' || f.college_id === null || !!f.college_counts_as_gened;
const facultyGroupKey = (f) => (props.sectionCollegeId !== null && f.college_id === props.sectionCollegeId ? 'f-college' : isGenEdFaculty(f) ? 'f-gened' : 'f-other');
const roomOtherCollege = (r) => !!r.college_id && props.sectionCollegeId !== null && r.college_id !== props.sectionCollegeId;
const roomGroupKey = (r) => (roomOtherCollege(r) ? 'r-other' : 'r-main');

const buildStats = (list, keyFn, countFn) => {
    const groups = {};
    list.forEach((x) => (groups[keyFn(x)] ??= []).push(countFn(x)));
    return Object.fromEntries(Object.entries(groups).map(([k, v]) => [k, { avg: v.reduce((a, b) => a + b, 0) / v.length, spread: Math.max(...v) - Math.min(...v) }]));
};
const facultyStats = computed(() => buildStats(data.faculty, facultyGroupKey, (f) => f.subject_count));
const roomStats = computed(() => buildStats(data.rooms, (r) => `${roomGroupKey(r)}|${r.type ?? ''}`, (r) => r.class_count));
// Only compare within a group, and only when its numbers actually differ.
const level = (count, st) => {
    if (!st || st.spread === 0) return null;
    if (count > st.avg * 1.25 && count - st.avg >= 1) return 'higher';
    if (count < st.avg * 0.75) return 'lower';
    return null;
};
const workload = (kind, res) => level(kind === 'f' ? res.subject_count : res.class_count, kind === 'f' ? facultyStats.value[facultyGroupKey(res)] : roomStats.value[`${roomGroupKey(res)}|${res.type ?? ''}`]);
const atLimit = (f) => f.max_units != null && Number(f.current_load) >= Number(f.max_units);

/* ------------------------------------------------------------------ */
/* Filtering + grouping                                                */
/* ------------------------------------------------------------------ */

const buildings = computed(() => [...new Set(data.rooms.map((r) => r.building).filter(Boolean))].sort().map((b) => ({ label: b, value: b })));
const floors = computed(() => {
    const pool = filters.building ? data.rooms.filter((r) => r.building === filters.building) : data.rooms;
    return [...new Set(pool.map((r) => r.floor).filter((f) => f != null && f !== ''))].sort().map((f) => ({ label: `Floor ${f}`, value: f }));
});

const sortRes = (kind) => (a, b) => {
    const fa = statusFor(kind, a) === 'busy' ? 1 : 0;
    const fb = statusFor(kind, b) === 'busy' ? 1 : 0;
    const la = kind === 'f' ? a.subject_count : a.class_count;
    const lb = kind === 'f' ? b.subject_count : b.class_count;
    return fa - fb || la - lb || (kind === 'f' ? a.name.localeCompare(b.name) : a.code.localeCompare(b.code));
};

const searching = computed(() => filters.search.trim() !== '' || !!filters.building || !!filters.floor);
const makeGroups = (kind, list, descs, keyFn) => {
    const built = descs.map((d) => ({ ...d, all: list.filter((x) => keyFn(x) === d.key).sort(sortRes(kind)) })).filter((g) => g.all.length);
    const hasPrimary = built.some((g) => !g.collapsible);
    return built.map((g) => {
        const open = !g.collapsible || searching.value || !hasPrimary || !!showMore[g.key];
        return { ...g, open, visible: open ? g.all : [], hidden: open ? 0 : g.all.length };
    });
};

const matchesRange = (kind, res) => !filters.onlyAvailable || !range.value || statusFor(kind, res) === 'free';

const facultyGroups = computed(() => {
    const q = filters.search.trim().toLowerCase();
    const list = data.faculty.filter((f) => (!q || f.name.toLowerCase().includes(q) || (f.college_name ?? '').toLowerCase().includes(q)) && matchesRange('f', f));
    return makeGroups(
        'f',
        list,
        [
            { key: 'f-college', label: `${props.sectionCollegeName ?? 'Same college'} faculty`, collapsible: false },
            { key: 'f-gened', label: 'General Education faculty', collapsible: false },
            { key: 'f-other', label: 'Other faculty', collapsible: true },
        ],
        facultyGroupKey
    );
});
const roomGroups = computed(() => {
    const q = filters.search.trim().toLowerCase();
    const list = data.rooms.filter(
        (r) =>
            (!q || r.code.toLowerCase().includes(q) || (r.name ?? '').toLowerCase().includes(q) || (r.type ?? '').toLowerCase().includes(q)) &&
            (!filters.building || r.building === filters.building) &&
            (!filters.floor || String(r.floor) === String(filters.floor)) &&
            matchesRange('r', r)
    );
    return makeGroups(
        'r',
        list,
        [
            { key: 'r-main', label: 'Rooms (this college & shared)', collapsible: false },
            { key: 'r-other', label: 'Other college rooms', collapsible: true },
        ],
        roomGroupKey
    );
});
const groups = computed(() => (tab.value === 'rooms' ? roomGroups.value : facultyGroups.value));
const kind = computed(() => (tab.value === 'rooms' ? 'r' : 'f'));
const total = computed(() => groups.value.reduce((n, g) => n + g.all.length, 0));

/* Axis ticks for the availability bar (every 2 hours). */
const ticks = computed(() => {
    const span = windowEnd.value - windowStart.value;
    const out = [];
    for (let m = Math.ceil(windowStart.value / 120) * 120; m < windowEnd.value; m += 120) {
        const left = ((m - windowStart.value) / span) * 100;
        if (left < 94) out.push({ left, label: `${Math.floor(m / 60) % 12 === 0 ? 12 : Math.floor(m / 60) % 12}${m >= 720 ? 'p' : 'a'}` });
    }
    return out;
});

const toggle = (key) => { expanded[key] = !expanded[key]; };
const toggleMore = (key) => { showMore[key] = !showMore[key]; };
const summaryFor = (k, res) => {
    const free = freeRanges(k, res);
    const status = statusFor(k, res);
    const parts = [status ? (status === 'free' ? `Free ${fmtRange(range.value.s, range.value.e)}.` : `Occupied during ${fmtRange(range.value.s, range.value.e)}.`) : ''];
    parts.push(free.length ? `Free on ${filters.day}: ${free.map((r) => fmtRange(r.start, r.end)).join(', ')}.` : `No free time on ${filters.day}.`);
    return parts.filter(Boolean).join(' ');
};
const segTitle = (k, res, i) => {
    const b = stripFor(k, res)[i];
    return `${fmtRange(slots.value[i], slots.value[i] + STEP)} — ${b ? `Occupied: ${b.l}` : 'Available'}`;
};

/* ------------------------------------------------------------------ */
/* Dragging (header is the handle; position is remembered)             */
/* ------------------------------------------------------------------ */

const POS_KEY = 'classly.availabilityPanel.pos';
const panelEl = ref(null);
const pos = reactive({ x: null, y: null });
let drag = null;

const clampPos = () => {
    if (pos.x === null || !panelEl.value) return;
    const w = panelEl.value.offsetWidth;
    pos.x = Math.min(Math.max(pos.x, 80 - w), window.innerWidth - 80);
    pos.y = Math.min(Math.max(pos.y, 0), window.innerHeight - 48);
};
const onDown = (e) => {
    if (e.button !== undefined && e.button !== 0) return;
    if (e.target.closest('button, input, select, .p-select')) return;
    const rect = panelEl.value.getBoundingClientRect();
    drag = { dx: e.clientX - rect.left, dy: e.clientY - rect.top };
    e.currentTarget.setPointerCapture?.(e.pointerId);
};
const onMove = (e) => {
    if (!drag) return;
    pos.x = e.clientX - drag.dx;
    pos.y = e.clientY - drag.dy;
    clampPos();
};
const onUp = () => {
    if (!drag) return;
    drag = null;
    try { localStorage.setItem(POS_KEY, JSON.stringify({ x: pos.x, y: pos.y })); } catch (e) { /* storage unavailable — position just isn't remembered */ }
};
const resetPos = () => {
    pos.x = null;
    pos.y = null;
    try { localStorage.removeItem(POS_KEY); } catch (e) { /* ignore */ }
};
onMounted(() => {
    try {
        const saved = JSON.parse(localStorage.getItem(POS_KEY) ?? 'null');
        if (saved && Number.isFinite(saved.x) && Number.isFinite(saved.y)) { pos.x = saved.x; pos.y = saved.y; }
    } catch (e) { /* ignore */ }
    window.addEventListener('resize', clampPos);
});
onBeforeUnmount(() => window.removeEventListener('resize', clampPos));

const panelStyle = computed(() => (pos.x === null ? {} : { left: `${pos.x}px`, top: `${pos.y}px`, right: 'auto' }));
const close = () => emit('update:visible', false);
const onKey = (e) => { if (e.key === 'Escape') close(); };
</script>

<template>
    <Teleport to="body">
        <div
            v-if="visible"
            ref="panelEl"
            class="ap-root"
            :class="{ 'is-dark': isDark }"
            :style="panelStyle"
            role="dialog"
            aria-modal="false"
            :aria-label="tab === 'rooms' ? 'Room availability' : 'Faculty availability'"
            tabindex="-1"
            @keydown="onKey"
        >
            <!-- Header = drag handle -->
            <header class="ap-header" @pointerdown="onDown" @pointermove="onMove" @pointerup="onUp" @pointercancel="onUp" @dblclick="resetPos" title="Drag to move · double-click to reset position">
                <i class="pi pi-arrows-alt ap-grip" aria-hidden="true"></i>
                <div class="ap-tabs" role="tablist">
                    <button type="button" role="tab" class="ap-tab" :class="{ on: tab === 'faculty' }" :aria-selected="tab === 'faculty'" @click="tab = 'faculty'"><i class="pi pi-users" aria-hidden="true"></i> Faculty</button>
                    <button type="button" role="tab" class="ap-tab" :class="{ on: tab === 'rooms' }" :aria-selected="tab === 'rooms'" @click="tab = 'rooms'"><i class="pi pi-building" aria-hidden="true"></i> Rooms</button>
                </div>
                <button type="button" class="ap-iconbtn ml-auto" aria-label="Refresh" title="Refresh" @click="load"><i class="pi pi-refresh" :class="{ 'pi-spin': loading }"></i></button>
                <button type="button" class="ap-iconbtn" aria-label="Close panel" @click="close"><i class="pi pi-times"></i></button>
            </header>

            <!-- Controls -->
            <div class="ap-controls">
                <div class="ap-days" role="group" aria-label="Day">
                    <button v-for="d in allowedDays" :key="d" type="button" class="ap-chip" :class="{ on: filters.day === d }" :aria-pressed="filters.day === d" @click="filters.day = d">{{ d }}</button>
                </div>
                <div class="ap-row">
                    <Select v-model="filters.start" :options="timeChoices.slice(0, -1)" optionLabel="label" optionValue="value" placeholder="From" showClear size="small" class="ap-sel" aria-label="From time" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    <span class="ap-to">to</span>
                    <Select v-model="filters.end" :options="timeChoices.filter((o) => !filters.start || o.value > filters.start)" optionLabel="label" optionValue="value" placeholder="To" showClear size="small" class="ap-sel" aria-label="To time" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                </div>
                <label class="ap-check" :class="{ dim: !range }" :title="range ? '' : 'Choose a from/to time first'">
                    <input v-model="filters.onlyAvailable" type="checkbox" :disabled="!range" />
                    <span>Only show available for {{ range ? fmtRange(range.s, range.e) : 'the chosen time' }}</span>
                </label>
                <div class="ap-row">
                    <InputText v-model="filters.search" :placeholder="tab === 'rooms' ? 'Search room…' : 'Search faculty…'" size="small" class="ap-grow" :aria-label="tab === 'rooms' ? 'Search rooms' : 'Search faculty'" />
                </div>
                <div v-if="tab === 'rooms' && (buildings.length > 1 || floors.length > 1)" class="ap-row">
                    <Select v-if="buildings.length > 1" v-model="filters.building" :options="buildings" optionLabel="label" optionValue="value" showClear placeholder="All buildings" size="small" class="ap-sel" aria-label="Building" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    <Select v-if="floors.length > 1" v-model="filters.floor" :options="floors" optionLabel="label" optionValue="value" showClear placeholder="All floors" size="small" class="ap-sel" aria-label="Floor" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                </div>
            </div>

            <!-- List -->
            <div class="ap-list" aria-live="polite">
                <div v-if="loading && !total" class="ap-center" role="status"><ProgressSpinner style="width: 32px; height: 32px" strokeWidth="4" /></div>
                <div v-else-if="loadError" class="ap-center ap-bad">
                    <p><i class="pi pi-exclamation-triangle"></i> {{ loadError }}</p>
                    <button type="button" class="ap-more" @click="load">Retry</button>
                </div>
                <p v-else-if="!groups.length" class="ap-center ap-muted">No {{ tab === 'rooms' ? 'rooms' : 'faculty' }} match the filters.</p>

                <template v-for="g in groups" :key="g.key">
                    <div class="ap-group">
                        <span>{{ g.label }} <span class="ap-muted">({{ g.all.length }})</span></span>
                        <button v-if="g.collapsible && !searching" type="button" class="ap-more" :aria-expanded="g.open" @click="toggleMore(g.key)">{{ g.open ? 'Hide' : `Show ${g.hidden}` }}</button>
                    </div>

                    <article v-for="res in g.visible" :key="`${kind}-${res.id}`" class="ap-card">
                        <div class="ap-top">
                            <button type="button" class="ap-name" :aria-expanded="!!expanded[`${kind}-${res.id}`]" @click="toggle(`${kind}-${res.id}`)">
                                <i class="pi" :class="expanded[`${kind}-${res.id}`] ? 'pi-chevron-down' : 'pi-chevron-right'" aria-hidden="true"></i>
                                <span class="truncate">{{ kind === 'f' ? res.name : res.code }}<span v-if="kind === 'r' && res.name && res.name !== res.code" class="ap-muted font-normal"> — {{ res.name }}</span></span>
                            </button>
                            <span v-if="statusFor(kind, res) === 'free'" class="ap-badge ap-ok"><i class="pi pi-check" aria-hidden="true"></i> Available</span>
                            <span v-else-if="statusFor(kind, res) === 'busy'" class="ap-badge ap-no"><i class="pi pi-times" aria-hidden="true"></i> Occupied</span>
                        </div>
                        <p class="ap-meta">
                            <template v-if="kind === 'f'">
                                {{ res.subject_count }} subject{{ res.subject_count === 1 ? '' : 's' }}<template v-if="res.max_units != null"> · {{ res.current_load }}/{{ res.max_units }} units</template>
                            </template>
                            <template v-else>
                                {{ res.class_count }} class{{ res.class_count === 1 ? '' : 'es' }}<template v-if="res.type"> · {{ res.type }}</template><template v-if="res.capacity"> · cap {{ res.capacity }}</template><template v-if="res.building"> · {{ res.building }}<template v-if="res.floor"> F{{ res.floor }}</template></template>
                            </template>
                            <span v-if="workload(kind, res) === 'higher'" class="ap-badge ap-warn"><i class="pi pi-arrow-up" aria-hidden="true"></i> Higher workload</span>
                            <span v-else-if="workload(kind, res) === 'lower'" class="ap-badge ap-info"><i class="pi pi-arrow-down" aria-hidden="true"></i> Lower workload</span>
                            <span v-if="kind === 'f' && atLimit(res)" class="ap-badge ap-no"><i class="pi pi-exclamation-triangle" aria-hidden="true"></i> At load limit</span>
                        </p>

                        <!-- Availability bar: one segment per 30 minutes -->
                        <div class="ap-strip" role="img" :aria-label="`${kind === 'f' ? res.name : res.code} on ${filters.day}. ${summaryFor(kind, res)}`">
                            <span v-for="(b, i) in stripFor(kind, res)" :key="i" class="ap-seg" :class="[b ? 'busy' : 'free', { inr: inRange(i) }]" :title="segTitle(kind, res, i)"></span>
                        </div>
                        <div class="ap-axis" aria-hidden="true"><span v-for="t in ticks" :key="t.left" :style="{ left: `${t.left}%` }">{{ t.label }}</span></div>

                        <div v-if="expanded[`${kind}-${res.id}`]" class="ap-detail">
                            <p><strong>Occupied {{ filters.day }}:</strong>
                                <template v-if="dayBlocks(kind, res).length"><span v-for="(b, n) in dayBlocks(kind, res)" :key="n" class="ap-pill busy">{{ fmtRange(b.s, b.e) }} · {{ b.l }}</span></template>
                                <span v-else class="ap-muted"> nothing</span>
                            </p>
                            <p><strong>Free {{ filters.day }}:</strong>
                                <template v-if="freeRanges(kind, res).length"><span v-for="(r, n) in freeRanges(kind, res)" :key="n" class="ap-pill free">{{ fmtRange(r.start, r.end) }}</span></template>
                                <span v-else class="ap-muted"> no free time</span>
                            </p>
                            <p v-if="weekBlocks(kind, res).some((b) => b.d !== filters.day)"><strong>Other days:</strong>
                                <span v-for="(b, n) in weekBlocks(kind, res).filter((x) => x.d !== filters.day)" :key="n" class="ap-pill">{{ b.d }} {{ fmtRange(b.s, b.e) }}</span>
                            </p>
                        </div>
                    </article>
                </template>
            </div>

            <footer class="ap-foot">
                <span><i class="ap-sw free"></i> Available</span>
                <span><i class="ap-sw busy"></i> Occupied</span>
                <span><i class="ap-sw inr"></i> Time you're checking</span>
                <span class="ap-muted ml-auto">Drag the header to move</span>
            </footer>
        </div>
    </Teleport>
</template>

<style>
/* Unscoped: the panel teleports to <body>. Everything is prefixed `ap-`. */
.ap-root {
    --ap-bg: #ffffff; --ap-soft: #f8fafc; --ap-border: #e2e8f0; --ap-text: #1e293b; --ap-muted: #64748b;
    --ap-free: #d1fae5; --ap-free-fg: #047857; --ap-free-bd: #6ee7b7;
    --ap-busy: #fecdd3; --ap-busy-fg: #be123c; --ap-busy-bd: #fda4af;
    --ap-accent: #4f46e5; --ap-warn: #b45309; --ap-warn-bg: #fef3c7; --ap-info-bg: #dbeafe; --ap-info-fg: #1d4ed8;
    position: fixed; top: 5.5rem; right: 1.5rem; z-index: 950;
    width: min(30rem, calc(100vw - 1rem)); max-height: calc(100vh - 7rem);
    display: flex; flex-direction: column; overflow: hidden;
    background: var(--ap-bg); color: var(--ap-text); border: 1px solid var(--ap-border); border-radius: 1rem;
    box-shadow: 0 18px 50px rgba(15, 23, 42, 0.28); font-size: 0.78rem;
}
.ap-root.is-dark {
    --ap-bg: #1e293b; --ap-soft: #162033; --ap-border: #334155; --ap-text: #e2e8f0; --ap-muted: #94a3b8;
    --ap-free: rgba(16, 185, 129, 0.25); --ap-free-fg: #6ee7b7; --ap-free-bd: rgba(16, 185, 129, 0.5);
    --ap-busy: rgba(244, 63, 94, 0.3); --ap-busy-fg: #fda4af; --ap-busy-bd: rgba(244, 63, 94, 0.55);
    --ap-accent: #818cf8; --ap-warn: #fbbf24; --ap-warn-bg: rgba(245, 158, 11, 0.2); --ap-info-bg: rgba(59, 130, 246, 0.22); --ap-info-fg: #93c5fd;
}
.ap-header { display: flex; align-items: center; gap: 0.5rem; padding: 0.55rem 0.7rem; background: #0f1b4d; color: #fff; cursor: grab; user-select: none; touch-action: none; }
.ap-header:active { cursor: grabbing; }
.ap-grip { opacity: 0.6; font-size: 0.8rem; }
.ap-tabs { display: flex; gap: 0.25rem; background: rgba(255, 255, 255, 0.12); border-radius: 999px; padding: 0.15rem; }
.ap-tab { border: 0; background: transparent; color: #cbd5e1; font-weight: 600; font-size: 0.78rem; padding: 0.25rem 0.7rem; border-radius: 999px; cursor: pointer; display: inline-flex; gap: 0.3rem; align-items: center; }
.ap-tab.on { background: #fff; color: #0f1b4d; }
.ap-iconbtn { border: 0; background: transparent; color: #e2e8f0; width: 1.8rem; height: 1.8rem; border-radius: 999px; cursor: pointer; }
.ap-iconbtn:hover { background: rgba(255, 255, 255, 0.15); }
.ap-tab:focus-visible, .ap-iconbtn:focus-visible, .ap-chip:focus-visible, .ap-name:focus-visible, .ap-more:focus-visible { outline: 2px solid var(--ap-accent); outline-offset: 1px; }

.ap-controls { padding: 0.6rem 0.75rem; display: grid; gap: 0.45rem; background: var(--ap-soft); border-bottom: 1px solid var(--ap-border); }
.ap-days { display: flex; flex-wrap: wrap; gap: 0.3rem; }
.ap-chip { font-size: 0.72rem; font-weight: 600; padding: 0.18rem 0.55rem; border-radius: 999px; border: 1px solid var(--ap-border); background: var(--ap-bg); color: var(--ap-text); cursor: pointer; }
.ap-chip.on { background: var(--ap-accent); border-color: var(--ap-accent); color: #fff; }
.ap-row { display: flex; align-items: center; gap: 0.4rem; }
.ap-sel, .ap-grow { flex: 1 1 0; min-width: 0; }
.ap-to { color: var(--ap-muted); }
.ap-check { display: flex; align-items: center; gap: 0.4rem; font-size: 0.72rem; }
.ap-check.dim { opacity: 0.6; }

.ap-list { overflow-y: auto; padding: 0.4rem 0.6rem 0.6rem; flex: 1 1 auto; min-height: 8rem; }
.ap-center { text-align: center; padding: 1.5rem 0.5rem; }
.ap-muted { color: var(--ap-muted); }
.ap-bad { color: var(--ap-busy-fg); }
.ap-group { position: sticky; top: -0.4rem; z-index: 1; display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin: 0.4rem -0.6rem 0.3rem; padding: 0.3rem 0.75rem; background: var(--ap-soft); border-top: 1px solid var(--ap-border); border-bottom: 1px solid var(--ap-border); font-size: 0.7rem; font-weight: 700; }
.ap-more { font-size: 0.68rem; font-weight: 600; padding: 0.1rem 0.55rem; border-radius: 999px; border: 1px solid var(--ap-accent); color: var(--ap-accent); background: transparent; cursor: pointer; }
.ap-more:hover { background: var(--ap-accent); color: #fff; }

.ap-card { padding: 0.45rem 0.1rem 0.5rem; border-bottom: 1px solid var(--ap-border); }
.ap-top { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.ap-name { display: flex; align-items: center; gap: 0.35rem; min-width: 0; background: none; border: 0; padding: 0; font-weight: 700; font-size: 0.8rem; color: var(--ap-text); cursor: pointer; text-align: left; }
.ap-name .pi { font-size: 0.6rem; }
.ap-meta { margin: 0.1rem 0 0.35rem 1rem; color: var(--ap-muted); font-size: 0.7rem; display: flex; flex-wrap: wrap; align-items: center; gap: 0.25rem 0.4rem; }
.ap-badge { display: inline-flex; align-items: center; gap: 0.25rem; font-size: 0.64rem; font-weight: 700; padding: 0.05rem 0.45rem; border-radius: 999px; white-space: nowrap; }
.ap-badge .pi { font-size: 0.55rem; }
.ap-ok { background: var(--ap-free); color: var(--ap-free-fg); }
.ap-no { background: var(--ap-busy); color: var(--ap-busy-fg); }
.ap-warn { background: var(--ap-warn-bg); color: var(--ap-warn); }
.ap-info { background: var(--ap-info-bg); color: var(--ap-info-fg); }

/* Availability bar. Busy segments are hatched so state isn't colour-only. */
.ap-strip { display: flex; gap: 1px; height: 1.15rem; margin-left: 1rem; }
.ap-seg { flex: 1 1 0; border-radius: 2px; border: 1px solid transparent; }
.ap-seg.free, .ap-sw.free { background: var(--ap-free); border-color: var(--ap-free-bd); }
.ap-seg.busy, .ap-sw.busy { background: repeating-linear-gradient(135deg, var(--ap-busy), var(--ap-busy) 3px, transparent 3px, transparent 5px), var(--ap-busy); border-color: var(--ap-busy-bd); }
.ap-seg.inr, .ap-sw.inr { box-shadow: 0 -2px 0 0 var(--ap-accent), 0 2px 0 0 var(--ap-accent); }
.ap-axis { position: relative; height: 0.9rem; margin: 0.1rem 0 0 1rem; font-size: 0.58rem; color: var(--ap-muted); }
.ap-axis span { position: absolute; transform: translateX(-50%); }

.ap-detail { margin: 0.3rem 0 0 1rem; padding: 0.4rem 0.5rem; background: var(--ap-soft); border-radius: 0.5rem; line-height: 1.55rem; font-size: 0.72rem; }
.ap-pill { display: inline-block; margin: 0 0.25rem 0.1rem 0; padding: 0 0.45rem; border-radius: 999px; font-size: 0.68rem; border: 1px solid var(--ap-border); }
.ap-pill.busy { background: var(--ap-busy); color: var(--ap-busy-fg); border-color: var(--ap-busy-bd); }
.ap-pill.free { background: var(--ap-free); color: var(--ap-free-fg); border-color: var(--ap-free-bd); }

.ap-foot { display: flex; flex-wrap: wrap; align-items: center; gap: 0.3rem 0.9rem; padding: 0.45rem 0.75rem; border-top: 1px solid var(--ap-border); background: var(--ap-soft); font-size: 0.66rem; color: var(--ap-muted); }
.ap-foot span { display: inline-flex; align-items: center; gap: 0.3rem; }
.ap-sw { display: inline-block; width: 0.9rem; height: 0.7rem; border-radius: 2px; border: 1px solid transparent; }

@media (max-width: 640px) {
    .ap-root { left: 0.5rem !important; right: 0.5rem !important; width: auto; top: 4.5rem; }
}
</style>