<script setup>
/**
 * ROOM GRID — Rooms page ("Grid" view).
 *
 * Panelist request: see every room, from the 1st floor up, in one glance
 * instead of opening each room's schedule. Laid out like the building
 * itself:
 *
 *            Mon        Tue        Wed   ...      (days across)
 *   4th   [ rooms ]   [ rooms ]   [ rooms ]
 *   3rd   [ rooms ]   [ rooms ]   [ rooms ]
 *   2nd   [ rooms ]   [ rooms ]   [ rooms ]
 *   1st   [ rooms ]   [ rooms ]   [ rooms ]       (top floor on top)
 *
 * Each cell lists that floor's rooms for that day: the room code, a
 * one-row bar (one segment per 30 minutes; occupied segments hatched so
 * state never relies on colour alone) and the booked start–end times.
 * Pick a Time range to see which rooms are free for exactly that time.
 * Clicking a room opens the page's existing Room Schedule Details.
 *
 * Read-only. Data comes from RoomController::grid() — the same Active-
 * Academic-Term bookings the Rooms table's Utilization column uses.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import Select from 'primevue/select';
import InputText from 'primevue/inputtext';
import ProgressSpinner from 'primevue/progressspinner';

const props = defineProps({ isDark: { type: Boolean, default: false } });
const emit = defineEmits(['open-room']);

/* ------------------------------------------------------------------ */
/* Time helpers                                                        */
/* ------------------------------------------------------------------ */

const STEP = 30;
const toMin = (hhmm) => {
    if (!hhmm) return null;
    const [h, m] = String(hhmm).slice(0, 5).split(':').map(Number);
    return h * 60 + m;
};
const fromMin = (min) => `${String(Math.floor(min / 60)).padStart(2, '0')}:${String(min % 60).padStart(2, '0')}`;
const meridiem = (min) => (Math.floor(min / 60) >= 12 ? 'PM' : 'AM');
const clock = (min) => `${Math.floor(min / 60) % 12 === 0 ? 12 : Math.floor(min / 60) % 12}:${String(min % 60).padStart(2, '0')}`;
const fmt = (min) => `${clock(min)} ${meridiem(min)}`;
// "8:00–9:30 AM" (one AM/PM when both ends share it, else both)
const rangeText = (s, e) => (meridiem(s) === meridiem(e) ? `${clock(s)}–${clock(e)} ${meridiem(e)}` : `${fmt(s)}–${fmt(e)}`);

/* ------------------------------------------------------------------ */
/* Data                                                                */
/* ------------------------------------------------------------------ */

const loading = ref(false);
const loadError = ref('');
const data = reactive({ days: [], window: { start: '07:00', end: '19:00' }, rooms: [] });

const filters = reactive({
    days: [], // empty = every day
    building: null,
    type: null,
    search: '',
    from: null,
    to: null,
    onlyAvailable: false,
    showTimes: true,
});

const load = async () => {
    loading.value = true;
    loadError.value = '';
    try {
        const { data: json } = await window.axios.get(route('scheduling.rooms.grid'));
        data.days = json.days ?? [];
        data.window = json.window ?? data.window;
        data.rooms = json.rooms ?? [];
    } catch (e) {
        loadError.value = 'Could not load the room grid. Please try again.';
    } finally {
        loading.value = false;
    }
};
onMounted(load);
defineExpose({ reload: load });

/* ------------------------------------------------------------------ */
/* Derived                                                             */
/* ------------------------------------------------------------------ */

const windowStart = computed(() => toMin(data.window.start));
const windowEnd = computed(() => toMin(data.window.end));
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

const shownDays = computed(() => (filters.days.length ? data.days.filter((d) => filters.days.includes(d)) : data.days));
const toggleDay = (d) => {
    if (filters.days.includes(d)) filters.days = filters.days.filter((x) => x !== d);
    else filters.days = [...filters.days, d];
};

const buildingOptions = computed(() => [...new Set(data.rooms.map((r) => r.building).filter(Boolean))].sort().map((b) => ({ label: b, value: b })));
const typeOptions = computed(() => [...new Set(data.rooms.map((r) => r.type).filter(Boolean))].sort().map((t) => ({ label: t, value: t })));

