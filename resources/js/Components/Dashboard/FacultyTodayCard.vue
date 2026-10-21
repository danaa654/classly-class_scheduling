<script setup>
/**
 * FACULTY TODAY — Dashboard sidebar, beside Room Occupancy.
 *
 * "Which faculty have a class on the selected day?" It follows the Room
 * Occupancy card: same day, same moment (and the same Live clock), built from
 * the very same room blocks (scheduling.rooms.grid), so it is already scoped to
 * the user's role/College and the Active Academic Term and can never disagree
 * with the room tiles. Nothing extra is requested from the server.
 *
 *   Teaching now   a class is running at the chosen moment
 *   Up next        has a class later that day
 *   Done           all of that day's classes have ended
 *
 * Read-only.
 */
import { computed, ref } from 'vue';

const props = defineProps({
    isDark: { type: Boolean, default: false },
    accent: { type: String, default: '#E11D2E' },
    day: { type: String, default: null },
    minute: { type: Number, default: 0 },
    live: { type: Boolean, default: false },
    rooms: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
});

/* Colours — same derivation as Room Occupancy so the two cards match */
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
const onLit = computed(() => (luminance(lit.value) < 0.19 ? '#ffffff' : '#111827'));
const themeVars = computed(() => ({ '--ft-lit': lit.value, '--ft-on-lit': onLit.value, '--ft-glow': `${base.value}80` }));

/* Time helpers */
const toMin = (hhmm) => {
    if (!hhmm) return null;
    const [h, m] = String(hhmm).slice(0, 5).split(':').map(Number);
    return h * 60 + m;
};
const meridiem = (min) => (Math.floor(min / 60) >= 12 ? 'PM' : 'AM');
const clock = (min) => `${Math.floor(min / 60) % 12 === 0 ? 12 : Math.floor(min / 60) % 12}:${String(min % 60).padStart(2, '0')}`;
const fmt = (min) => `${clock(min)} ${meridiem(min)}`;
const rangeText = (s, e) => (meridiem(s) === meridiem(e) ? `${clock(s)}–${clock(e)} ${meridiem(e)}` : `${fmt(s)}–${fmt(e)}`);
const DAY_LONG = { Mon: 'Monday', Tue: 'Tuesday', Wed: 'Wednesday', Thu: 'Thursday', Fri: 'Friday', Sat: 'Saturday', Sun: 'Sunday' };

const shortCode = (code) => String(code ?? '').replace(/\s*\(.*\)\s*$/, '').trim() || code;
const natural = (a, b) => String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });

/* Faculty with a class on the selected day */
const UNASSIGNED = 'Unassigned';

const faculty = computed(() => {
    if (!props.day) return [];
    const t = props.minute;
    const byName = new Map();
    const seen = new Set();

    for (const room of props.rooms) {
        for (const b of room.blocks?.[props.day] ?? []) {
            const name = b.faculty || UNASSIGNED;
            if (name === UNASSIGNED) continue; // not a person — surfaced as a count below
            // A merged class can appear in more than one room row; count it once.
            const key = `${name}|${b.id}`;
            if (seen.has(key)) continue;
            seen.add(key);

            const sm = toMin(b.s);
            const em = toMin(b.e);
            if (!byName.has(name)) byName.set(name, { name, classes: [] });
            byName.get(name).classes.push({ id: b.id, sm, em, subject: b.subject, section: b.section, room: shortCode(room.code), now: sm <= t && t < em });
        }
    }

    return [...byName.values()]
        .map((f) => {
            f.classes.sort((a, b) => a.sm - b.sm);
            const current = f.classes.find((c) => c.now);
            const next = f.classes.find((c) => c.sm > t);
            const state = current ? 'live' : next ? 'next' : 'done';
            return { ...f, state, current, next, sortKey: current ? 0 : next ? 1 : 2 };
        })
        .sort((a, b) => a.sortKey - b.sortKey || (a.next?.sm ?? 0) - (b.next?.sm ?? 0) || natural(a.name, b.name));
});

const unassignedCount = computed(() => {
    if (!props.day) return 0;
    const ids = new Set();
    for (const room of props.rooms) for (const b of room.blocks?.[props.day] ?? []) if (!b.faculty || b.faculty === UNASSIGNED) ids.add(b.id);
    return ids.size;
});

const query = ref('');
const visible = computed(() => {
    const q = query.value.trim().toLowerCase();
    return q ? faculty.value.filter((f) => f.name.toLowerCase().includes(q)) : faculty.value;
});

const counts = computed(() => {
    const c = { live: 0, next: 0, done: 0 };
    for (const f of faculty.value) c[f.state]++;
    return c;
});

const open = ref(new Set());
const toggle = (name) => {
    const s = new Set(open.value);
    s.has(name) ? s.delete(name) : s.add(name);
    open.value = s;
};

