<script setup>
/**
 * AVAILABILITY CHECK MODAL — Section Subjects page.
 *
 * Panelist requirement: a scheduler assigning a Faculty / Room / Day /
 * Time to a Section Subject must be able to see every Faculty member's
 * and every Room's availability (and workload) WITHOUT leaving this
 * page for the Faculty or Room pages and coming back.
 *
 * Opened from a row's "Availability" button. It is a pure picker:
 *  - reads ONE endpoint (scheduling.section-subjects.availability) for
 *    every Faculty/Room timetable of this Section's term,
 *  - overlays the page's own UNSAVED rows on top (so a booking the
 *    scheduler just made on another row of this Section but hasn't
 *    saved yet still blocks the slot here),
 *  - never writes anything and never auto-assigns. "Use Selection"
 *    emits the chosen values and the page applies them through its
 *    normal onFacultyChange/onRoomChange/onDaysChange/... handlers, so
 *    every existing validation (conflict check, load limit, room
 *    type/college warnings, hours mismatch) still runs on Save exactly
 *    as if the values had been picked from the row's own dropdowns.
 *
 * Availability rule == ScheduleConflictService::overlaps()
 * (existing.start < new.end AND existing.end > new.start), evaluated
 * per 30-minute slot, so a slot shown green here can never be one
 * Save would reject for Faculty/Room double-booking.
 */
import { ref, reactive, computed, watch } from 'vue';
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';
import Select from 'primevue/select';
import InputText from 'primevue/inputtext';
import Tag from 'primevue/tag';
import ProgressSpinner from 'primevue/progressspinner';

const props = defineProps({
    visible: { type: Boolean, default: false },
    sectionId: { type: Number, required: true },
    sectionCode: { type: String, default: '' },
    // 'both' | 'faculty' | 'rooms' — which list the popup opens on.
    initialView: { type: String, default: 'both' },
    // Scope inputs — same ones Show.vue's facultyGroupsFor()/roomGroupsFor()
    // use, so this modal orders Faculty/Rooms exactly like the row dropdowns.
    subjectCategory: { type: String, default: null }, // Major | Minor | General Education
    wantsLaboratory: { type: Boolean, default: false },
    sectionCollegeId: { type: Number, default: null },
    sectionCollegeName: { type: String, default: null },
    sectionDepartmentId: { type: Number, default: null },
    // The Section Subject row being edited (live, reactive page row).
    row: { type: Object, default: null },
    // Every row of the CURRENT section (live values, incl. unsaved).
    sectionRows: { type: Array, default: () => [] },
    schedulingWindow: { type: Object, required: true },
    // Weekly contact hours to split across the chosen days (same
    // number the page's Auto End Time uses). Null when unknown.
    weeklyHours: { type: Number, default: null },
    isDark: { type: Boolean, default: false },
});

const emit = defineEmits(['update:visible', 'apply']);

/* ------------------------------------------------------------------ */
/* Constants / helpers                                                 */
/* ------------------------------------------------------------------ */

const STEP = 30; // minutes per grid cell — same step the page's time dropdowns use
const DAY_LABELS = { Mon: 'Mon', Tue: 'Tue', Wed: 'Wed', Thu: 'Thu', Fri: 'Fri', Sat: 'Sat', Sun: 'Sun' };
const ALL_DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const DAY_PRESETS = [
    { label: 'MW', value: ['Mon', 'Wed'] },
    { label: 'TTH', value: ['Tue', 'Thu'] },
    { label: 'WF', value: ['Wed', 'Fri'] },
    { label: 'MWF', value: ['Mon', 'Wed', 'Fri'] },
    { label: 'SAT', value: ['Sat'] },
];

const toMin = (hhmm) => {
    if (!hhmm) return null;
    const [h, m] = String(hhmm).slice(0, 5).split(':').map(Number);
    return h * 60 + m;
};
const fromMin = (min) => `${String(Math.floor(min / 60)).padStart(2, '0')}:${String(min % 60).padStart(2, '0')}`;
const fmt = (hhmm) => {
    const min = typeof hhmm === 'number' ? hhmm : toMin(hhmm);
    if (min == null) return '—';
    const h = Math.floor(min / 60);
    const m = min % 60;
    return `${h % 12 === 0 ? 12 : h % 12}:${String(m).padStart(2, '0')} ${h >= 12 ? 'PM' : 'AM'}`;
};
const fmtRange = (s, e) => `${fmt(s)}–${fmt(e)}`;
const sortDays = (days) => ALL_DAYS.filter((d) => days.includes(d));
const sameList = (a, b) => a.length === b.length && a.every((v, i) => v === b[i]);

const windowStart = computed(() => toMin(props.schedulingWindow.start_time));
const windowEnd = computed(() => toMin(props.schedulingWindow.end_time));
const allowedDays = computed(() => {
    const allowed = props.schedulingWindow?.available_days;
    return Array.isArray(allowed) && allowed.length ? ALL_DAYS.filter((d) => allowed.includes(d)) : ALL_DAYS.slice(0, 6);
});
const presets = computed(() => DAY_PRESETS.filter((p) => p.value.every((d) => allowedDays.value.includes(d))));

// Grid slots: every STEP minutes from window start up to (not incl.) window end.
const allSlots = computed(() => {
    const slots = [];
    for (let m = windowStart.value; m < windowEnd.value; m += STEP) slots.push(m);
    return slots;
});

const timeChoices = computed(() => {
    const out = [];
    for (let m = windowStart.value; m <= windowEnd.value; m += STEP) out.push({ label: fmt(m), value: fromMin(m) });
    return out;
});

/* ------------------------------------------------------------------ */
/* Data load                                                           */
/* ------------------------------------------------------------------ */

const loading = ref(false);
const loadError = ref('');
const data = reactive({ faculty: [], rooms: [] });

