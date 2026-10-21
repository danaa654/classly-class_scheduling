<script setup>
/**
 * ROOM OCCUPANCY — Dashboard card.
 *
 * "Which rooms are being used right now?" Pick a day and a time (it opens on
 * today, right now) and every room lights up if a class is in session:
 *
 *   Room 302  8:00–12:00  BSIT-1A   -> lit at 10:00 AM, dim at 2:00 PM
 *
 *   In use    a class is running at the chosen time (subject, section,
 *             faculty, time range and how far along it is)
 *   Up next   no class now, but one starts later that day
 *   Free      nothing left today (or nothing scheduled that day)
 *
 * Complements the Rooms page's weekly Room Grid (Floor x Day): that one shows
 * the whole week, this one shows ONE moment. It reads the very same endpoint
 * (scheduling.rooms.grid), so it is already scoped to the user's role/College
 * and to the Active Academic Term, and can never disagree with the Room Grid.
 * Day / time changes are computed in the browser, so scrubbing is instant.
 *
 * Read-only. No backend change needed.
 */
import { Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import InfoPopover from '@/Components/InfoPopover.vue';

const props = defineProps({
    isDark: { type: Boolean, default: false },
    // School / College colour (the Dashboard hero's colour). Defaults to the
    // school red of the PAP building; a Dean/OIC gets their College colour.
    accent: { type: String, default: '#E11D2E' },
});

/* ------------------------------------------------------------------ */
/* Colours — derived from the school colour                            */
/* ------------------------------------------------------------------ */

// Tells the Dashboard which day/moment is showing, so the Faculty Today
// sidebar beside this card always follows the same day (and Live clock).
const emit = defineEmits(['snapshot']);

const HEX = /^#[0-9a-f]{6}$/i;
const base = computed(() => (HEX.test(props.accent ?? '') ? props.accent : '#E11D2E'));
const rgb = (hex) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
const toHex = (c) => '#' + c.map((v) => Math.round(v).toString(16).padStart(2, '0')).join('');
const mix = (hex, other, t) => toHex(rgb(hex).map((c, i) => c * (1 - t) + rgb(other)[i] * t));
const luminance = (hex) => {
    const [r, g, b] = rgb(hex).map((c) => {
        const v = c / 255;
        return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
const lit = computed(() => mix(base.value, '#000000', 0.08));
// White text on the lit tile unless the colour is light (e.g. a yellow College).
const onLit = computed(() => (luminance(lit.value) < 0.19 ? '#ffffff' : '#111827'));
// Accent used for text/links on the card surface, kept readable in both modes.
const accentText = computed(() => {
    if (props.isDark) return luminance(base.value) < 0.25 ? mix(base.value, '#ffffff', 0.38) : base.value;
    return luminance(base.value) > 0.3 ? mix(base.value, '#000000', 0.45) : base.value;
});
const themeVars = computed(() => ({
    '--ro-lit': lit.value,
    '--ro-on-lit': onLit.value,
    '--ro-accent': accentText.value,
    '--ro-glow': `${base.value}80`,
}));

/* ------------------------------------------------------------------ */
/* Time helpers                                                        */
/* ------------------------------------------------------------------ */

const toMin = (hhmm) => {
    if (!hhmm) return null;
    const [h, m] = String(hhmm).slice(0, 5).split(':').map(Number);
    return h * 60 + m;
};
const meridiem = (min) => (Math.floor(min / 60) >= 12 ? 'PM' : 'AM');
const clock = (min) => `${Math.floor(min / 60) % 12 === 0 ? 12 : Math.floor(min / 60) % 12}:${String(min % 60).padStart(2, '0')}`;
const fmt = (min) => `${clock(min)} ${meridiem(min)}`;
// "8:00–12:00 PM" — one AM/PM when both ends share it, otherwise both.
const rangeText = (s, e) => (meridiem(s) === meridiem(e) ? `${clock(s)}–${clock(e)} ${meridiem(e)}` : `${fmt(s)}–${fmt(e)}`);

const DAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const DAY_LONG = { Mon: 'Monday', Tue: 'Tuesday', Wed: 'Wednesday', Thu: 'Thursday', Fri: 'Friday', Sat: 'Saturday', Sun: 'Sunday' };

/* ------------------------------------------------------------------ */
/* Data                                                                */
/* ------------------------------------------------------------------ */

const loading = ref(true);
const loadError = ref('');
const data = reactive({ days: [], window: { start: '07:00', end: '19:00' }, rooms: [] });

const load = async () => {
    loading.value = true;
    loadError.value = '';
    try {
        const { data: json } = await window.axios.get(route('scheduling.rooms.grid'));
        data.days = json.days ?? [];
        data.window = json.window ?? data.window;
        data.rooms = json.rooms ?? [];
        // First load: open on today's day (or the first class day when today
        // is not one, e.g. a Sunday). Reloads keep whatever the user picked.
        if (!day.value) day.value = data.days.includes(todayName()) ? todayName() : data.days[0] ?? null;
    } catch (e) {
        loadError.value = 'Could not load room occupancy.';
    } finally {
        loading.value = false;
    }
};

/* ------------------------------------------------------------------ */
/* Selected day + moment                                               */
/* ------------------------------------------------------------------ */

const todayName = () => DAY_NAMES[new Date().getDay()];
const nowMinutes = () => new Date().getHours() * 60 + new Date().getMinutes();

const clockNow = ref(nowMinutes());
const today = ref(todayName());
let tick = null;
onMounted(() => {
    load();
    // Keeps "right now" honest if the dashboard stays open all day.
    tick = setInterval(() => {
        clockNow.value = nowMinutes();
        today.value = todayName();
    }, 30000);
});
onBeforeUnmount(() => tick && clearInterval(tick));

const day = ref(null);
// While `live` is on, the chosen moment follows the real clock. Dragging the
// slider or picking another day turns it off; "Now" turns it back on.
const live = ref(true);
const manualMinute = ref(null);

const minute = computed(() => (live.value ? clockNow.value : manualMinute.value ?? clockNow.value));

const windowStart = computed(() => toMin(data.window.start) ?? 420);
const windowEnd = computed(() => toMin(data.window.end) ?? 1140);

// Slider position only: the clock can sit outside the class-hours window
// (e.g. 8 PM), but the thumb stays on the track.
const sliderValue = computed({
    get: () => Math.min(Math.max(minute.value, windowStart.value), windowEnd.value),
    set: (v) => {
        live.value = false;
        manualMinute.value = Number(v);
    },
});

const canGoLive = computed(() => data.days.includes(today.value));
const isLiveNow = computed(() => live.value && day.value === today.value);

const pickDay = (d) => {
    day.value = d;
    // Another day than today can't be "now": keep the time where it is so the
    // user can compare the same hour across days.
    if (d !== today.value) {
        manualMinute.value = minute.value;
        live.value = false;
    }
};
const goNow = () => {
    if (!canGoLive.value) return;
    day.value = today.value;
    live.value = true;
};

const afterHours = computed(() => minute.value < windowStart.value || minute.value >= windowEnd.value);

/* ------------------------------------------------------------------ */
/* Room state at the chosen day + moment                               */
/* ------------------------------------------------------------------ */

const stateFor = (room) => {
    const blocks = (room.blocks?.[day.value] ?? []).map((b) => ({ ...b, sm: toMin(b.s), em: toMin(b.e) }));
    const t = minute.value;
    const current = blocks.find((b) => b.sm <= t && t < b.em);
    if (current) {
        return { key: 'live', block: current, progress: Math.round(((t - current.sm) / (current.em - current.sm)) * 100) };
    }
    const next = blocks.find((b) => b.sm > t);
    if (next) return { key: 'next', block: next };
    return { key: 'free', hadClasses: blocks.length > 0 };
};

const states = computed(() => {
    const map = new Map();
    for (const room of data.rooms) map.set(room.id, stateFor(room));
    return map;
});

const counts = computed(() => {
    const c = { live: 0, next: 0, free: 0 };
    for (const s of states.value.values()) c[s.key]++;
    return c;
});

/* ------------------------------------------------------------------ */
/* Filters + grouping (building -> floors, top floor first)            */
/* ------------------------------------------------------------------ */

const stateFilter = ref(null); // null = all
const toggleFilter = (key) => {
    stateFilter.value = stateFilter.value === key ? null : key;
};
const building = ref(null);

const buildings = computed(() => [...new Set(data.rooms.map((r) => r.building).filter(Boolean))].sort());

const visibleRooms = computed(() =>
    data.rooms.filter(
        (r) => (!building.value || r.building === building.value) && (!stateFilter.value || states.value.get(r.id)?.key === stateFilter.value),
    ),
);

const natural = (a, b) => String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });

const grouped = computed(() => {
    const byBuilding = new Map();
    for (const room of visibleRooms.value) {
        const bKey = room.building || 'Rooms';
        if (!byBuilding.has(bKey)) byBuilding.set(bKey, new Map());
        const floors = byBuilding.get(bKey);
        const fKey = room.floor || 'No floor';
        if (!floors.has(fKey)) floors.set(fKey, { label: fKey, order: room.floor_order ?? 999, rooms: [] });
        floors.get(fKey).rooms.push(room);
    }
    return [...byBuilding.entries()]
        .sort((a, b) => natural(a[0], b[0]))
        .map(([name, floors]) => ({
            name,
            floors: [...floors.values()]
                .sort((a, b) => b.order - a.order || natural(a.label, b.label))
                .map((f) => ({ ...f, rooms: [...f.rooms].sort((a, b) => natural(a.code, b.code)) })),
        }));
});

const floorBadge = (f) => (f.order > 0 && f.order < 100 ? String(f.order) : f.order === 0 ? 'G' : f.order < 0 ? 'B' : '•');

const typeShort = (t) => (t === 'Laboratory' ? 'Lab' : t === 'Lecture' ? 'Lecture' : t || '');

// "Room 201 (Forensic BSCRIM Lab)" -> "Room 201" so every tile stays small.
const shortCode = (room) => String(room.code ?? '').replace(/\s*\(.*\)\s*$/, '').trim() || room.code;

// The tile only has room for a code + one line; hover, focus or tap a tile to
// read the rest in the detail strip (it keeps the last room you looked at).
const focusId = ref(null);
const focused = computed(() => {
    const room = data.rooms.find((r) => r.id === focusId.value);
    if (!room) return null;
    const t = minute.value;
    const blocks = (room.blocks?.[day.value] ?? []).map((b) => ({ ...b, sm: toMin(b.s), em: toMin(b.e), now: toMin(b.s) <= t && t < toMin(b.e) }));
    return { room, state: states.value.get(room.id), blocks };
});

/* The Rooms page remembers its List/Grid choice per browser; opening it from
   here should land on the weekly Room Grid. */
const rememberGridView = () => {
    try {
        localStorage.setItem('classly.rooms.view', 'grid');
    } catch (e) {
        /* storage unavailable — the page just opens on its last view */
    }
};

const tileSub = (room) => {
    const st = states.value.get(room.id);
    if (st.key === 'live') return st.block.subject;
    if (st.key === 'next') return `Next ${fmt(st.block.sm)}`;
    return '';
};
const tileLabel = (room) => {
    const st = states.value.get(room.id);
    const detail = st.key === 'live' ? `${st.block.subject}, ${st.block.section}, until ${fmt(st.block.em)}` : st.key === 'next' ? `next class ${fmt(st.block.sm)}` : st.hadClasses ? 'done for the day' : 'no class this day';
    return `${room.code}: ${stateLabel[st.key]}, ${detail}`;
};

watch(
    () => [day.value, minute.value, isLiveNow.value, data.rooms],
    () => emit('snapshot', { day: day.value, minute: minute.value, live: isLiveNow.value, rooms: data.rooms, loading: loading.value }),
    { immediate: true },
);
watch(loading, () => emit('snapshot', { day: day.value, minute: minute.value, live: isLiveNow.value, rooms: data.rooms, loading: loading.value }));

const momentLabel = computed(() => `${DAY_LONG[day.value] ?? ''} · ${fmt(minute.value)}`);
const stateLabel = { live: 'In use', next: 'Up next', free: 'Free' };
</script>

<template>
    <section class="ro neu-card flex flex-col rounded-2xl p-5 sm:p-6" :class="isDark ? 'ro-dark' : ''" :style="themeVars" aria-labelledby="ro-title">
        <!-- Header -->
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 id="ro-title" class="flex items-center gap-2 text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                    Room Occupancy
                    <InfoPopover
                        title="Room Occupancy"
                        :paragraphs="['Shows which rooms have a class at the day and time you pick. It opens on today, right now.']"
                        :bullets="[
                            'Red (lit) means a class is in session at that moment. Blue means a class starts later. Pale blue means free.',
                            'Hover or tap a room to see its subject, section, faculty and the rest of its day.',
                            'Drag the time slider to check any hour, or use Now to jump back to the current time.',
                            'Click In use, Up next or Free to show only those rooms.',
                            'Only the active term\'s scheduled classes count, and you only see rooms in your scope.',
                        ]"
                    />
                </h2>
                <p class="ro-muted mt-0.5 text-sm" aria-live="polite">{{ momentLabel }}<span v-if="isLiveNow" class="ro-live-tag">Live</span></p>
            </div>

            <Link :href="route('scheduling.rooms')" class="ro-link" @click="rememberGridView">
                Open weekly Room Grid
                <i class="pi pi-arrow-right text-xs" aria-hidden="true"></i>
            </Link>
        </div>

        <!-- Controls -->
        <div v-if="!loading && !loadError && data.rooms.length" class="mt-3 flex flex-col gap-2.5">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Day">
                    <button
                        v-for="d in data.days"
                        :key="d"
                        type="button"
                        class="ro-day"
                        :class="{ 'ro-day-on': day === d, 'ro-day-today': d === today }"
                        :aria-pressed="day === d"
                        :title="d === today ? `${DAY_LONG[d]} (today)` : DAY_LONG[d]"
                        @click="pickDay(d)"
                    >
                        {{ d }}
                    </button>
                    <button type="button" class="ro-now" :disabled="!canGoLive || isLiveNow" :title="canGoLive ? 'Jump to today, right now' : 'Today is not a class day'" @click="goNow">
                        <i class="pi pi-clock text-xs" aria-hidden="true"></i> Now
                    </button>
                </div>

                <div class="flex min-w-[12rem] flex-1 items-center gap-2.5">
                    <span class="ro-time" aria-hidden="true">{{ fmt(windowStart) }}</span>
                    <input
                        v-model="sliderValue"
                        type="range"
                        class="ro-range"
                        :min="windowStart"
                        :max="windowEnd"
                        step="15"
                        aria-label="Time of day"
                        :aria-valuetext="fmt(minute)"
                    />
                    <span class="ro-time" aria-hidden="true">{{ fmt(windowEnd) }}</span>
                </div>

                <select v-if="buildings.length > 1" v-model="building" class="ro-select" aria-label="Building">
                    <option :value="null">All buildings</option>
                    <option v-for="b in buildings" :key="b" :value="b">{{ b }}</option>
                </select>
            </div>
            <p v-if="afterHours" class="ro-muted text-xs">Outside class hours ({{ fmt(windowStart) }}–{{ fmt(windowEnd) }}), so every room reads as free or done.</p>

            <!-- Counts double as filters -->
            <div class="flex flex-wrap gap-2" role="group" aria-label="Filter by status">
                <button
                    v-for="key in ['live', 'next', 'free']"
                    :key="key"
                    type="button"
                    class="ro-chip"
                    :class="[`ro-chip-${key}`, { 'ro-chip-on': stateFilter === key }]"
                    :aria-pressed="stateFilter === key"
                    @click="toggleFilter(key)"
                >
                    <span class="ro-dot" aria-hidden="true"></span>
                    {{ stateLabel[key] }}
                    <strong class="tabular-nums">{{ counts[key] }}</strong>
                </button>
            </div>
        </div>

        <!-- States -->
        <p v-if="loading" class="ro-muted mt-6 flex items-center gap-2 text-sm" role="status">
            <i class="pi pi-spin pi-spinner" aria-hidden="true"></i> Loading rooms…
        </p>
        <div v-else-if="loadError" class="mt-6 flex flex-wrap items-center gap-3 text-sm" role="alert">
            <span class="ro-muted">{{ loadError }}</span>
            <button type="button" class="ro-now" @click="load"><i class="pi pi-refresh text-xs" aria-hidden="true"></i> Try again</button>
        </div>
        <p v-else-if="!data.rooms.length" class="ro-muted mt-6 text-sm">No active rooms in your scope yet. Rooms added in Room Master will show up here.</p>

        <template v-else>
            <!-- Rooms: one small tile per room, floors stacked like the building -->
            <div class="ro-map mt-3">
                <p v-if="!visibleRooms.length" class="ro-muted py-4 text-center text-sm">No rooms match this filter at {{ fmt(minute) }}.</p>

                <div v-for="b in grouped" :key="b.name" class="ro-bld">
                    <h3 v-if="buildings.length > 1" class="ro-building">{{ b.name }}</h3>

                    <div v-for="f in b.floors" :key="f.label" class="ro-floor">
                        <div class="ro-floor-label" :title="f.label">
                            <span class="ro-floor-badge" aria-hidden="true">{{ floorBadge(f) }}</span>
                            <span class="sr-only">{{ f.label }}</span>
                            <span v-if="floorBadge(f) === '•'" class="ro-floor-text" aria-hidden="true">{{ f.label }}</span>
                        </div>

                        <ul class="ro-tiles">
                            <li v-for="room in f.rooms" :key="room.id">
                                <button
                                    type="button"
                                    class="ro-tile"
                                    :class="[`ro-tile-${states.get(room.id).key}`, { 'ro-tile-focus': focusId === room.id }]"
                                    :title="tileLabel(room)"
                                    :aria-label="tileLabel(room)"
                                    @mouseenter="focusId = room.id"
                                    @focus="focusId = room.id"
                                    @click="focusId = room.id"
                                >
                                    <span class="ro-code">{{ shortCode(room) }}</span>
                                    <span class="ro-sub">{{ tileSub(room) }}</span>
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Detail strip: fixed height so the grid never jumps while you hover -->
            <div class="ro-detail" aria-live="polite">
                <template v-if="focused">
                    <div class="ro-detail-head">
                        <strong class="ro-detail-title">{{ focused.room.code }}</strong>
                        <span class="ro-detail-state" :class="`ro-detail-state-${focused.state.key}`">{{ stateLabel[focused.state.key] }}</span>
                        <span class="ro-muted">{{ typeShort(focused.room.type) }}<template v-if="focused.room.capacity"> · {{ focused.room.capacity }} seats</template><template v-if="focused.room.floor"> · {{ focused.room.floor }}</template></span>
                    </div>
                    <p v-if="!focused.blocks.length" class="ro-muted text-sm">No class on {{ DAY_LONG[day] }}.</p>
                    <ul v-else class="ro-blocks">
                        <li v-for="blk in focused.blocks" :key="blk.id" :class="{ 'ro-block-now': blk.now }">
                            <span class="ro-block-time">{{ rangeText(blk.sm, blk.em) }}</span>
                            <strong>{{ blk.subject }}</strong>
                            <span>{{ blk.section }}</span>
                            <span class="ro-muted">{{ blk.faculty }}</span>
                        </li>
                    </ul>
                </template>
                <p v-else class="ro-muted text-sm">Hover or tap a room to see its subject, section and faculty.</p>
            </div>
        </template>
    </section>
</template>

<style scoped>
.ro {
    --ro-text: #1e293b;
    --ro-muted: #64748b;
    --ro-line: #e2e8f0;
    --ro-soft: #f8fafc;
    --ro-surface: rgba(255, 255, 255, 0.92);
    /* school blue — the sky and trim around the red building */
    --ro-blue: #2563eb;
    --ro-blue-text: #1d4ed8;
    --ro-blue-soft: #eff6ff;
    --ro-blue-line: #bfdbfe;
    --ro-navy: #1e3a8a;
    --ro-navy-text: #ffffff;
}
.ro-dark {
    --ro-text: #e2e8f0;
    --ro-muted: #94a3b8;
    --ro-line: rgba(255, 255, 255, 0.12);
    --ro-soft: rgba(255, 255, 255, 0.05);
    --ro-surface: rgba(11, 18, 32, 0.86);
    --ro-blue: #60a5fa;
    --ro-blue-text: #93c5fd;
    --ro-blue-soft: rgba(59, 130, 246, 0.1);
    --ro-blue-line: rgba(96, 165, 250, 0.32);
    --ro-navy: rgba(59, 130, 246, 0.28);
    --ro-navy-text: #dbeafe;
}

/* The Dashboard's frosted glass lets the campus photo bleed through small text;
   this card is dense, so it gets a more solid surface (same glass, higher opacity). */

.ro-muted { color: var(--ro-muted); }

.ro-live-tag {
    margin-left: 0.5rem;
    padding: 0.05rem 0.45rem;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 700;
    color: var(--ro-on-lit);
    background: var(--ro-lit);
}

.ro-link {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--ro-accent);
    text-decoration: none;
}
.ro-link:hover { text-decoration: underline; }