const initials = (name) =>
    String(name)
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0].toUpperCase())
        .join('');

const statusText = (f) => {
    if (f.state === 'live') return `Teaching · until ${fmt(f.current.em)}`;
    if (f.state === 'next') return `Next ${fmt(f.next.sm)}`;
    return 'Done for the day';
};
</script>

<template>
    <aside class="ft neu-card relative min-h-[22rem] rounded-2xl" :class="isDark ? 'ft-dark' : ''" :style="themeVars" aria-labelledby="ft-title">
        <!-- Absolute on wide screens so the list scrolls inside, instead of stretching the row taller than Room Occupancy -->
        <div class="ft-inner flex flex-col p-5 xl:absolute xl:inset-0">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <h2 id="ft-title" class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Faculty Today</h2>
                    <p class="ft-muted mt-0.5 text-sm">
                        {{ DAY_LONG[day] ?? '' }}<template v-if="day"> · {{ fmt(minute) }}</template>
                        <span v-if="live" class="ft-live-tag">Live</span>
                    </p>
                </div>
                <span v-if="!loading" class="ft-total" :title="`${faculty.length} faculty with a class`">{{ faculty.length }}</span>
            </div>

            <template v-if="!loading && faculty.length">
                <div class="ft-stats mt-3" aria-label="Faculty status counts">
                    <span class="ft-stat ft-stat-live"><span class="ft-dot" aria-hidden="true"></span>Teaching <strong>{{ counts.live }}</strong></span>
                    <span class="ft-stat ft-stat-next"><span class="ft-dot" aria-hidden="true"></span>Next <strong>{{ counts.next }}</strong></span>
                    <span class="ft-stat ft-stat-done"><span class="ft-dot" aria-hidden="true"></span>Done <strong>{{ counts.done }}</strong></span>
                </div>

                <label class="ft-search mt-3">
                    <i class="pi pi-search text-xs" aria-hidden="true"></i>
                    <input v-model="query" type="search" placeholder="Search faculty" aria-label="Search faculty" />
                </label>
            </template>

            <p v-if="loading" class="ft-muted mt-6 flex items-center gap-2 text-sm" role="status">
                <i class="pi pi-spin pi-spinner" aria-hidden="true"></i> Loading faculty…
            </p>
            <p v-else-if="!faculty.length" class="ft-muted mt-6 text-sm">
                No faculty have a class on {{ DAY_LONG[day] ?? 'this day' }}.
            </p>
            <p v-else-if="!visible.length" class="ft-muted mt-4 text-sm">No faculty match “{{ query }}”.</p>

            <ul v-if="!loading && visible.length" class="ft-list mt-3 min-h-0 flex-1 overflow-y-auto">
                <li v-for="f in visible" :key="f.name" class="ft-item" :class="`ft-item-${f.state}`">
                    <button type="button" class="ft-row" :aria-expanded="open.has(f.name)" @click="toggle(f.name)">
                        <span class="ft-avatar" aria-hidden="true">{{ initials(f.name) }}</span>
                        <span class="min-w-0 flex-1 text-left">
                            <span class="ft-name">{{ f.name }}</span>
                            <span class="ft-status">{{ statusText(f) }}</span>
                        </span>
                        <span class="ft-count" :title="`${f.classes.length} class(es) on ${DAY_LONG[day]}`">{{ f.classes.length }}</span>
                        <i class="pi text-[0.65rem]" :class="open.has(f.name) ? 'pi-chevron-up' : 'pi-chevron-down'" aria-hidden="true"></i>
                    </button>

                    <ul v-if="open.has(f.name)" class="ft-classes">
                        <li v-for="c in f.classes" :key="c.id" :class="{ 'ft-class-now': c.now }">
                            <span class="ft-class-time">{{ rangeText(c.sm, c.em) }}</span>
                            <span class="ft-class-meta"><strong>{{ c.subject }}</strong> · {{ c.section }} · {{ c.room }}</span>
                        </li>
                    </ul>
                </li>
            </ul>

            <p v-if="!loading && unassignedCount" class="ft-muted mt-2 text-xs">
                {{ unassignedCount }} class{{ unassignedCount === 1 ? '' : 'es' }} on {{ DAY_LONG[day] }} {{ unassignedCount === 1 ? 'has' : 'have' }} no faculty assigned yet.
            </p>
        </div>
    </aside>
</template>

<style scoped>
.ft {
    --ft-text: #1e293b;
    --ft-muted: #64748b;
    --ft-line: #e2e8f0;
    --ft-soft: #f8fafc;
    --ft-surface: rgba(255, 255, 255, 0.92);
    --ft-blue: #2563eb;
    --ft-blue-text: #1d4ed8;
    --ft-blue-soft: #eff6ff;
    --ft-blue-line: #bfdbfe;
}
.ft-dark {
    --ft-text: #e2e8f0;
    --ft-muted: #94a3b8;
    --ft-line: rgba(255, 255, 255, 0.12);
    --ft-soft: rgba(255, 255, 255, 0.05);
    --ft-surface: rgba(11, 18, 32, 0.86);
    --ft-blue: #60a5fa;
    --ft-blue-text: #93c5fd;
    --ft-blue-soft: rgba(59, 130, 246, 0.1);
    --ft-blue-line: rgba(96, 165, 250, 0.32);
}