const load = async () => {
    loading.value = true;
    loadError.value = '';
    try {
        const url = new URL(route('scheduling.section-subjects.availability', props.sectionId), window.location.origin);
        if (props.row?.id) url.searchParams.set('section_subject_id', props.row.id);
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

/* ------------------------------------------------------------------ */
/* Draft selection (local until "Use Selection")                       */
/* ------------------------------------------------------------------ */

const draft = reactive({ faculty_id: null, room_id: null, days: [], start_time: null, end_time: null });
const filters = reactive({
    viewDay: 'ALL',
    from: null,
    to: null,
    facultySearch: '',
    roomSearch: '',
    building: null,
    floor: null,
    onlyAvailable: false,
    sort: 'best',
    view: 'both', // 'both' | 'faculty' | 'rooms'
    anchor: 'start', // what a cell click sets: 'start' | 'end'
});
const expanded = reactive({});

const isOnline = computed(() => props.row?.delivery_mode === 'online');
// Browse mode: opened from the page header with no subject row — a read-only
// look-up (who/what is free when). No picking, no Use Selection.
const browse = computed(() => !props.row);

const resetFromRow = () => {
    const r = props.row;
    draft.faculty_id = r?.faculty_id ?? null;
    draft.room_id = isOnline.value ? null : (r?.room_id ?? null);
    draft.days = sortDays(Array.isArray(r?.days) ? r.days : []);
    draft.start_time = r?.start_time ? String(r.start_time).slice(0, 5) : null;
    draft.end_time = r?.end_time ? String(r.end_time).slice(0, 5) : null;

    filters.viewDay = draft.days.length > 1 ? 'ALL' : (draft.days[0] ?? allowedDays.value[0]);
    filters.from = fromMin(windowStart.value);
    filters.to = fromMin(windowEnd.value);
    filters.facultySearch = '';
    filters.roomSearch = '';
    filters.building = null;
    filters.floor = null;
    filters.onlyAvailable = false;
    filters.sort = 'best';
    filters.view = isOnline.value ? 'faculty' : props.initialView;
    filters.anchor = 'start';
    Object.keys(expanded).forEach((k) => delete expanded[k]);
};

watch(
    () => props.visible,
    (open) => {
        if (!open) return;
        resetFromRow();
        load();
    }
);

/* ------------------------------------------------------------------ */
/* Timetable / occupancy                                               */
/* ------------------------------------------------------------------ */

const subjectCode = (r) => r?.subject?.subject_code ?? 'Class';

// Server blocks for a resource, with this section's own rows swapped
// for their LIVE (possibly unsaved) values. The edited row itself is
// never a booking — it's the draft.
const blocksFor = (kind, res) => {
    const liveIds = new Set(props.sectionRows.map((r) => r.id));
    const kept = res.blocks.filter((b) => !liveIds.has(b.id));
    const live = [];
    for (const r of props.sectionRows) {
        if (r.id === props.row?.id || r.is_merged) continue;
        const matches = kind === 'f' ? r.faculty_id === res.id : r.room_id === res.id;
        if (!matches || !r.days?.length || !r.start_time || !r.end_time) continue;
        for (const d of r.days) {
            live.push({ id: r.id, d, s: String(r.start_time).slice(0, 5), e: String(r.end_time).slice(0, 5), l: `${subjectCode(r)} · ${props.sectionCode}` });
        }
    }
    return [...kept, ...live];
};

// key -> { Mon: [block|null per slot], ... }
const occupancy = computed(() => {
    const map = new Map();
    const slots = allSlots.value;
    const build = (kind, res) => {
        const byDay = {};
        for (const b of blocksFor(kind, res)) {
            const bs = toMin(b.s);
            const be = toMin(b.e);
            const arr = (byDay[b.d] ??= new Array(slots.length).fill(null));
            slots.forEach((t, i) => {
                if (bs < t + STEP && be > t && !arr[i]) arr[i] = b;
            });
        }
        map.set(`${kind}-${res.id}`, byDay);
    };
    data.faculty.forEach((f) => build('f', f));
    data.rooms.forEach((r) => build('r', r));
    return map;
});

const consideredDays = computed(() => {
    if (filters.viewDay === 'ALL') return draft.days.length ? draft.days : allowedDays.value.slice(0, 1);
    return [filters.viewDay];
});

// What occupies slot i for this resource across the considered days.
const slotBlocks = (kind, res, i) => {
    const byDay = occupancy.value.get(`${kind}-${res.id}`) ?? {};
    const hits = [];
    for (const d of consideredDays.value) {
        const b = byDay[d]?.[i];
        if (b) hits.push({ ...b, d });
    }
    return hits;
};

// Conflicts of a resource against an explicit (days, start, end).
const conflictsFor = (kind, res, days, start, end) => {
    const s = toMin(start);
    const e = toMin(end);
    if (s == null || e == null || e <= s || !days.length) return [];
    return blocksFor(kind, res)
        .filter((b) => days.includes(b.d) && toMin(b.s) < e && toMin(b.e) > s)
        .sort((a, b) => ALL_DAYS.indexOf(a.d) - ALL_DAYS.indexOf(b.d) || toMin(a.s) - toMin(b.s));
};

const timeIsComplete = computed(() => draft.days.length > 0 && !!draft.start_time && !!draft.end_time && toMin(draft.end_time) > toMin(draft.start_time));

/* ------------------------------------------------------------------ */
/* Workload + recommendations                                          */
/* ------------------------------------------------------------------ */

/* --- Scope (mirrors Show.vue isQualifiedFor()/roomGroupsFor()) --- */

const isGenEd = computed(() => props.subjectCategory === 'General Education');
const typeMatch = computed(() => (props.wantsLaboratory ? 'Laboratory' : 'Lecture'));

// Faculty tiers: 0 = explicit Teaching Qualification for THIS subject,
// 1 = in scope (GenEd pool for GenEd subjects, else the Section's own
// College), 2 = everyone else (manual override).
const facultyInScope = (f) => {
    if (isGenEd.value) return f.faculty_category === 'General Education Faculty' || f.college_id === null || !!f.college_counts_as_gened;
    return props.sectionCollegeId !== null && f.college_id === props.sectionCollegeId;
};
const facultyTier = (f) => (f.qualified ? 0 : facultyInScope(f) ? 1 : 2);

// Rooms: another College's room is a hard block for Major subjects and a
// confirm-on-save warning for Minor; GenEd subjects have no owning College.
const roomOtherCollege = (r) => !isGenEd.value && !!r.college_id && props.sectionCollegeId !== null && r.college_id !== props.sectionCollegeId;
const roomBlocked = (r) => roomOtherCollege(r) && props.subjectCategory === 'Major';
const roomGroupKey = (r) => (roomOtherCollege(r) ? 'r-other' : browse.value || r.type === typeMatch.value ? 'r-type' : 'r-scope');
const roomScopeRank = (r) => (props.sectionDepartmentId && r.department_id === props.sectionDepartmentId ? 0 : r.college_id ? 1 : 2);

/* --- Workload: compared only WITHIN a scope group, and only when the --- */
/* group's numbers actually differ (all-zero => no badge at all).        */

const buildStats = (list, keyFn, countFn) => {
    const groups = {};
    list.forEach((x) => (groups[keyFn(x)] ??= []).push(countFn(x)));
    return Object.fromEntries(
        Object.entries(groups).map(([k, v]) => [k, { avg: v.reduce((a, b) => a + b, 0) / v.length, spread: Math.max(...v) - Math.min(...v) }])
    );
};
const facultyStats = computed(() => buildStats(data.faculty, (f) => facultyTier(f), (f) => f.subject_count));
const roomStats = computed(() => buildStats(data.rooms, (r) => `${roomGroupKey(r)}|${r.type ?? ''}`, (r) => r.class_count));

const workloadLevel = (count, st) => {
    if (!st || st.spread === 0) return 'none';
    if (count > st.avg * 1.25 && count - st.avg >= 1) return 'higher';
    if (count < st.avg * 0.75) return 'lower';
    return 'balanced';
};
const facultyAtLimit = (f) => f.max_units != null && Number(f.current_load) >= Number(f.max_units);

const sessionMinutes = computed(() => {
    if (props.weeklyHours && draft.days.length) return Math.round((props.weeklyHours / draft.days.length) * 60);
    if (draft.start_time && draft.end_time && toMin(draft.end_time) > toMin(draft.start_time)) return toMin(draft.end_time) - toMin(draft.start_time);
    return null;
});

// Is the resource free for the whole draft time on every chosen day?
const freeForDraft = (kind, res) => timeIsComplete.value && conflictsFor(kind, res, draft.days, draft.start_time, draft.end_time).length === 0;

const needSeats = computed(() => Number(props.row?.capacity ?? 0));

// How well a room's size fits the section. tier 0 = snug (<=50% spare),
// 1 = comfortable / unknown, 2 = oversized, 3 = too small.
const roomFit = (r) => {
    const need = needSeats.value;
    if (!need || !r.capacity) return { tier: 1, tooSmall: false, label: r.capacity ? `Seats ${r.capacity}` : null };
    if (r.capacity < need) return { tier: 3, tooSmall: true, label: `Seats ${r.capacity} — short by ${need - r.capacity}` };
    const ratio = (r.capacity - need) / need;
    return { tier: ratio <= 0.5 ? 0 : ratio <= 1.5 ? 1 : 2, tooSmall: false, label: `Seats ${r.capacity} for ${need} students` };
};

// Buildings where this Section already has a class on the same day(s) and,
// once a time is chosen, within an hour of it — a room there saves the
// class a walk across campus. building -> subject code of that class.
const neighbourBuildings = computed(() => {
    const map = new Map();
    const s0 = toMin(draft.start_time);
    const e0 = toMin(draft.end_time);
    for (const row of props.sectionRows) {
        if (row.id === props.row?.id || row.is_merged || !row.room_id || !row.days?.length || !row.start_time || !row.end_time) continue;
        if (draft.days.length && !row.days.some((d) => draft.days.includes(d))) continue;
        if (timeIsComplete.value) {
            const rs = toMin(row.start_time);
            const re = toMin(row.end_time);
            const gap = rs >= e0 ? rs - e0 : s0 >= re ? s0 - re : 0;
            if (gap > 60) continue;
        }
        const room = data.rooms.find((r) => r.id === row.room_id);
        if (room?.building && !map.has(room.building)) map.set(room.building, subjectCode(row));
    }
    return map;
});
const roomBuildingMiss = (r) => (neighbourBuildings.value.size && !neighbourBuildings.value.has(r.building) ? 1 : 0);

// Best-first, in-scope, free candidates. Faculty: qualified > same
// college/GenEd, then fewest subjects. Rooms: right type, then snug fit,
// then same building as the Section's neighbouring class, then own
// department, then fewest scheduled classes. Nothing is auto-assigned.
const recommendation = computed(() => {
    const out = { faculty: [], rooms: [] };
    if (!timeIsComplete.value || browse.value) return out;

    const freeFaculty = data.faculty.filter((f) => facultyTier(f) <= 1 && freeForDraft('f', f) && !facultyAtLimit(f));
    const fpool = freeFaculty.some((f) => facultyTier(f) === 0) ? freeFaculty.filter((f) => facultyTier(f) === 0) : freeFaculty;
    out.faculty = [...fpool].sort((x, y) => x.subject_count - y.subject_count || x.current_load - y.current_load || x.name.localeCompare(y.name)).slice(0, 3);

    if (!isOnline.value) {
        const free = data.rooms.filter((r) => !roomOtherCollege(r) && freeForDraft('r', r) && !roomFit(r).tooSmall);
        const rpool = free.some((r) => r.type === typeMatch.value) ? free.filter((r) => r.type === typeMatch.value) : free;
        out.rooms = [...rpool]
            .sort((x, y) => roomFit(x).tier - roomFit(y).tier || roomBuildingMiss(x) - roomBuildingMiss(y) || roomScopeRank(x) - roomScopeRank(y) || x.class_count - y.class_count || x.code.localeCompare(y.code))
            .slice(0, 3);
    }
    return out;
});
const recommendedOrder = computed(() => {
    const map = new Map();
    recommendation.value.faculty.forEach((f, i) => map.set(`f-${f.id}`, i));
    recommendation.value.rooms.forEach((r, i) => map.set(`r-${r.id}`, i));
    return map;
});
const recommendedIds = computed(() => new Set(recommendedOrder.value.keys()));
const bestFaculty = computed(() => recommendation.value.faculty[0] ?? null);
const bestRoom = computed(() => recommendation.value.rooms[0] ?? null);

const facultyReasons = (f) => {
    const out = [];
    if (timeIsComplete.value) out.push(`free ${draft.days.join('/')} ${fmtRange(draft.start_time, draft.end_time)}`);
    out.push(f.qualified ? 'qualified for this subject' : facultyInScope(f) ? (isGenEd.value ? 'GenEd faculty' : 'same college') : 'outside scope');
    out.push(`${f.subject_count} subject${f.subject_count === 1 ? '' : 's'} assigned`);
    if (f.max_units != null) out.push(`${f.current_load}/${f.max_units} units`);
    return out;
};
const roomReasons = (r) => {
    const out = [];
    if (timeIsComplete.value) out.push(`free ${draft.days.join('/')} ${fmtRange(draft.start_time, draft.end_time)}`);
    const fit = roomFit(r);
    if (fit.label) out.push(fit.label);
    const near = neighbourBuildings.value.get(r.building);
    if (near) out.push(`same building as ${near}`);
    if (r.type) out.push(`${String(r.type).toLowerCase()} room`);
    out.push(`${r.class_count} class${r.class_count === 1 ? '' : 'es'} scheduled`);
    return out;
};

const pickBest = (kind, res) => { if (kind === 'f') draft.faculty_id = res.id; else draft.room_id = res.id; };

const badgesFor = (kind, res) => {
    const badges = [];
    if (recommendedIds.value.has(`${kind}-${res.id}`)) badges.push({ key: 'rec', label: 'Recommended', severity: 'success', icon: 'pi pi-star-fill' });

    if (timeIsComplete.value && !(kind === 'r' && roomBlocked(res))) {
        badges.push(
            freeForDraft(kind, res)
                ? { key: 'avail', label: 'Available', severity: 'success', icon: 'pi pi-check' }
                : { key: 'occ', label: 'Currently occupied', severity: 'danger', icon: 'pi pi-times' }
        );
    }

    const level = kind === 'f'
        ? workloadLevel(res.subject_count, facultyStats.value[facultyTier(res)])
        : workloadLevel(res.class_count, roomStats.value[`${roomGroupKey(res)}|${res.type ?? ''}`]);
    if (level === 'higher') badges.push({ key: 'hi', label: 'Higher workload', severity: 'warn', icon: 'pi pi-arrow-up' });
    if (level === 'lower') badges.push({ key: 'lo', label: 'Lower workload', severity: 'info', icon: 'pi pi-arrow-down' });
    if (kind === 'f' && facultyAtLimit(res)) badges.push({ key: 'cap', label: 'At load limit', severity: 'danger', icon: 'pi pi-exclamation-triangle' });
    if (kind === 'r' && roomBlocked(res)) badges.push({ key: 'blk', label: 'Other college — not allowed for Major', severity: 'danger', icon: 'pi pi-ban' });
    else if (kind === 'r' && !browse.value && roomOtherCollege(res)) badges.push({ key: 'oc', label: 'Other college — confirm on save', severity: 'warn', icon: 'pi pi-exclamation-triangle' });
    if (kind === 'r' && !roomBlocked(res) && !browse.value) {
        const fit = roomFit(res);
        if (fit.tooSmall) badges.push({ key: 'small', label: 'Too small', severity: 'danger', icon: 'pi pi-exclamation-circle' });
        else if (fit.tier === 0 && needSeats.value) badges.push({ key: 'fit', label: 'Good fit', severity: 'success', icon: 'pi pi-check' });
        else if (fit.tier === 2) badges.push({ key: 'big', label: 'Oversized', severity: 'secondary', icon: 'pi pi-expand' });
        if (neighbourBuildings.value.has(res.building)) badges.push({ key: 'near', label: 'Same building', severity: 'info', icon: 'pi pi-map-marker' });
    }
    return badges;
};

const recommendationReason = (kind, res) => (kind === 'f' ? facultyReasons(res) : roomReasons(res)).join(' · ');

/* ------------------------------------------------------------------ */
/* Filtering + sorting                                                 */
/* ------------------------------------------------------------------ */

const buildings = computed(() => [...new Set(data.rooms.map((r) => r.building).filter(Boolean))].sort().map((b) => ({ label: b, value: b })));
const floors = computed(() => {
    const pool = filters.building ? data.rooms.filter((r) => r.building === filters.building) : data.rooms;
    return [...new Set(pool.map((r) => r.floor).filter((f) => f != null && f !== ''))].sort().map((f) => ({ label: `Floor ${f}`, value: f }));
});
watch(() => filters.building, () => { filters.floor = null; });

// The columns (slots) shown, per the time-range filter.
const visibleSlotIdx = computed(() => {
    const from = toMin(filters.from) ?? windowStart.value;
    const to = toMin(filters.to) ?? windowEnd.value;
    return allSlots.value.map((t, i) => i).filter((i) => allSlots.value[i] >= from && allSlots.value[i] < to);
});
// One header cell per 30-minute column, labeled by its START time
// (8 AM, 8:30, 9 AM, 9:30 ...) so a half-hour start/end such as
// 8:00–9:30 can be read straight off the grid.
const hourHeaders = computed(() =>
    visibleSlotIdx.value.map((i) => {
        const t = allSlots.value[i];
        const hour = Math.floor(t / 60);
        const h12 = hour % 12 === 0 ? 12 : hour % 12;
        const half = t % 60 !== 0;
        return { hour: t, span: 1, half, label: half ? `${h12}:${String(t % 60).padStart(2, '0')}` : `${h12} ${hour >= 12 ? 'PM' : 'AM'}` };
    })
);

const rank = (kind, res) => {
    const free = timeIsComplete.value ? (freeForDraft(kind, res) ? 0 : 1) : 0;
    const rec = recommendedOrder.value.get(`${kind}-${res.id}`) ?? 99;
    const load = kind === 'f' ? res.subject_count : res.class_count;
    const scope = kind === 'r' ? roomScopeRank(res) : 0;
    const fit = kind === 'r' ? roomFit(res).tier : 0;
    const near = kind === 'r' ? roomBuildingMiss(res) : 0;
    return { free, rec, load, scope, fit, near };
};
const labelOf = (kind, x) => (kind === 'f' ? x.name : x.code);
const sorter = (kind) => (a, b) => {
    if (filters.sort === 'name') return labelOf(kind, a).localeCompare(labelOf(kind, b));
    const ra = rank(kind, a);
    const rb = rank(kind, b);
    if (filters.sort === 'workload') return ra.load - rb.load || labelOf(kind, a).localeCompare(labelOf(kind, b));
    return ra.free - rb.free || ra.rec - rb.rec || ra.fit - rb.fit || ra.near - rb.near || ra.scope - rb.scope || ra.load - rb.load || labelOf(kind, a).localeCompare(labelOf(kind, b));
};

const matchingFaculty = computed(() => {
    const q = filters.facultySearch.trim().toLowerCase();
    return data.faculty
        .filter((f) => !q || f.name.toLowerCase().includes(q) || (f.college_name ?? '').toLowerCase().includes(q))
        .filter((f) => !filters.onlyAvailable || !timeIsComplete.value || freeForDraft('f', f) || f.id === draft.faculty_id);
});
const matchingRooms = computed(() => {
    const q = filters.roomSearch.trim().toLowerCase();
    return data.rooms
        .filter((r) => !q || r.code.toLowerCase().includes(q) || (r.name ?? '').toLowerCase().includes(q) || (r.type ?? '').toLowerCase().includes(q))
        .filter((r) => !filters.building || r.building === filters.building)
        .filter((r) => !filters.floor || String(r.floor) === String(filters.floor))
        .filter((r) => !filters.onlyAvailable || !timeIsComplete.value || freeForDraft('r', r) || r.id === draft.room_id);
});

// Collapsible groups (out-of-scope) stay closed until the scheduler asks,
// unless they are searching, or there is nothing in scope to show at all.
const showMore = reactive({});
const toggleMore = (key) => { showMore[key] = !showMore[key]; };

const buildGroups = (kind, items, descs, groupOf, searching, selectedId) => {
    const built = descs.map((d) => ({ ...d, all: items.filter((x) => groupOf(x) === d.key).sort(sorter(kind)) })).filter((g) => g.all.length);
    const hasPrimary = built.some((g) => !g.collapsible);
    return built.map((g) => {
        const open = !g.collapsible || searching || !hasPrimary || !!showMore[g.key];
        const visible = open ? g.all : g.all.filter((x) => x.id === selectedId);
        return { ...g, open, visible, hidden: g.all.length - visible.length };
    });
};

const facultyGroups = computed(() =>
    buildGroups(
        'f',
        matchingFaculty.value,
        [
            { key: 'ft-0', label: 'Qualified for this subject', collapsible: false },
            { key: 'ft-1', label: isGenEd.value ? 'General Education faculty' : `${props.sectionCollegeName ?? 'Same college'} faculty`, collapsible: false },
            { key: 'ft-2', label: 'Other faculty (manual override)', collapsible: true },
        ],
        (f) => `ft-${facultyTier(f)}`,
        filters.facultySearch.trim() !== '',
        draft.faculty_id
    )
);
const roomGroups = computed(() =>
    buildGroups(
        'r',
        matchingRooms.value,
        [
            { key: 'r-type', label: browse.value ? 'Rooms (this college & shared)' : props.wantsLaboratory ? 'Laboratory rooms' : 'Lecture rooms', collapsible: false },
            { key: 'r-scope', label: 'Other room types in scope', collapsible: true },
            { key: 'r-other', label: browse.value ? 'Other college rooms' : props.subjectCategory === 'Major' ? 'Other college rooms (not allowed for Major subjects)' : 'Other college rooms (override)', collapsible: true },
        ],
        roomGroupKey,
        filters.roomSearch.trim() !== '' || !!filters.building || !!filters.floor,
        draft.room_id
    )
);
const facultyShown = computed(() => facultyGroups.value.reduce((n, g) => n + g.visible.length, 0));
const facultyMatching = computed(() => facultyGroups.value.reduce((n, g) => n + g.all.length, 0));
const roomsShown = computed(() => roomGroups.value.reduce((n, g) => n + g.visible.length, 0));
const roomsMatching = computed(() => roomGroups.value.reduce((n, g) => n + g.all.length, 0));

/* ------------------------------------------------------------------ */
/* Cell state + interaction                                            */
/* ------------------------------------------------------------------ */

const inDraftRange = (i) => {
    if (!draft.start_time || !draft.end_time) return false;
    const t = allSlots.value[i];
    return t >= toMin(draft.start_time) && t < toMin(draft.end_time);
};
const isSelectedRes = (kind, res) => (kind === 'f' ? draft.faculty_id === res.id : draft.room_id === res.id);

const cellInfo = (kind, res, i) => {
    const blocks = slotBlocks(kind, res, i);
    const occupied = blocks.length > 0;
    const selectedCell = isSelectedRes(kind, res) && inDraftRange(i);
    const t = fmt(allSlots.value[i]);
    const dayLabel = filters.viewDay === 'ALL' ? (consideredDays.value.length > 1 ? 'selected days' : consideredDays.value[0]) : filters.viewDay;
    const who = blocks.map((b) => `${filters.viewDay === 'ALL' && consideredDays.value.length > 1 ? `${b.d} ` : ''}${b.l}`).join('; ');
    let state = 'free';
    let text = `Available at ${t}`;
    if (occupied) {
        state = selectedCell ? 'conflict' : 'busy';
        text = `Occupied at ${t} — ${who}`;
    } else if (selectedCell) {
        state = 'selected';
        text = `Selected at ${t}`;
    }
    const name = kind === 'f' ? res.name : `${res.code}`;
    const blocked = kind === 'r' && roomBlocked(res);
    return { state, text: blocked ? `${text} — other college's room, not allowed for Major subjects` : text, aria: `${name}, ${dayLabel}, ${text}`, occupied, locked: occupied || blocked };
};

// Put a resource + a [start, end) range into the draft. Days default to
// the day being viewed when none are chosen yet.
const applyRange = (kind, res, startMin, endMin) => {
    if (!browse.value) {
        if (kind === 'f') draft.faculty_id = res.id;
        else draft.room_id = res.id;
    }
    if (!draft.days.length && filters.viewDay !== 'ALL') draft.days = [filters.viewDay];
    draft.start_time = fromMin(startMin);
    draft.end_time = fromMin(endMin);
};

// Length of one meeting: weekly hours split across the chosen days (so a
// 3-hour subject is 3 h on one day, 1.5 h on MW), else the current
// start–end, else 90 minutes.
const meetingLength = () => sessionMinutes.value ?? 90;

const onCell = (kind, res, i) => {
    if (cellInfo(kind, res, i).locked) return;
    if (!draft.days.length && filters.viewDay !== 'ALL') draft.days = [filters.viewDay];
    const t = allSlots.value[i];
    const len = meetingLength();
    if (filters.anchor === 'end') {
        // "End here": the clicked slot is the LAST slot of the class.
        const end = t + STEP;
        applyRange(kind, res, Math.max(windowStart.value, end - len), end);
    } else {
        applyRange(kind, res, t, Math.min(t + len, windowEnd.value));
    }
};

// The extra right-hand column: the boundary after the last visible slot
// (e.g. 7:30 PM). Clicking it ends the class there and counts back.
const endBoundary = computed(() => (visibleSlotIdx.value.length ? allSlots.value[visibleSlotIdx.value[visibleSlotIdx.value.length - 1]] + STEP : windowEnd.value));
const endCapInfo = (kind, res) => {
    const end = endBoundary.value;
    const start = Math.max(windowStart.value, end - meetingLength());
    const days = draft.days.length ? draft.days : consideredDays.value;
    const blocked = kind === 'r' && roomBlocked(res);
    const conflicts = blocked ? [] : conflictsFor(kind, res, days, fromMin(start), fromMin(end));
    const ok = !blocked && conflicts.length === 0;
    return {
        ok,
        start,
        end,
        text: ok
            ? `End at ${fmt(end)} — class runs ${fmtRange(start, end)}`
            : blocked
              ? `Not allowed for Major subjects`
              : `Can't end at ${fmt(end)} — ${describeConflicts(conflicts)}`,
    };
};
const onEndCap = (kind, res) => {
    const info = endCapInfo(kind, res);
    if (!info.ok) return;
    if (!draft.days.length && filters.viewDay !== 'ALL') draft.days = [filters.viewDay];
    applyRange(kind, res, info.start, info.end);
};

const selectResource = (kind, res) => {
    if (browse.value) return;
    if (kind === 'r' && roomBlocked(res)) return;
    if (kind === 'f') draft.faculty_id = draft.faculty_id === res.id ? null : res.id;
    else draft.room_id = draft.room_id === res.id ? null : res.id;
};

/* Schedule pickers */

const toggleDay = (day) => {
    const next = draft.days.includes(day) ? draft.days.filter((d) => d !== day) : sortDays([...draft.days, day]);
    setDays(next);
};
const setDays = (next) => {
    draft.days = sortDays(next);
    // Same rule as the page's Auto End Time: weekly hours split across the days.
    if (draft.start_time && props.weeklyHours && draft.days.length) {
        draft.end_time = fromMin(Math.min(toMin(draft.start_time) + Math.round((props.weeklyHours / draft.days.length) * 60), windowEnd.value));
    }
    if (draft.days.length > 1) filters.viewDay = 'ALL';
    else if (draft.days.length === 1) filters.viewDay = draft.days[0];
};
const presetActive = (p) => sameList(sortDays(p.value), draft.days);

const onStartChange = (value) => {
    draft.start_time = value;
    if (value && sessionMinutes.value) draft.end_time = fromMin(Math.min(toMin(value) + sessionMinutes.value, windowEnd.value));
};
const endChoices = computed(() => timeChoices.value.filter((o) => !draft.start_time || o.value > draft.start_time));

/* ------------------------------------------------------------------ */
/* Smart availability check                                            */
/* ------------------------------------------------------------------ */

const describeConflicts = (list) => {
    const shown = list.slice(0, 2).map((b) => `${b.d} ${fmtRange(b.s, b.e)} (${b.l})`);
    return shown.join('; ') + (list.length > 2 ? ` and ${list.length - 2} more` : '');
};

const selectedFaculty = computed(() => data.faculty.find((f) => f.id === draft.faculty_id) ?? null);
const selectedRoom = computed(() => data.rooms.find((r) => r.id === draft.room_id) ?? null);

const timeProblem = computed(() => {
    if (!draft.start_time || !draft.end_time) return '';
    if (toMin(draft.end_time) <= toMin(draft.start_time)) return 'End time must be after the start time.';
    if (toMin(draft.start_time) < windowStart.value || toMin(draft.end_time) > windowEnd.value) {
        return `Outside the scheduling window (${fmt(windowStart.value)}–${fmt(windowEnd.value)}).`;
    }
    return '';
});

const resourceCheck = (kind, res, label) => {
    if (!res) return { status: 'none', message: `No ${label} selected yet — pick one below.` };
    if (!timeIsComplete.value || timeProblem.value) return { status: 'pending', message: `${kind === 'f' ? res.name : res.code}: choose days and a start/end time to check.` };
    const conflicts = conflictsFor(kind, res, draft.days, draft.start_time, draft.end_time);
    const nm = kind === 'f' ? res.name : res.code;
    if (!conflicts.length) return { status: 'ok', message: `${nm} is AVAILABLE` };
    return { status: 'bad', message: `${nm} is OCCUPIED — ${describeConflicts(conflicts)}` };
};

const facultyCheck = computed(() => resourceCheck('f', selectedFaculty.value, 'faculty'));
const roomCheck = computed(() => (isOnline.value ? { status: 'ok', message: 'Online class — no room needed' } : resourceCheck('r', selectedRoom.value, 'room')));

// This Section's OWN other classes — the third thing Save rejects.
const sectionCheck = computed(() => {
    if (!timeIsComplete.value || timeProblem.value) return { status: 'pending', message: 'Section schedule: choose days and time to check.' };
    const s = toMin(draft.start_time);
    const e = toMin(draft.end_time);
    const hits = [];
    for (const r of props.sectionRows) {
        if (r.id === props.row?.id || !r.days?.length || !r.start_time || !r.end_time) continue;
        if (r.is_merged) continue;
        const overlapDays = r.days.filter((d) => draft.days.includes(d));
        if (overlapDays.length && toMin(r.start_time) < e && toMin(r.end_time) > s) {
            hits.push({ d: overlapDays[0], s: String(r.start_time).slice(0, 5), e: String(r.end_time).slice(0, 5), l: subjectCode(r) });
        }
    }
    if (!hits.length) return { status: 'ok', message: `${props.sectionCode || 'This section'} has no other class at this time` };
    return { status: 'bad', message: `${props.sectionCode || 'This section'} already has a class — ${describeConflicts(hits)}` };
});

const timeCheck = computed(() => {
    if (timeProblem.value) return { status: 'bad', message: timeProblem.value };
    return null;
});

const roomCapacityNote = computed(() => {
    const need = Number(props.row?.capacity ?? 0);
    if (!selectedRoom.value || !need || !selectedRoom.value.capacity) return '';
    return need > selectedRoom.value.capacity ? `Section capacity (${need}) exceeds ${selectedRoom.value.code}'s capacity (${selectedRoom.value.capacity}).` : '';
});

const allClear = computed(() => facultyCheck.value.status === 'ok' && roomCheck.value.status === 'ok' && sectionCheck.value.status === 'ok' && !timeCheck.value);
const hasBlocking = computed(() => [facultyCheck.value, roomCheck.value, sectionCheck.value, timeCheck.value].some((c) => c?.status === 'bad'));

const checkIcon = (status) => ({ ok: 'pi pi-check-circle', bad: 'pi pi-times-circle', pending: 'pi pi-clock', none: 'pi pi-minus-circle' }[status]);
const checkTone = (status) => ({ ok: 'av-tone-ok', bad: 'av-tone-bad', pending: 'av-tone-muted', none: 'av-tone-muted' }[status]);

/* ------------------------------------------------------------------ */
/* Per-resource detail (occupied / available times)                    */
/* ------------------------------------------------------------------ */

const toggleExpanded = (key) => { expanded[key] = !expanded[key]; };

// Contiguous free/busy ranges across the visible window for the considered days.
const rangesFor = (kind, res) => {
    const busy = [];
    const free = [];
    let cur = null;
    for (const i of visibleSlotIdx.value) {
        const isBusy = slotBlocks(kind, res, i).length > 0;
        const t = allSlots.value[i];
        if (cur && cur.busy === isBusy && cur.end === t) cur.end = t + STEP;
        else {
            if (cur) (cur.busy ? busy : free).push(cur);
            cur = { busy: isBusy, start: t, end: t + STEP };
        }
    }
    if (cur) (cur.busy ? busy : free).push(cur);
    return { busy, free };
};
const occupiedList = (kind, res) =>
    blocksFor(kind, res)
        .filter((b) => consideredDays.value.includes(b.d))
        .sort((a, b) => ALL_DAYS.indexOf(a.d) - ALL_DAYS.indexOf(b.d) || toMin(a.s) - toMin(b.s));

/* ------------------------------------------------------------------ */
/* Apply / close                                                       */
/* ------------------------------------------------------------------ */

const hasSelection = computed(() => draft.faculty_id || draft.room_id || draft.days.length || draft.start_time);
const close = () => emit('update:visible', false);
const useSelection = () => {
    emit('apply', {
        faculty_id: draft.faculty_id,
        room_id: isOnline.value ? null : draft.room_id,
        days: [...draft.days],
        start_time: draft.start_time,
        end_time: draft.end_time,
    });
    close();
};

const dayChoices = computed(() => [{ label: draft.days.length > 1 ? 'All selected days' : 'Selected day', value: 'ALL' }, ...allowedDays.value.map((d) => ({ label: DAY_LABELS[d], value: d }))]);
const sortChoices = [
    { label: 'Best match', value: 'best' },
    { label: 'Lowest workload', value: 'workload' },
    { label: 'Name', value: 'name' },
];
const gridColCount = computed(() => visibleSlotIdx.value.length);
</script>

<template>
    <Dialog
        :visible="visible"
        @update:visible="(v) => emit('update:visible', v)"
        modal
        :draggable="false"
        :dismissableMask="false"
        :closeOnEscape="true"
        :style="{ width: '78rem' }"
        :breakpoints="{ '1280px': '96vw', '640px': '100vw' }"
        :contentStyle="{ padding: '0' }"
        :pt="{ root: { class: isDark ? 'dark-scope av-dialog' : 'av-dialog' } }"
    >
        <template #header>
            <div class="min-w-0">
                <p class="text-base font-semibold leading-tight">{{ browse ? (filters.view === 'faculty' ? 'Faculty Availability' : filters.view === 'rooms' ? 'Room Availability' : 'Faculty & Room Availability') : 'Check Availability' }}</p>
                <p v-if="row" class="text-xs text-slate-500 truncate">
                    {{ row.subject?.subject_code }} — {{ row.subject?.subject_title }}
                    <span v-if="sectionCode"> · {{ sectionCode }}</span>
                </p>
                <p v-else class="text-xs text-slate-500 truncate">Pick days and a time to see who and what is free{{ sectionCode ? ` · ${sectionCode}` : '' }}</p>
            </div>
        </template>

        <div class="av-root" :class="{ 'is-dark': isDark }">
            <!-- ================= Selection summary + smart check (always visible) ================= -->
            <section class="av-summary" aria-label="Current selection and availability check">
                <div class="grid grid-cols-1 gap-3" :class="{ 'lg:grid-cols-2': !browse }">
                    <!-- Schedule picker -->
                    <div class="av-card">
                        <p class="av-label">Schedule</p>
                        <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Class days">
                            <button
                                v-for="d in allowedDays"
                                :key="d"
                                type="button"
                                class="av-chip"
                                :class="{ 'av-chip-on': draft.days.includes(d) }"
                                :aria-pressed="draft.days.includes(d)"
                                @click="toggleDay(d)"
                            >
                                {{ DAY_LABELS[d] }}
                            </button>
                            <span class="av-sep" aria-hidden="true"></span>
                            <button
                                v-for="p in presets"
                                :key="p.label"
                                type="button"
                                class="av-chip av-chip-preset"
                                :class="{ 'av-chip-on': presetActive(p) }"
                                :aria-pressed="presetActive(p)"
                                :title="`Set days to ${p.label}`"
                                @click="setDays(p.value)"
                            >
                                {{ p.label }}
                            </button>
                        </div>
                        <div class="grid grid-cols-2 gap-2 mt-2">
                            <Select
                                :modelValue="draft.start_time"
                                :options="timeChoices.slice(0, -1)"
                                optionLabel="label"
                                optionValue="value"
                                placeholder="Start time"
                                size="small"
                                class="w-full"
                                aria-label="Start time"
                                :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                @update:modelValue="onStartChange"
                            />
                            <Select
                                v-model="draft.end_time"
                                :options="endChoices"
                                optionLabel="label"
                                optionValue="value"
                                placeholder="End time"
                                size="small"
                                class="w-full"
                                aria-label="End time"
                                :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                            />
                        </div>
                    </div>

                    <!-- Smart check -->
                    <div v-if="!browse" class="av-card" aria-live="polite">
                        <p class="av-label">Availability check</p>
                        <ul class="space-y-1.5">
                            <li class="av-check" :class="checkTone(facultyCheck.status)">
                                <i :class="checkIcon(facultyCheck.status)" aria-hidden="true"></i>
                                <span><strong>Faculty:</strong> {{ facultyCheck.message }}
                                    <button v-if="facultyCheck.status === 'none' && filters.view === 'rooms'" type="button" class="av-morebtn ml-1" @click="filters.view = 'faculty'">Choose faculty</button>
                                </span>
                            </li>
                            <li class="av-check" :class="checkTone(roomCheck.status)">
                                <i :class="checkIcon(roomCheck.status)" aria-hidden="true"></i>
                                <span><strong>Room:</strong> {{ roomCheck.message }}
                                    <button v-if="roomCheck.status === 'none' && filters.view === 'faculty'" type="button" class="av-morebtn ml-1" @click="filters.view = 'rooms'">Choose room</button>
                                </span>
                            </li>
                            <li class="av-check" :class="checkTone(sectionCheck.status)">
                                <i :class="checkIcon(sectionCheck.status)" aria-hidden="true"></i>
                                <span><strong>Section:</strong> {{ sectionCheck.message }}</span>
                            </li>
                            <li v-if="timeCheck" class="av-check av-tone-bad">
                                <i class="pi pi-times-circle" aria-hidden="true"></i>
                                <span>{{ timeCheck.message }}</span>
                            </li>
                            <li v-if="roomCapacityNote" class="av-check av-tone-warn">
                                <i class="pi pi-exclamation-triangle" aria-hidden="true"></i>
                                <span>{{ roomCapacityNote }}</span>
                            </li>
                        </ul>
                        <p v-if="allClear" class="av-banner av-tone-ok mt-2"><i class="pi pi-check" aria-hidden="true"></i> Faculty, room and section are all free for this time.</p>
                    </div>
                </div>

                <!-- Best suggestion (never auto-assigned) -->
                <div v-if="!browse" class="av-best" aria-live="polite">
                    <template v-if="timeIsComplete">
                        <div class="av-best-row">
                            <i class="pi pi-star-fill av-best-icon" aria-hidden="true"></i>
                            <span v-if="bestFaculty" class="min-w-0"><strong>Best faculty:</strong> {{ bestFaculty.name }} — {{ facultyReasons(bestFaculty).join(' · ') }}</span>
                            <span v-else class="min-w-0 av-tone-muted"><strong>Best faculty:</strong> nobody in scope is free at this time — try another time or open "Other faculty".</span>
                            <button v-if="bestFaculty" type="button" class="av-morebtn shrink-0" :disabled="draft.faculty_id === bestFaculty.id" @click="pickBest('f', bestFaculty)">{{ draft.faculty_id === bestFaculty.id ? 'Selected' : 'Select' }}</button>
                        </div>
                        <div v-if="!isOnline" class="av-best-row">
                            <i class="pi pi-star-fill av-best-icon" aria-hidden="true"></i>
                            <span v-if="bestRoom" class="min-w-0"><strong>Best room:</strong> {{ bestRoom.code }}<template v-if="bestRoom.building"> ({{ bestRoom.building }})</template> — {{ roomReasons(bestRoom).join(' · ') }}</span>
                            <span v-else class="min-w-0 av-tone-muted"><strong>Best room:</strong> no suitable room is free at this time — try another time or open the other room groups.</span>
                            <button v-if="bestRoom" type="button" class="av-morebtn shrink-0" :disabled="draft.room_id === bestRoom.id" @click="pickBest('r', bestRoom)">{{ draft.room_id === bestRoom.id ? 'Selected' : 'Select' }}</button>
                        </div>
                    </template>
                    <p v-else class="av-best-row av-tone-muted"><i class="pi pi-star av-best-icon" aria-hidden="true"></i> Choose days and a start/end time to get a best faculty and best room suggestion.</p>
                </div>

                <!-- Filters -->
                <div class="av-filters">
                    <div v-if="!isOnline" class="av-filter">
                        <span class="av-label" id="av-view-label">Show</span>
                        <div class="flex gap-1.5" role="group" aria-labelledby="av-view-label">
                            <button type="button" class="av-chip" :class="{ 'av-chip-on': filters.view === 'faculty' }" :aria-pressed="filters.view === 'faculty'" @click="filters.view = 'faculty'">Faculty</button>
                            <button type="button" class="av-chip" :class="{ 'av-chip-on': filters.view === 'rooms' }" :aria-pressed="filters.view === 'rooms'" @click="filters.view = 'rooms'">Rooms</button>
                            <button type="button" class="av-chip" :class="{ 'av-chip-on': filters.view === 'both' }" :aria-pressed="filters.view === 'both'" @click="filters.view = 'both'">Both</button>
                        </div>
                    </div>
                    <div class="av-filter">
                        <label class="av-label" for="av-viewday">Day shown</label>
                        <Select
                            inputId="av-viewday"
                            v-model="filters.viewDay"
                            :options="dayChoices"
                            optionLabel="label"
                            optionValue="value"
                            size="small"
                            class="w-full"
                            :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                        />
                    </div>
                    <div class="av-filter av-filter-wide">
                        <label class="av-label" for="av-from">Time range</label>
                        <div class="av-range">
                            <Select inputId="av-from" v-model="filters.from" :options="timeChoices.slice(0, -1)" optionLabel="label" optionValue="value" size="small" class="w-full" aria-label="Show from" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                            <span class="text-xs text-slate-500 px-1">to</span>
                            <Select v-model="filters.to" :options="timeChoices.filter((o) => !filters.from || o.value > filters.from)" optionLabel="label" optionValue="value" size="small" class="w-full" aria-label="Show until" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                        </div>
                    </div>
                    <div class="av-filter">
                        <label class="av-label" for="av-fsearch">Faculty</label>
                        <InputText id="av-fsearch" v-model="filters.facultySearch" placeholder="Search faculty…" size="small" class="w-full" />
                    </div>
                    <div v-if="!isOnline" class="av-filter">
                        <label class="av-label" for="av-rsearch">Room</label>
                        <InputText id="av-rsearch" v-model="filters.roomSearch" placeholder="Search room…" size="small" class="w-full" />
                    </div>
                    <div v-if="!isOnline && buildings.length > 1" class="av-filter">
                        <label class="av-label" for="av-building">Building</label>
                        <Select inputId="av-building" v-model="filters.building" :options="buildings" optionLabel="label" optionValue="value" showClear placeholder="All buildings" size="small" class="w-full" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div v-if="!isOnline && floors.length > 1" class="av-filter">
                        <label class="av-label" for="av-floor">Floor</label>
                        <Select inputId="av-floor" v-model="filters.floor" :options="floors" optionLabel="label" optionValue="value" showClear placeholder="All floors" size="small" class="w-full" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div class="av-filter">
                        <label class="av-label" for="av-sort">Sort by</label>
                        <Select inputId="av-sort" v-model="filters.sort" :options="sortChoices" optionLabel="label" optionValue="value" size="small" class="w-full" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <label class="av-filter av-toggle" :class="{ 'opacity-60': !timeIsComplete }" :title="timeIsComplete ? '' : 'Choose days and a start/end time first'">
                        <input v-model="filters.onlyAvailable" type="checkbox" :disabled="!timeIsComplete" />
                        <span>Only show available for the chosen time</span>
                    </label>
                </div>

                <!-- Legend -->
                <div class="av-legend" aria-label="Legend">
                    <span><i class="av-swatch av-cell-free"><i class="pi pi-check"></i></i> Available</span>
                    <span><i class="av-swatch av-cell-busy"><i class="pi pi-times"></i></i> Occupied</span>
                    <span><i class="av-swatch av-cell-selected"><i class="pi pi-check"></i></i> Your selection</span>
                    <span><i class="av-swatch av-cell-conflict"><i class="pi pi-exclamation-triangle"></i></i> Selection conflicts</span>
                    <span class="inline-flex items-center gap-1">
                        <span class="text-slate-500">Click a cell to set the</span>
                        <button type="button" class="av-chip" :class="{ 'av-chip-on': filters.anchor === 'start' }" :aria-pressed="filters.anchor === 'start'" @click="filters.anchor = 'start'">Start time</button>
                        <button type="button" class="av-chip" :class="{ 'av-chip-on': filters.anchor === 'end' }" :aria-pressed="filters.anchor === 'end'" @click="filters.anchor = 'end'">End time</button>
                    </span>
                    <span class="text-slate-400"><i class="pi pi-step-forward text-[0.6rem]" aria-hidden="true"></i> in the last column ends the class at {{ fmt(endBoundary) }} and counts back.</span>
                </div>
            </section>

            <!-- ================= Grid ================= -->
            <div v-if="loading" class="flex items-center justify-center py-16" role="status" aria-label="Loading availability">
                <ProgressSpinner style="width: 40px; height: 40px" strokeWidth="4" />
            </div>
            <div v-else-if="loadError" class="p-6 text-center">
                <p class="text-sm text-red-500 mb-3"><i class="pi pi-exclamation-triangle mr-1"></i>{{ loadError }}</p>
                <Button label="Retry" icon="pi pi-refresh" size="small" outlined @click="load" />
            </div>
            <div v-else class="av-gridwrap" tabindex="0" aria-label="Availability timetable. Scroll horizontally for more times.">
                <table class="av-table" :style="{ minWidth: `calc(var(--av-name-w, 14rem) + 3.6rem + ${gridColCount} * 1.6rem)` }">
                    <thead>
                        <tr>
                            <th class="av-stick av-name-col av-corner" rowspan="1">
                                <span class="sr-only">Resource</span>
                                <span class="text-[0.65rem] uppercase tracking-wide text-slate-500">
                                    {{ filters.viewDay === 'ALL' ? (consideredDays.length > 1 ? consideredDays.join(' + ') : consideredDays[0]) : filters.viewDay }}
                                </span>
                                <span v-if="visibleSlotIdx.length" class="block text-[0.6rem] font-normal normal-case tracking-normal text-slate-500">
                                    {{ fmt(allSlots[visibleSlotIdx[0]]) }}–{{ fmt(allSlots[visibleSlotIdx[visibleSlotIdx.length - 1]] + STEP) }} · each column = 30 min, labeled by its start
                                </span>
                            </th>
                            <th v-for="h in hourHeaders" :key="h.hour" :colspan="h.span" class="av-hour" :class="{ 'av-half': h.half }" :title="`${fmt(h.hour)} – ${fmt(h.hour + STEP)}`">{{ h.label }}</th>
                            <th class="av-hour av-endcap" :title="`Class ends at ${fmt(endBoundary)} — click a row's ⏭ to end the class here`">{{ fmt(endBoundary) }}</th>
                        </tr>
                    </thead>

                    <tbody>
                        <!-- FACULTY -->
                        <tr v-if="filters.view !== 'rooms'" class="av-group"><th :colspan="gridColCount + 2"><span class="av-sticky-inline">Faculty — showing {{ facultyShown }} of {{ facultyMatching }}</span></th></tr>
                        <tr v-if="filters.view !== 'rooms' && !facultyGroups.length"><td :colspan="gridColCount + 2" class="av-empty">No faculty match the filters.</td></tr>
                        <template v-if="filters.view !== 'rooms'">
                        <template v-for="g in facultyGroups" :key="g.key">
                        <tr class="av-subgroup">
                            <th :colspan="gridColCount + 2">
                                <span class="av-sticky-inline">
                                    <span class="av-subgroup-label">{{ g.label }} <span class="av-subgroup-count">({{ g.all.length }})</span></span>
                                    <button v-if="g.collapsible && (!g.open || showMore[g.key])" type="button" class="av-morebtn" :aria-expanded="g.open" @click="toggleMore(g.key)">
                                        {{ showMore[g.key] ? 'Hide' : `Show ${g.hidden} more` }}
                                    </button>
                                </span>
                            </th>
                        </tr>
                        <template v-for="f in g.visible" :key="`f-${f.id}`">
                            <tr :class="{ 'av-row-selected': draft.faculty_id === f.id }">
                                <th scope="row" class="av-stick av-name-col">
                                    <div class="av-name">
                                        <div class="min-w-0 flex-1">
                                            <button type="button" class="av-namebtn" :aria-expanded="!!expanded[`f-${f.id}`]" @click="toggleExpanded(`f-${f.id}`)">
                                                <i class="pi text-[0.6rem]" :class="expanded[`f-${f.id}`] ? 'pi-chevron-down' : 'pi-chevron-right'" aria-hidden="true"></i>
                                                <span class="truncate">{{ f.name }}</span>
                                            </button>
                                            <p class="av-meta">
                                                {{ f.subject_count }} subject{{ f.subject_count === 1 ? '' : 's' }}
                                                <template v-if="f.max_units != null"> · {{ f.current_load }}/{{ f.max_units }} units</template>
                                            </p>
                                        </div>
                                        <Button
                                            v-if="!browse"
                                            :label="draft.faculty_id === f.id ? 'Selected' : 'Select'"
                                            :icon="draft.faculty_id === f.id ? 'pi pi-check' : undefined"
                                            size="small"
                                            :severity="draft.faculty_id === f.id ? 'success' : 'secondary'"
                                            :outlined="draft.faculty_id !== f.id"
                                            class="!text-[0.7rem] !py-0.5 !px-2 shrink-0"
                                            :aria-pressed="draft.faculty_id === f.id"
                                            :aria-label="`${draft.faculty_id === f.id ? 'Deselect' : 'Select'} ${f.name}`"
                                            @click="selectResource('f', f)"
                                        />
                                    </div>
                                    <div class="av-badges">
                                        <Tag v-for="b in badgesFor('f', f)" :key="b.key" :value="b.label" :icon="b.icon" :severity="b.severity" class="!text-[0.6rem] !py-0 !px-1.5" :title="b.key === 'rec' ? recommendationReason('f', f) : undefined" />
                                    </div>
                                </th>
                                <td
                                    v-for="i in visibleSlotIdx"
                                    :key="i"
                                    class="av-td"
                                    :class="[`av-cell-${cellInfo('f', f, i).state}`, { 'av-hour-start': allSlots[i] % 60 === 0 }]"
                                >
                                    <button
                                        type="button"
                                        class="av-cell"
                                        :title="cellInfo('f', f, i).text"
                                        :aria-label="cellInfo('f', f, i).aria"
                                        :aria-disabled="cellInfo('f', f, i).locked"
                                        :tabindex="cellInfo('f', f, i).locked ? -1 : 0"
                                        @click="onCell('f', f, i)"
                                    >
                                        <i
                                            class="pi"
                                            :class="{
                                                'pi-check': cellInfo('f', f, i).state === 'free' || cellInfo('f', f, i).state === 'selected',
                                                'pi-times': cellInfo('f', f, i).state === 'busy',
                                                'pi-exclamation-triangle': cellInfo('f', f, i).state === 'conflict',
                                            }"
                                            aria-hidden="true"
                                        ></i>
                                    </button>
                                </td>
                                <td class="av-td av-endtd">
                                    <button
                                        type="button"
                                        class="av-cell av-endbtn"
                                        :class="endCapInfo('f', f).ok ? 'av-end-ok' : 'av-end-no'"
                                        :title="endCapInfo('f', f).text"
                                        :aria-label="`${f.name}, ${endCapInfo('f', f).text}`"
                                        :aria-disabled="!endCapInfo('f', f).ok"
                                        :tabindex="endCapInfo('f', f).ok ? 0 : -1"
                                        @click="onEndCap('f', f)"
                                    >
                                        <i class="pi" :class="endCapInfo('f', f).ok ? 'pi-step-forward' : 'pi-times'" aria-hidden="true"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="expanded[`f-${f.id}`]" class="av-detail">
                                <td :colspan="gridColCount + 2">
                                    <div class="av-detail-inner">
                                        <p><strong>Occupied:</strong>
                                            <template v-if="occupiedList('f', f).length">
                                                <span v-for="(b, n) in occupiedList('f', f)" :key="n" class="av-pill av-pill-busy">{{ b.d }} {{ fmtRange(b.s, b.e) }} · {{ b.l }}</span>
                                            </template>
                                            <span v-else class="text-slate-500"> nothing scheduled on the shown day(s)</span>
                                        </p>
                                        <p><strong>Available:</strong>
                                            <template v-if="rangesFor('f', f).free.length">
                                                <span v-for="(r, n) in rangesFor('f', f).free" :key="n" class="av-pill av-pill-free">{{ fmtRange(r.start, r.end) }}</span>
                                            </template>
                                            <span v-else class="text-slate-500"> no free time in the shown range</span>
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        </template>
                        </template>

                        <!-- ROOMS -->
                        <template v-if="!isOnline && filters.view !== 'faculty'">
                            <tr class="av-group"><th :colspan="gridColCount + 2"><span class="av-sticky-inline">Rooms — showing {{ roomsShown }} of {{ roomsMatching }}</span></th></tr>
                            <tr v-if="!roomGroups.length"><td :colspan="gridColCount + 2" class="av-empty">No rooms match the filters.</td></tr>
                            <template v-for="g in roomGroups" :key="g.key">
                            <tr class="av-subgroup">
                                <th :colspan="gridColCount + 2">
                                    <span class="av-sticky-inline">
                                        <span class="av-subgroup-label">{{ g.label }} <span class="av-subgroup-count">({{ g.all.length }})</span></span>
                                        <button v-if="g.collapsible && (!g.open || showMore[g.key])" type="button" class="av-morebtn" :aria-expanded="g.open" @click="toggleMore(g.key)">
                                            {{ showMore[g.key] ? 'Hide' : `Show ${g.hidden} more` }}
                                        </button>
                                    </span>
                                </th>
                            </tr>
                            <template v-for="r in g.visible" :key="`r-${r.id}`">
                                <tr :class="{ 'av-row-selected': draft.room_id === r.id, 'av-row-blocked': roomBlocked(r) }">
                                    <th scope="row" class="av-stick av-name-col">
                                        <div class="av-name">
                                            <div class="min-w-0 flex-1">
                                                <button type="button" class="av-namebtn" :aria-expanded="!!expanded[`r-${r.id}`]" @click="toggleExpanded(`r-${r.id}`)">
                                                    <i class="pi text-[0.6rem]" :class="expanded[`r-${r.id}`] ? 'pi-chevron-down' : 'pi-chevron-right'" aria-hidden="true"></i>
                                                    <span class="truncate">{{ r.code }}<span v-if="r.name && r.name !== r.code" class="font-normal text-slate-500"> — {{ r.name }}</span></span>
                                                </button>
                                                <p class="av-meta">
                                                    {{ r.class_count }} class{{ r.class_count === 1 ? '' : 'es' }}
                                                    <template v-if="r.type"> · {{ r.type }}</template>
                                                    <template v-if="r.capacity"> · cap {{ r.capacity }}</template>
                                                    <template v-if="r.building"> · {{ r.building }}<template v-if="r.floor"> F{{ r.floor }}</template></template>
                                                </p>
                                            </div>
                                            <Button
                                                v-if="!browse"
                                                :label="draft.room_id === r.id ? 'Selected' : 'Select'"
                                                :icon="draft.room_id === r.id ? 'pi pi-check' : undefined"
                                                size="small"
                                                :severity="draft.room_id === r.id ? 'success' : 'secondary'"
                                                :outlined="draft.room_id !== r.id"
                                                class="!text-[0.7rem] !py-0.5 !px-2 shrink-0"
                                                :aria-pressed="draft.room_id === r.id"
                                                :disabled="roomBlocked(r)"
                                                :aria-label="`${draft.room_id === r.id ? 'Deselect' : 'Select'} ${r.code}`"
                                                @click="selectResource('r', r)"
                                            />
                                        </div>
                                        <div class="av-badges">
                                            <Tag v-for="b in badgesFor('r', r)" :key="b.key" :value="b.label" :icon="b.icon" :severity="b.severity" class="!text-[0.6rem] !py-0 !px-1.5" :title="b.key === 'rec' ? recommendationReason('r', r) : undefined" />
                                        </div>
                                    </th>
                                    <td
                                        v-for="i in visibleSlotIdx"
                                        :key="i"
                                        class="av-td"
                                        :class="[`av-cell-${cellInfo('r', r, i).state}`, { 'av-hour-start': allSlots[i] % 60 === 0 }]"
                                    >
                                        <button
                                            type="button"
                                            class="av-cell"
                                            :title="cellInfo('r', r, i).text"
                                            :aria-label="cellInfo('r', r, i).aria"
                                            :aria-disabled="cellInfo('r', r, i).locked"
                                            :tabindex="cellInfo('r', r, i).locked ? -1 : 0"
                                            @click="onCell('r', r, i)"
                                        >
                                            <i
                                                class="pi"
                                                :class="{
                                                    'pi-check': cellInfo('r', r, i).state === 'free' || cellInfo('r', r, i).state === 'selected',
                                                    'pi-times': cellInfo('r', r, i).state === 'busy',
                                                    'pi-exclamation-triangle': cellInfo('r', r, i).state === 'conflict',
                                                }"
                                                aria-hidden="true"
                                            ></i>
                                        </button>
                                    </td>
                                <td class="av-td av-endtd">
                                    <button
                                        type="button"
                                        class="av-cell av-endbtn"
                                        :class="endCapInfo('r', r).ok ? 'av-end-ok' : 'av-end-no'"
                                        :title="endCapInfo('r', r).text"
                                        :aria-label="`${r.code}, ${endCapInfo('r', r).text}`"
                                        :aria-disabled="!endCapInfo('r', r).ok"
                                        :tabindex="endCapInfo('r', r).ok ? 0 : -1"
                                        @click="onEndCap('r', r)"
                                    >
                                        <i class="pi" :class="endCapInfo('r', r).ok ? 'pi-step-forward' : 'pi-times'" aria-hidden="true"></i>
                                    </button>
                                </td>
                                </tr>
                                <tr v-if="expanded[`r-${r.id}`]" class="av-detail">
                                    <td :colspan="gridColCount + 2">
                                        <div class="av-detail-inner">
                                            <p><strong>Occupied:</strong>
                                                <template v-if="occupiedList('r', r).length">
                                                    <span v-for="(b, n) in occupiedList('r', r)" :key="n" class="av-pill av-pill-busy">{{ b.d }} {{ fmtRange(b.s, b.e) }} · {{ b.l }}</span>
                                                </template>
                                                <span v-else class="text-slate-500"> nothing scheduled on the shown day(s)</span>
                                            </p>
                                            <p><strong>Available:</strong>
                                                <template v-if="rangesFor('r', r).free.length">
                                                    <span v-for="(rg, n) in rangesFor('r', r).free" :key="n" class="av-pill av-pill-free">{{ fmtRange(rg.start, rg.end) }}</span>
                                                </template>
                                                <span v-else class="text-slate-500"> no free time in the shown range</span>
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            </template>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <template #footer>
            <div class="flex flex-wrap items-center justify-between gap-2 w-full">
                <p v-if="browse" class="text-xs text-slate-500 min-w-0">
                    View only — click a cell to test a day and time, then use "Only show available for the chosen time".
                </p>
                <p v-else class="text-xs text-slate-500 min-w-0 truncate" aria-live="polite">
                    <template v-if="hasSelection">
                        <strong>{{ selectedFaculty?.name ?? 'No faculty' }}</strong>
                        <template v-if="!isOnline"> · <strong>{{ selectedRoom?.code ?? 'No room' }}</strong></template>
                        <template v-if="draft.days.length"> · {{ draft.days.join('/') }}</template>
                        <template v-if="draft.start_time && draft.end_time"> {{ fmtRange(draft.start_time, draft.end_time) }}</template>
                    </template>
                    <template v-else>Nothing selected yet.</template>
                    <span v-if="hasBlocking" class="av-tone-bad ml-2"><i class="pi pi-exclamation-triangle" aria-hidden="true"></i> Has conflicts — Save Schedule will reject it.</span>
                </p>
                <div class="flex items-center gap-2">
                    <Button :label="browse ? 'Close' : 'Cancel'" severity="secondary" text size="small" @click="close" />
                    <Button v-if="!browse" label="Use Selection" icon="pi pi-check" size="small" :disabled="!hasSelection" @click="useSelection" />
                </div>
            </div>
        </template>
    </Dialog>