/* ---------- controls ---------- */
.ro-day,
.ro-now,
.ro-chip {
    border: 1px solid var(--ro-line);
    background: var(--ro-soft);
    color: var(--ro-text);
    font-size: 0.78rem;
    font-weight: 600;
    border-radius: 999px;
    padding: 0.22rem 0.7rem;
    cursor: pointer;
    transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}
.ro-day { min-width: 2.7rem; position: relative; }
.ro-day:hover:not(.ro-day-on),
.ro-now:hover:not(:disabled),
.ro-chip:hover:not(.ro-chip-on) { border-color: var(--ro-lit); }
.ro-day-on { background: var(--ro-lit); border-color: var(--ro-lit); color: var(--ro-on-lit); }
/* today marker: a small underline, so it isn't colour-only and doesn't fight the selected state */
.ro-day-today::after {
    content: '';
    position: absolute;
    left: 50%;
    bottom: 2px;
    width: 0.85rem;
    height: 2px;
    border-radius: 2px;
    background: currentColor;
    transform: translateX(-50%);
    opacity: 0.7;
}
.ro-now { display: inline-flex; align-items: center; gap: 0.35rem; }
.ro-now:disabled { opacity: 0.45; cursor: default; }

.ro-select {
    border: 1px solid var(--ro-line);
    background: var(--ro-soft);
    color: var(--ro-text);
    border-radius: 0.6rem;
    padding: 0.25rem 0.6rem;
    font-size: 0.78rem;
}