.ft-muted { color: var(--ft-muted); }
.ft-live-tag {
    margin-left: 0.5rem;
    padding: 0.05rem 0.45rem;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 700;
    color: var(--ft-on-lit);
    background: var(--ft-lit);
}
.ft-total {
    flex: none;
    min-width: 1.9rem;
    height: 1.9rem;
    padding: 0 0.5rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    font-weight: 700;
    font-size: 0.85rem;
    background: var(--ft-blue-soft);
    border: 1px solid var(--ft-blue-line);
    color: var(--ft-blue-text);
}

.ft-stats { display: flex; flex-wrap: wrap; gap: 0.35rem 0.75rem; font-size: 0.75rem; color: var(--ft-text); }
.ft-stat { display: inline-flex; align-items: center; gap: 0.35rem; }
.ft-dot { width: 0.55rem; height: 0.55rem; border-radius: 999px; background: var(--ft-muted); opacity: 0.5; }
.ft-stat-live .ft-dot { background: var(--ft-lit); opacity: 1; box-shadow: 0 0 6px var(--ft-glow); }
.ft-stat-next .ft-dot { background: var(--ft-blue-soft); border: 2px solid var(--ft-blue); opacity: 1; }
.ft-stat-done .ft-dot { background: var(--ft-blue-line); opacity: 1; }

.ft-search {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.3rem 0.65rem;
    border-radius: 0.65rem;
    border: 1px solid var(--ft-line);
    background: var(--ft-soft);
    color: var(--ft-muted);
}
.ft-search input { flex: 1; min-width: 0; background: transparent; border: 0; outline: 0; font-size: 0.8rem; color: var(--ft-text); }
.ft-search:focus-within { border-color: var(--ft-blue); }

.ft-list { list-style: none; margin-bottom: 0; padding: 0 0.15rem 0 0; display: flex; flex-direction: column; gap: 0.35rem; }
.ft-item { border: 1px solid var(--ft-line); border-radius: 0.7rem; background: var(--ft-soft); border-left: 3px solid var(--ft-blue-line); }
.ft-item-live { border-left-color: var(--ft-lit); }
.ft-item-next { border-left-color: var(--ft-blue); }
.ft-item-done { opacity: 0.72; }

.ft-row { display: flex; align-items: center; gap: 0.55rem; width: 100%; padding: 0.4rem 0.55rem; background: transparent; border: 0; cursor: pointer; color: var(--ft-text); }
.ft-row:focus-visible { outline: 2px solid var(--ft-text); outline-offset: 1px; border-radius: 0.6rem; }
.ft-avatar {
    flex: none;
    width: 1.9rem;
    height: 1.9rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 700;
    background: var(--ft-blue-soft);
    border: 1px solid var(--ft-blue-line);
    color: var(--ft-blue-text);
}
.ft-item-live .ft-avatar { background: var(--ft-lit); border-color: var(--ft-lit); color: var(--ft-on-lit); }
.ft-name { display: block; font-size: 0.82rem; font-weight: 700; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ft-status { display: block; font-size: 0.7rem; line-height: 1.2; color: var(--ft-muted); font-variant-numeric: tabular-nums; }
.ft-item-next .ft-status { color: var(--ft-blue-text); font-weight: 600; }
.ft-count { flex: none; font-size: 0.7rem; font-weight: 700; padding: 0.05rem 0.45rem; border-radius: 999px; border: 1px solid var(--ft-line); }

.ft-classes { list-style: none; margin: 0; padding: 0.1rem 0.6rem 0.5rem 3rem; display: flex; flex-direction: column; gap: 0.25rem; }
.ft-classes li { display: flex; flex-direction: column; padding-left: 0.5rem; border-left: 3px solid transparent; font-size: 0.74rem; color: var(--ft-text); }
.ft-class-now { border-left-color: var(--ft-lit) !important; }
.ft-class-time { font-weight: 600; font-variant-numeric: tabular-nums; }
.ft-class-meta { color: var(--ft-muted); }
.ft-class-meta strong { color: var(--ft-text); }

@media (prefers-reduced-motion: reduce) {
    .ft-row { transition: none; }
}
</style>

<style>
/* Same reasoning as Room Occupancy: out-rank the Dashboard's frosted glass so
   small text stays readable over the campus photo. */
.dash-scope .ft.neu-card,
.dark .dash-scope .ft.neu-card {
    background: var(--ft-surface);
}
</style>