// key `${roomId}|${day}` -> block|null per 30-minute slot
const occupancy = computed(() => {
    const map = new Map();
    for (const room of data.rooms) {
        for (const day of data.days) {
            const arr = new Array(slots.value.length).fill(null);
            for (const b of room.blocks?.[day] ?? []) {
                const bs = toMin(b.s);
                const be = toMin(b.e);
                slots.value.forEach((t, i) => {
                    if (bs < t + STEP && be > t && !arr[i]) arr[i] = b;
                });
            }
            map.set(`${room.id}|${day}`, arr);
        }
    }
    return map;
});
const stripFor = (room, day) => occupancy.value.get(`${room.id}|${day}`) ?? [];

const range = computed(() => {
    const s = toMin(filters.from);
    const e = toMin(filters.to);
    return s != null && e != null && e > s ? { s, e } : null;
});
const inRange = (i) => !!range.value && slots.value[i] >= range.value.s && slots.value[i] < range.value.e;
// 'free' | 'busy' | null (no time range chosen)
const statusFor = (room, day) => {
    if (!range.value) return null;
    const strip = stripFor(room, day);
    return slots.value.some((t, i) => t >= range.value.s && t < range.value.e && strip[i]) ? 'busy' : 'free';
};

const bookedText = (room, day) => (room.blocks?.[day] ?? []).map((b) => rangeText(toMin(b.s), toMin(b.e))).join(' · ');
const tooltipFor = (room, day) => {
    const blocks = room.blocks?.[day] ?? [];
    const head = `${room.code}${room.name && room.name !== room.code ? ` — ${room.name}` : ''} · ${day}`;
    if (!blocks.length) return `${head}\nNo classes — free all day`;
    return `${head}\n${blocks.map((b) => `${rangeText(toMin(b.s), toMin(b.e))}  ${b.subject} · ${b.section} · ${b.faculty}`).join('\n')}`;
};
const segTitle = (room, day, i) => {
    const b = stripFor(room, day)[i];
    return `${rangeText(slots.value[i], slots.value[i] + STEP)} — ${b ? `Occupied: ${b.subject} · ${b.section}` : 'Available'}`;
};

const filteredRooms = computed(() => {
    const q = filters.search.trim().toLowerCase();
    return data.rooms.filter(
        (r) =>
            (!filters.building || r.building === filters.building) &&
            (!filters.type || r.type === filters.type) &&
            (!q || r.code.toLowerCase().includes(q) || (r.name ?? '').toLowerCase().includes(q))
    );
});

// building -> floors (top floor first) -> rooms
const buildings = computed(() => {
    const byBuilding = new Map();
    for (const room of filteredRooms.value) {
        const bKey = room.building || 'No building';
        if (!byBuilding.has(bKey)) byBuilding.set(bKey, new Map());
        const floors = byBuilding.get(bKey);
        const fKey = room.floor || 'No floor';
        if (!floors.has(fKey)) floors.set(fKey, { label: fKey, order: room.floor_order ?? 999, rooms: [] });
        floors.get(fKey).rooms.push(room);
    }
    return [...byBuilding.entries()]
        .sort((a, b) => a[0].localeCompare(b[0]))
        .map(([name, floors]) => ({
            name,
            floors: [...floors.values()].sort((a, b) => b.order - a.order || a.label.localeCompare(b.label)),
        }));
});

// Short badge for the floor label: 4 for "4th Floor", G for ground, B for basement.
const floorBadge = (f) => (f.order > 0 && f.order < 100 ? String(f.order) : f.order === 0 ? 'G' : f.order < 0 ? 'B' : '•');

const cellRooms = (floor, day) => floor.rooms.filter((r) => !filters.onlyAvailable || !range.value || statusFor(r, day) === 'free');
const freeCount = (floor, day) => floor.rooms.filter((r) => statusFor(r, day) === 'free').length;
const dayFree = (day) => filteredRooms.value.filter((r) => statusFor(r, day) === 'free').length;

const openRoom = (room) =>
    emit('open-room', { id: room.id, room_code: room.code, room_name: room.name, building: room.building, floor: room.floor, room_type: room.type, capacity: room.capacity });

const gridStyle = computed(() => ({ '--rg-days': shownDays.value.length }));
</script>