</template>

<style>
/* Unscoped on purpose: the Dialog teleports to <body>. Every selector is
   prefixed `av-` / `.av-root` so nothing leaks into the rest of the app. */
.av-root {
    --av-name-w: 14rem;
    --av-bg: #ffffff;
    --av-bg-soft: #f8fafc;
    --av-border: #e2e8f0;
    --av-text: #1e293b;
    --av-muted: #64748b;
    --av-free-bg: #ecfdf5;
    --av-free-fg: #047857;
    --av-free-bd: #a7f3d0;
    --av-busy-bg: #ffe4e6;
    --av-busy-fg: #be123c;
    --av-busy-bd: #fecdd3;
    --av-sel-bg: #4f46e5;
    --av-sel-fg: #ffffff;
    --av-conf-bg: #be123c;
    --av-warn: #b45309;
    color: var(--av-text);
}
.av-root.is-dark {
    --av-bg: #1e293b;
    --av-bg-soft: #162033;
    --av-border: #334155;
    --av-text: #e2e8f0;
    --av-muted: #94a3b8;
    --av-free-bg: rgba(16, 185, 129, 0.14);
    --av-free-fg: #6ee7b7;
    --av-free-bd: rgba(16, 185, 129, 0.35);
    --av-busy-bg: rgba(244, 63, 94, 0.2);
    --av-busy-fg: #fda4af;
    --av-busy-bd: rgba(244, 63, 94, 0.4);
    --av-sel-bg: #6366f1;
    --av-conf-bg: #e11d48;
    --av-warn: #fbbf24;
}