.ro-time { font-size: 0.7rem; font-variant-numeric: tabular-nums; color: var(--ro-muted); white-space: nowrap; }
.ro-range { flex: 1; min-width: 0; accent-color: var(--ro-lit); cursor: pointer; }

.ro-chip { display: inline-flex; align-items: center; gap: 0.4rem; }
.ro-chip strong { font-weight: 700; }
.ro-chip-on { border-color: var(--ro-lit); box-shadow: 0 0 0 1px var(--ro-lit) inset; }
.ro-dot { width: 0.55rem; height: 0.55rem; border-radius: 999px; flex: none; background: var(--ro-muted); opacity: 0.5; }
.ro-chip-live .ro-dot { background: var(--ro-lit); opacity: 1; box-shadow: 0 0 6px var(--ro-glow); }
.ro-chip-next .ro-dot { background: var(--ro-blue-soft); opacity: 1; border: 2px solid var(--ro-blue); }
.ro-chip-free .ro-dot { background: var(--ro-blue-line); opacity: 1; }
.ro-chip-next.ro-chip-on { border-color: var(--ro-blue); box-shadow: 0 0 0 1px var(--ro-blue) inset; }

/* ---------- room map ---------- */
.ro-bld + .ro-bld { margin-top: 0.75rem; }
.ro-building { margin: 0 0 0.35rem; font-size: 0.78rem; font-weight: 700; color: var(--ro-muted); }