<template>
    <div class="rg-root" :class="{ 'is-dark': isDark }">
        <!-- Filter bar: one grouped panel, two tidy rows -->
        <section class="rg-bar" aria-label="Room grid filters">
            <div class="rg-bar-row">
                <div class="rg-days" role="group" aria-label="Days">
                    <button type="button" class="rg-chip" :class="{ on: !filters.days.length }" :aria-pressed="!filters.days.length" @click="filters.days = []">All days</button>
                    <button v-for="d in data.days" :key="d" type="button" class="rg-chip" :class="{ on: filters.days.includes(d) }" :aria-pressed="filters.days.includes(d)" @click="toggleDay(d)">{{ d }}</button>
                </div>

                <div class="rg-time" role="group" aria-label="Time range">
                    <i class="pi pi-clock rg-time-icon" aria-hidden="true"></i>
                    <Select v-model="filters.from" :options="timeChoices.slice(0, -1)" optionLabel="label" optionValue="value" placeholder="From" showClear size="small" class="rg-sel-time" aria-label="From time" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    <i class="pi pi-arrow-right rg-time-arrow" aria-hidden="true"></i>
                    <Select v-model="filters.to" :options="timeChoices.filter((o) => !filters.from || o.value > filters.from)" optionLabel="label" optionValue="value" placeholder="To" showClear size="small" class="rg-sel-time" aria-label="To time" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                </div>
            </div>

            <div class="rg-bar-row">
                <Select v-if="buildingOptions.length > 1" v-model="filters.building" :options="buildingOptions" optionLabel="label" optionValue="value" placeholder="All buildings" showClear size="small" class="rg-sel" aria-label="Building" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                <Select v-model="filters.type" :options="typeOptions" optionLabel="label" optionValue="value" placeholder="All room types" showClear size="small" class="rg-sel" aria-label="Room type" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                <span class="rg-search">
                    <i class="pi pi-search" aria-hidden="true"></i>
                    <InputText v-model="filters.search" placeholder="Search room…" size="small" aria-label="Search rooms" />
                </span>

                <div class="rg-toggles">
                    <button type="button" class="rg-toggle" :class="{ on: filters.onlyAvailable && range }" :aria-pressed="!!(filters.onlyAvailable && range)" :disabled="!range" :title="range ? '' : 'Choose a From and To time first'" @click="filters.onlyAvailable = !filters.onlyAvailable">
                        <i class="pi" :class="filters.onlyAvailable && range ? 'pi-check-circle' : 'pi-circle'" aria-hidden="true"></i> Only available
                    </button>
                    <button type="button" class="rg-toggle" :class="{ on: filters.showTimes }" :aria-pressed="filters.showTimes" @click="filters.showTimes = !filters.showTimes">
                        <i class="pi" :class="filters.showTimes ? 'pi-check-circle' : 'pi-circle'" aria-hidden="true"></i> Booked times
                    </button>
                </div>
            </div>
        </section>

        <div class="rg-legend" aria-label="Legend">
            <span><i class="rg-sw free"></i> Available</span>
            <span><i class="rg-sw busy"></i> Occupied</span>
            <span v-if="range"><i class="rg-sw inr"></i> {{ rangeText(range.s, range.e) }}</span>
            <span class="rg-muted rg-legend-note">{{ fmt(windowStart) }}–{{ fmt(windowEnd) }} · one segment = 30 min · click a room for its full schedule</span>
        </div>

        <!-- States -->
        <div v-if="loading && !data.rooms.length" class="rg-center" role="status" aria-label="Loading room grid"><ProgressSpinner style="width: 40px; height: 40px" strokeWidth="4" /></div>
        <div v-else-if="loadError" class="rg-center rg-bad">
            <p><i class="pi pi-exclamation-triangle"></i> {{ loadError }}</p>
            <button type="button" class="rg-chip" @click="load">Retry</button>
        </div>
        <p v-else-if="!buildings.length" class="rg-center rg-muted">No rooms match the filters.</p>

        <!-- Grid -->
        <div v-else class="rg-scroll" tabindex="0" aria-label="Room grid by floor and day. Scroll for more.">
            <template v-for="b in buildings" :key="b.name">
                <div class="rg-grid" :style="gridStyle">
                    <!-- Header row -->
                    <div class="rg-corner" :title="`${b.name} — floors down, days across`">
                        <i class="pi pi-building" aria-hidden="true"></i>
                        <span class="rg-bname">{{ b.name }}</span>
                    </div>
                    <div v-for="d in shownDays" :key="d" class="rg-dayhead">
                        <span class="rg-dayname">{{ d }}</span>
                        <span v-if="range" class="rg-dayfree">{{ dayFree(d) }} room{{ dayFree(d) === 1 ? '' : 's' }} free</span>
                    </div>

                    <!-- Floors, top floor first -->
                    <template v-for="(f, fi) in b.floors" :key="f.label">
                        <div class="rg-floor" :class="{ 'rg-alt': fi % 2 === 1 }">
                            <div class="rg-floor-inner">
                                <span class="rg-floor-badge" aria-hidden="true">{{ floorBadge(f) }}</span>
                                <strong>{{ f.label }}</strong>
                                <span class="rg-muted">{{ f.rooms.length }} room{{ f.rooms.length === 1 ? '' : 's' }}</span>
                            </div>
                        </div>
                        <div v-for="d in shownDays" :key="`${f.label}-${d}`" class="rg-cell" :class="{ 'rg-alt': fi % 2 === 1 }">
                            <p class="rg-cellhead">
                                <span class="rg-cellfloor">{{ f.label }}</span>
                                <span v-if="range" class="rg-cellfree">{{ freeCount(f, d) }} of {{ f.rooms.length }} free</span>
                            </p>
                            <p v-if="!cellRooms(f, d).length" class="rg-muted rg-none">No rooms to show</p>
                            <button
                                v-for="r in cellRooms(f, d)"
                                :key="r.id"
                                type="button"
                                class="rg-room"
                                :class="statusFor(r, d)"
                                :title="tooltipFor(r, d)"
                                :aria-label="`${r.code}, ${d}. ${(r.blocks?.[d] ?? []).length ? 'Booked ' + bookedText(r, d) : 'Free all day'}${statusFor(r, d) ? (statusFor(r, d) === 'free' ? '. Available for the chosen time' : '. Occupied during the chosen time') : ''}. Open schedule`"
                                @click="openRoom(r)"
                            >
                                <span class="rg-line">
                                    <span class="rg-code">{{ r.code }}</span>
                                    <i v-if="statusFor(r, d) === 'free'" class="pi pi-check rg-mark ok" aria-hidden="true"></i>
                                    <i v-else-if="statusFor(r, d) === 'busy'" class="pi pi-times rg-mark no" aria-hidden="true"></i>
                                </span>
                                <span class="rg-strip" aria-hidden="true">
                                    <span v-for="(blk, i) in stripFor(r, d)" :key="i" class="rg-seg" :class="[blk ? 'busy' : 'free', { inr: inRange(i) }]" :title="segTitle(r, d, i)"></span>
                                </span>
                                <span v-if="filters.showTimes" class="rg-times" :class="{ free: !(r.blocks?.[d] ?? []).length }">{{ bookedText(r, d) || 'Free all day' }}</span>
                            </button>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>