.av-summary { padding: 0.75rem 1rem 0.5rem; border-bottom: 1px solid var(--av-border); background: var(--av-bg-soft); }
.av-card { background: var(--av-bg); border: 1px solid var(--av-border); border-radius: 0.75rem; padding: 0.6rem 0.75rem; }
.av-label { display: block; font-size: 0.65rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--av-muted); margin-bottom: 0.25rem; }

.av-chip { font-size: 0.72rem; font-weight: 600; padding: 0.2rem 0.55rem; border-radius: 999px; border: 1px solid var(--av-border); background: var(--av-bg); color: var(--av-text); cursor: pointer; }
.av-chip:hover { border-color: var(--av-sel-bg); }
.av-chip-on { background: var(--av-sel-bg); border-color: var(--av-sel-bg); color: var(--av-sel-fg); }
.av-chip-preset { border-style: dashed; }
.av-chip:focus-visible, .av-namebtn:focus-visible, .av-cell:focus-visible { outline: 2px solid var(--av-sel-bg); outline-offset: 1px; }
.av-sep { width: 1px; height: 1.1rem; background: var(--av-border); margin: 0 0.25rem; }

.av-check { display: flex; align-items: flex-start; gap: 0.4rem; font-size: 0.78rem; line-height: 1.25rem; }
.av-check i { margin-top: 0.2rem; }
.av-tone-ok { color: var(--av-free-fg); }
.av-tone-bad { color: var(--av-busy-fg); }
.av-tone-warn { color: var(--av-warn); }
.av-tone-muted { color: var(--av-muted); }
.av-banner { font-size: 0.75rem; font-weight: 600; padding: 0.3rem 0.5rem; border-radius: 0.5rem; background: var(--av-free-bg); border: 1px solid var(--av-free-bd); }