.ro-floor {
    display: grid;
    grid-template-columns: 2.2rem 1fr;
    gap: 0.6rem;
    align-items: start;
    padding: 0.45rem 0;
    border-top: 1px solid var(--ro-blue-line);
}
.ro-floor:first-of-type { border-top: 0; padding-top: 0; }

.ro-floor-label { display: flex; flex-direction: column; align-items: flex-start; gap: 0.2rem; padding-top: 0.15rem; }
.ro-floor-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.7rem;
    height: 1.7rem;
    border-radius: 0.5rem;
    font-weight: 700;
    font-size: 0.8rem;
    background: var(--ro-navy);
    border: 1px solid transparent;
    color: var(--ro-navy-text);
}
.ro-floor-text { font-size: 0.62rem; color: var(--ro-muted); line-height: 1.1; }

.ro-tiles {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(5.6rem, 1fr));
    gap: 0.4rem;
}

.ro-tile {
    display: flex;
    width: 100%;
    flex-direction: column;
    justify-content: center;
    gap: 0.05rem;
    height: 2.9rem;
    padding: 0.2rem 0.5rem;
    text-align: left;
    border-radius: 0.6rem;
    border: 1px solid var(--ro-blue-line);
    background: var(--ro-blue-soft);
    color: var(--ro-text);
    cursor: pointer;
    transition: background 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease;
}
.ro-code { font-weight: 700; font-size: 0.74rem; line-height: 1.15; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ro-sub { font-size: 0.65rem; line-height: 1.1; min-height: 0.72rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-variant-numeric: tabular-nums; }

/* In use — lit in the school colour */
.ro-tile-live {
    background: var(--ro-lit);
    border-color: var(--ro-lit);
    color: var(--ro-on-lit);
    box-shadow: 0 0 14px -3px var(--ro-glow);
}
.ro-tile-live .ro-sub { font-weight: 700; }

/* Up next — white/outlined, with the time of its next class */
.ro-tile-next {
    background: var(--ro-blue-soft);
    border-color: var(--ro-blue);
    box-shadow: 0 0 0 1px var(--ro-blue) inset;
}
.ro-tile-next .ro-sub { color: var(--ro-blue-text); font-weight: 700; }

/* Free — recedes */
.ro-tile-free { color: var(--ro-muted); }
.ro-tile-free .ro-code { font-weight: 600; }

.ro-tile:hover:not(.ro-tile-live) { border-color: var(--ro-blue); }
.ro-tile:focus-visible { outline: 2px solid var(--ro-text); outline-offset: 2px; }
.ro-tile-focus { box-shadow: 0 0 0 2px var(--ro-surface), 0 0 0 4px var(--ro-blue); }

/* ---------- detail strip ---------- */
.ro-detail {
    margin-top: 0.75rem;
    min-height: 4.4rem;
    padding: 0.6rem 0.8rem;
    border-radius: 0.75rem;
    border: 1px solid var(--ro-blue-line);
    border-left: 4px solid var(--ro-blue);
    background: var(--ro-blue-soft);
}
.ro-detail-head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.2rem 0.7rem; font-size: 0.85rem; }
.ro-detail-title { color: var(--ro-text); font-size: 0.95rem; }
.ro-detail-state { font-size: 0.68rem; font-weight: 700; padding: 0.05rem 0.5rem; border-radius: 999px; border: 1px solid var(--ro-line); color: var(--ro-text); }
.ro-detail-state-live { background: var(--ro-lit); border-color: var(--ro-lit); color: var(--ro-on-lit); }
.ro-detail-state-next { border-color: var(--ro-blue); color: var(--ro-blue-text); }