</template>

<style>
/* Unscoped on purpose; everything is prefixed `rg-`. */
.rg-root {
    --rg-bg: #ffffff; --rg-soft: #f8fafc; --rg-border: #e2e8f0; --rg-text: #1e293b; --rg-muted: #64748b;
    --rg-free: #d1fae5; --rg-free-fg: #047857; --rg-free-bd: #6ee7b7;
    --rg-busy: #fecdd3; --rg-busy-fg: #be123c; --rg-busy-bd: #fda4af;
    --rg-accent: #4f46e5;
    --rg-floor-line: #94a3b8; --rg-alt: #f1f5f9;
    color: var(--rg-text); font-size: 0.8rem;
}
.rg-root.is-dark {
    --rg-bg: #1e293b; --rg-soft: #162033; --rg-border: #334155; --rg-text: #e2e8f0; --rg-muted: #94a3b8;
    --rg-free: rgba(16, 185, 129, 0.25); --rg-free-fg: #6ee7b7; --rg-free-bd: rgba(16, 185, 129, 0.5);
    --rg-busy: rgba(244, 63, 94, 0.3); --rg-busy-fg: #fda4af; --rg-busy-bd: rgba(244, 63, 94, 0.55);
    --rg-accent: #818cf8;
    --rg-floor-line: #64748b; --rg-alt: rgba(255, 255, 255, 0.04);
}
.rg-muted { color: var(--rg-muted); }
.rg-bad { color: var(--rg-busy-fg); }
.rg-center { text-align: center; padding: 2.5rem 1rem; }