.av-best { margin-top: 0.6rem; background: var(--av-bg); border: 1px solid var(--av-border); border-left: 3px solid var(--av-sel-bg); border-radius: 0.6rem; padding: 0.4rem 0.7rem; display: grid; gap: 0.25rem; }
.av-best-row { display: flex; align-items: center; gap: 0.5rem; font-size: 0.76rem; line-height: 1.2rem; }
.av-best-icon { color: #f59e0b; font-size: 0.75rem; }
.av-morebtn:disabled { opacity: 0.6; cursor: default; }
.av-morebtn:disabled:hover { background: transparent; color: var(--av-sel-bg); }
.av-filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: 0.6rem 0.75rem; margin-top: 0.6rem; align-items: end; }
.av-filter { min-width: 0; }
.av-filter .p-select, .av-filter .p-inputtext { min-width: 0; width: 100%; }
.av-filter-wide { grid-column: span 2; }
.av-range { display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); align-items: center; gap: 0.25rem; }
.av-toggle { grid-column: 1 / -1; display: flex; align-items: center; gap: 0.4rem; font-size: 0.75rem; }
.av-legend { display: flex; flex-wrap: wrap; gap: 0.35rem 1rem; align-items: center; margin-top: 0.5rem; font-size: 0.7rem; color: var(--av-muted); }
.av-legend > span { display: inline-flex; align-items: center; gap: 0.3rem; }
.av-swatch { display: inline-flex; align-items: center; justify-content: center; width: 1.1rem; height: 1.1rem; border-radius: 0.25rem; font-size: 0.55rem; font-style: normal; border: 1px solid transparent; }