.ro-blocks { list-style: none; margin: 0.35rem 0 0; padding: 0; display: flex; flex-direction: column; gap: 0.15rem; font-size: 0.8rem; color: var(--ro-text); }
.ro-blocks li { display: flex; flex-wrap: wrap; gap: 0.15rem 0.6rem; align-items: baseline; padding-left: 0.5rem; border-left: 3px solid transparent; }
.ro-block-time { font-variant-numeric: tabular-nums; font-weight: 600; min-width: 7.5rem; }
.ro-block-now { border-left-color: var(--ro-lit); }

@media (max-width: 520px) {
    .ro-floor { grid-template-columns: 1fr; gap: 0.35rem; }
    .ro-floor-label { flex-direction: row; align-items: center; gap: 0.4rem; padding-top: 0; }
    .ro-tiles { grid-template-columns: repeat(auto-fill, minmax(5.4rem, 1fr)); }
}

@media (prefers-reduced-motion: reduce) {
    .ro-tile, .ro-day, .ro-now, .ro-chip { transition: none; }
}
</style>

<style>
/* Unscoped on purpose: it has to out-rank the Dashboard's own frosted-glass
   rule (.dash-scope .neu-card). The Dashboard's glass lets the campus photo
   bleed through small text; this card is dense, so it gets a more solid surface. */
.dash-scope .ro.neu-card {
    background: var(--ro-surface);
}
.dark .dash-scope .ro.neu-card {
    background: var(--ro-surface);
}
</style>