/* ---- filter bar ---- */
.rg-bar { display: flex; flex-direction: column; gap: 0.65rem; padding: 0.75rem 0.9rem; background: var(--rg-soft); border: 1px solid var(--rg-border); border-radius: 0.9rem; }
.rg-bar-row { display: flex; flex-wrap: wrap; align-items: center; gap: 0.6rem 0.8rem; }
.rg-days { display: inline-flex; flex-wrap: wrap; gap: 0.25rem; padding: 0.2rem; background: var(--rg-bg); border: 1px solid var(--rg-border); border-radius: 999px; }
.rg-chip { font-size: 0.76rem; font-weight: 600; padding: 0.25rem 0.75rem; border-radius: 999px; border: 0; background: transparent; color: var(--rg-text); cursor: pointer; }
.rg-chip:hover { background: var(--rg-soft); }
.rg-chip.on { background: var(--rg-accent); color: #fff; }
.rg-chip:focus-visible, .rg-toggle:focus-visible, .rg-room:focus-visible { outline: 2px solid var(--rg-accent); outline-offset: 1px; }

.rg-time { display: inline-flex; align-items: center; gap: 0.4rem; margin-left: auto; padding: 0.2rem 0.5rem; background: var(--rg-bg); border: 1px solid var(--rg-border); border-radius: 0.7rem; }
.rg-time-icon, .rg-time-arrow { color: var(--rg-muted); font-size: 0.75rem; }
.rg-sel-time { width: 8.5rem; }
.rg-sel { width: 11rem; }
.rg-search { position: relative; display: inline-flex; align-items: center; width: 14rem; }
.rg-search .pi { position: absolute; left: 0.65rem; color: var(--rg-muted); font-size: 0.75rem; pointer-events: none; }
.rg-search .p-inputtext { width: 100%; padding-left: 1.9rem; }

.rg-toggles { display: inline-flex; flex-wrap: wrap; gap: 0.4rem; margin-left: auto; }
.rg-toggle { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.76rem; font-weight: 600; padding: 0.35rem 0.8rem; border-radius: 999px; border: 1px solid var(--rg-border); background: var(--rg-bg); color: var(--rg-muted); cursor: pointer; }
.rg-toggle.on { border-color: var(--rg-accent); color: var(--rg-accent); background: color-mix(in srgb, var(--rg-accent) 10%, var(--rg-bg)); }
.rg-toggle:disabled { opacity: 0.55; cursor: not-allowed; }
.rg-toggle .pi { font-size: 0.8rem; }

.rg-legend { display: flex; flex-wrap: wrap; align-items: center; gap: 0.3rem 1rem; font-size: 0.72rem; margin: 0.55rem 0.2rem 0.6rem; }
.rg-legend > span { display: inline-flex; align-items: center; gap: 0.3rem; }
.rg-legend-note { margin-left: auto; }
.rg-sw { display: inline-block; width: 1rem; height: 0.7rem; border-radius: 2px; border: 1px solid transparent; }

/* ---- grid ---- */
.rg-scroll { overflow: auto; max-height: 72vh; border: 1px solid var(--rg-border); border-radius: 0.9rem; background: var(--rg-bg); }
.rg-grid { display: grid; grid-template-columns: 7.5rem repeat(var(--rg-days), minmax(10.5rem, 1fr)); min-width: calc(7.5rem + var(--rg-days) * 10.5rem); }
.rg-grid + .rg-grid { margin-top: 1.2rem; border-top: 3px solid var(--rg-border); }

.rg-corner, .rg-dayhead { position: sticky; top: 0; z-index: 3; display: flex; align-items: center; padding: 0.55rem 0.7rem; background: var(--rg-soft); border-bottom: 1px solid var(--rg-border); }
.rg-corner { left: 0; z-index: 4; gap: 0.4rem; border-right: 1px solid var(--rg-border); font-weight: 700; min-width: 0; }
.rg-corner .pi { color: var(--rg-accent); flex: 0 0 auto; }
.rg-bname { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.78rem; }
.rg-dayhead { flex-direction: column; justify-content: center; gap: 0.05rem; border-left: 1px solid var(--rg-border); }
.rg-dayname { font-weight: 700; font-size: 0.9rem; }
.rg-dayfree { font-size: 0.66rem; font-weight: 600; color: var(--rg-free-fg); }

.rg-floor { position: sticky; left: 0; z-index: 2; padding: 0.6rem 0.5rem; background: color-mix(in srgb, var(--rg-accent) 7%, var(--rg-soft)); border-right: 1px solid var(--rg-border); border-top: 3px solid var(--rg-floor-line); }
/* The label stays pinned just under the day header while you scroll through a tall floor. */
.rg-floor-inner { position: sticky; top: 4rem; display: flex; flex-direction: column; align-items: center; gap: 0.2rem; text-align: center; }
.rg-floor-badge { display: inline-flex; align-items: center; justify-content: center; width: 1.9rem; height: 1.9rem; border-radius: 0.55rem; background: var(--rg-accent); color: #fff; font-weight: 800; font-size: 0.95rem; }
.rg-cell { padding: 0.45rem 0.5rem 0.6rem; border-left: 1px solid var(--rg-border); border-top: 3px solid var(--rg-floor-line); display: flex; flex-direction: column; gap: 0.35rem; min-width: 0; }
/* Every other floor is shaded so the bands read as separate floors. */
.rg-cell.rg-alt { background: var(--rg-alt); }
.rg-floor.rg-alt { background: color-mix(in srgb, var(--rg-accent) 12%, var(--rg-soft)); }
.rg-cellhead { display: flex; align-items: center; justify-content: space-between; gap: 0.4rem; margin: 0 0 0.1rem; font-size: 0.64rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--rg-muted); }
.rg-cellfloor { color: var(--rg-accent); }
.rg-cellfree { color: var(--rg-free-fg); text-transform: none; letter-spacing: 0; }
.rg-none { font-size: 0.7rem; margin: 0; }

/* room card: code + status on top, full-width bar, then booked times */
.rg-room { display: flex; flex-direction: column; gap: 0.25rem; width: 100%; padding: 0.35rem 0.45rem 0.4rem; border: 1px solid var(--rg-border); border-radius: 0.55rem; background: var(--rg-bg); color: inherit; text-align: left; cursor: pointer; }
.rg-room:hover { border-color: var(--rg-accent); box-shadow: 0 0 0 1px var(--rg-accent) inset; }
.rg-room.free { border-color: var(--rg-free-bd); }
.rg-room.busy { border-color: var(--rg-busy-bd); }
.rg-line { display: flex; align-items: center; justify-content: space-between; gap: 0.3rem; min-width: 0; }
.rg-code { flex: 1 1 auto; min-width: 0; font-weight: 700; font-size: 0.74rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rg-mark { flex: 0 0 auto; font-size: 0.68rem; }
.rg-mark.ok { color: var(--rg-free-fg); }
.rg-mark.no { color: var(--rg-busy-fg); }
.rg-strip { display: flex; gap: 1px; height: 0.7rem; width: 100%; }
.rg-seg { flex: 1 1 0; border-radius: 1px; border: 1px solid transparent; }
.rg-seg.free, .rg-sw.free { background: var(--rg-free); border-color: var(--rg-free-bd); }
/* Occupied is hatched so the state is never colour-only. */
.rg-seg.busy, .rg-sw.busy { background: repeating-linear-gradient(135deg, var(--rg-busy), var(--rg-busy) 2px, transparent 2px, transparent 4px), var(--rg-busy); border-color: var(--rg-busy-bd); }
.rg-seg.inr, .rg-sw.inr { box-shadow: 0 -2px 0 0 var(--rg-accent), 0 2px 0 0 var(--rg-accent); }
.rg-times { font-size: 0.64rem; line-height: 0.9rem; color: var(--rg-muted); overflow-wrap: anywhere; }
.rg-times.free { color: var(--rg-free-fg); }

@media (max-width: 760px) {
    .rg-time, .rg-toggles { margin-left: 0; }
    .rg-search, .rg-sel { width: 100%; }
}
</style>