/* ---- grid ---- */
.av-gridwrap { overflow: auto; max-height: min(52vh, 40rem); background: var(--av-bg); }
.av-table { border-collapse: separate; border-spacing: 0; width: 100%; table-layout: fixed; font-size: 0.75rem; }
.av-table thead th { position: sticky; top: 0; z-index: 3; background: var(--av-bg-soft); border-bottom: 1px solid var(--av-border); }
.av-hour { overflow: hidden; font-size: 0.62rem; font-weight: 600; color: var(--av-muted); text-align: center; padding: 0.35rem 0; border-left: 1px solid var(--av-border); white-space: nowrap; }
.av-endcap { width: 3.6rem; min-width: 3.6rem; border-left: 1px solid var(--av-border); color: var(--av-text); }
.av-endtd { border-left: 1px solid var(--av-border); }
.av-end-ok { background: var(--av-bg); color: var(--av-sel-bg); border: 1px dashed var(--av-sel-bg); }
.av-end-ok:hover { background: var(--av-sel-bg); color: var(--av-sel-fg); }
.av-end-no { background: transparent; color: var(--av-busy-fg); border: 1px dashed var(--av-busy-bd); cursor: not-allowed; opacity: 0.7; }
.av-half { font-weight: 400; opacity: 0.8; border-left-style: dotted; }
.av-stick { position: sticky; left: 0; z-index: 2; background: var(--av-bg); border-right: 1px solid var(--av-border); }
.av-table thead .av-stick { z-index: 4; background: var(--av-bg-soft); }
.av-name-col { width: 14rem; min-width: 14rem; max-width: 14rem; text-align: left; padding: 0.35rem 0.6rem; font-weight: 500; vertical-align: top; }
.av-corner { vertical-align: middle; }
.av-name { display: flex; align-items: center; gap: 0.4rem; }
.av-namebtn { display: flex; align-items: center; gap: 0.3rem; max-width: 100%; background: none; border: 0; padding: 0; font-weight: 600; font-size: 0.78rem; color: var(--av-text); cursor: pointer; text-align: left; }
.av-meta { font-size: 0.65rem; color: var(--av-muted); margin-top: 0.1rem; }
.av-badges { display: flex; flex-wrap: wrap; gap: 0.2rem; margin-top: 0.25rem; }
.av-group th { position: sticky; left: 0; background: var(--av-bg-soft); font-size: 0.65rem; letter-spacing: 0.06em; text-transform: uppercase; color: var(--av-muted); text-align: left; padding: 0.35rem 0.6rem; border-top: 1px solid var(--av-border); border-bottom: 1px solid var(--av-border); }
.av-sticky-inline { position: sticky; left: 0; display: inline-flex; align-items: center; gap: 0.6rem; padding-right: 1rem; }
.av-subgroup th { background: var(--av-bg-soft); text-align: left; padding: 0.3rem 0.6rem; border-bottom: 1px solid var(--av-border); font-weight: 600; font-size: 0.72rem; color: var(--av-text); }
.av-subgroup-count { color: var(--av-muted); font-weight: 500; }
.av-morebtn { font-size: 0.68rem; font-weight: 600; padding: 0.1rem 0.55rem; border-radius: 999px; border: 1px solid var(--av-sel-bg); color: var(--av-sel-bg); background: transparent; cursor: pointer; }
.av-morebtn:hover { background: var(--av-sel-bg); color: var(--av-sel-fg); }
.av-row-blocked .av-name-col, .av-row-blocked .av-td { opacity: 0.55; }
.av-row-blocked .av-cell { cursor: not-allowed; }
.av-empty { padding: 0.8rem; text-align: center; color: var(--av-muted); font-size: 0.75rem; }
.av-row-selected .av-name-col { box-shadow: inset 3px 0 0 var(--av-sel-bg); }

.av-td { padding: 2px; min-width: 0; height: 2.1rem; border-bottom: 1px solid var(--av-border); }
.av-hour-start { border-left: 1px solid var(--av-border); }
.av-cell { width: 100%; height: 100%; min-height: 1.8rem; min-width: 0; display: flex; align-items: center; justify-content: center; border-radius: 0.35rem; border: 1px solid transparent; font-size: 0.65rem; cursor: pointer; background: transparent; padding: 0; }
.av-cell-free .av-cell, .av-swatch.av-cell-free { background: var(--av-free-bg); color: var(--av-free-fg); border-color: var(--av-free-bd); }
.av-cell-free .av-cell:hover { filter: brightness(0.95); box-shadow: 0 0 0 2px var(--av-sel-bg) inset; }
/* Diagonal hatch on occupied cells so state never relies on colour alone. */
.av-cell-busy .av-cell, .av-swatch.av-cell-busy {
    background: repeating-linear-gradient(135deg, var(--av-busy-bg), var(--av-busy-bg) 4px, transparent 4px, transparent 7px), var(--av-busy-bg);
    color: var(--av-busy-fg); border-color: var(--av-busy-bd); cursor: not-allowed;
}
.av-cell-selected .av-cell, .av-swatch.av-cell-selected { background: var(--av-sel-bg); color: var(--av-sel-fg); border-color: var(--av-sel-bg); }
.av-cell-conflict .av-cell, .av-swatch.av-cell-conflict { background: var(--av-conf-bg); color: #fff; border-color: var(--av-conf-bg); cursor: not-allowed; }

.av-detail td { background: var(--av-bg-soft); border-bottom: 1px solid var(--av-border); padding: 0; }
.av-detail-inner { position: sticky; left: 0; width: min(100%, 60rem); padding: 0.5rem 0.75rem 0.6rem 1.4rem; font-size: 0.72rem; line-height: 1.5rem; }
.av-pill { display: inline-block; margin: 0 0.25rem 0.15rem 0; padding: 0 0.45rem; border-radius: 999px; font-size: 0.68rem; border: 1px solid transparent; }
.av-pill-busy { background: var(--av-busy-bg); color: var(--av-busy-fg); border-color: var(--av-busy-bd); }
.av-pill-free { background: var(--av-free-bg); color: var(--av-free-fg); border-color: var(--av-free-bd); }

/* Small screens: tighter name column + cells, same horizontal scroll. */
@media (max-width: 480px) {
    .av-filter-wide { grid-column: 1 / -1; }
}
@media (max-width: 640px) {
    .av-name-col { width: 11rem; min-width: 11rem; max-width: 11rem; }
    .av-gridwrap { max-height: 46vh; }
    .av-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (prefers-reduced-motion: no-preference) {
    .av-cell { transition: box-shadow 0.12s ease, filter 0.12s ease; }
}
</style>