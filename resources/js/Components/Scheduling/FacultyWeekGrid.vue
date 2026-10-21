<script setup>
import { computed } from 'vue';
import { useCollegeColors, collegeSoftStyle } from '@/composables/useCollegeColors';

/**
 * Weekly timetable (Mon-Sat, per Term Setup) for ONE faculty member's assigned
 * subjects — the same Grid look as Reports > Schedule by Faculty, read-only.
 * Each block is colored by the College that owns its Section (Settings ->
 * College Colors); an Online meeting gets a dashed border instead of a
 * different color.
 *
 * `placements` is Faculty Details' `workload.assigned_placements`; each one
 * carries a `schedules` list (one entry per Face-to-Face / Online meeting).
 */
const props = defineProps({
    placements: { type: Array, default: () => [] },
    // { start_time, end_time, available_days, interval_minutes } — the School
    // Year's scheduling window, same shape the Reports grid uses.
    schedulingWindow: { type: Object, default: () => ({}) },
    isDark: { type: Boolean, default: false },
});

const { colorFor } = useCollegeColors();

const days = computed(() => (props.schedulingWindow.available_days?.length ? props.schedulingWindow.available_days : ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']));
const step = computed(() => props.schedulingWindow.interval_minutes || 30);

const toMinutes = (hhmm) => {
    const [h, m] = (hhmm || '00:00').split(':').map(Number);
    return h * 60 + (m || 0);
};
const formatLabel = (minutes) => {
    const h = Math.floor(minutes / 60) % 24;
    const m = minutes % 60;
    const period = h >= 12 ? 'PM' : 'AM';
    const h12 = h % 12 === 0 ? 12 : h % 12;
    return `${h12}:${String(m).padStart(2, '0')} ${period}`;
};

const gridStart = computed(() => toMinutes(props.schedulingWindow.start_time || '07:00'));
const gridEnd = computed(() => toMinutes(props.schedulingWindow.end_time || '17:00'));

const rows = computed(() => {
    const list = [];
    for (let t = gridStart.value; t < gridEnd.value; t += step.value) {
        list.push({ minutes: t, label: `${formatLabel(t)} – ${formatLabel(t + step.value)}` });
    }
    return list.length ? list : [{ minutes: gridStart.value, label: formatLabel(gridStart.value) }];
});

const blocks = computed(() => {
    const out = [];
    props.placements.forEach((placement) => {
        (placement.schedules ?? []).forEach((schedule) => {
            if (!schedule.days || !schedule.start_time || !schedule.end_time) return;
            const startMin = toMinutes(schedule.start_time);
            const endMin = toMinutes(schedule.end_time);
            const startIndex = Math.max(0, Math.round((startMin - gridStart.value) / step.value));
            if (startIndex >= rows.value.length) return;
            const span = Math.min(Math.max(1, Math.round((endMin - startMin) / step.value)), rows.value.length - startIndex);

            schedule.days
                .split(',')
                .map((d) => d.trim())
                .filter((d) => days.value.includes(d))
                .forEach((day) => {
                    out.push({
                        key: `${schedule.id}-${day}`,
                        dayIndex: days.value.indexOf(day),
                        startIndex,
                        span,
                        subject: placement.subject_title || placement.subject_code,
                        section: placement.section_code,
                        room: schedule.room_name || '—',
                        online: schedule.delivery_mode === 'online',
                        collegeId: placement.college_id,
                    });
                });
        });
    });
    return out;
});

const blockStyle = (block) => ({
    gridColumn: block.dayIndex + 2,
    gridRow: `${block.startIndex + 2} / span ${block.span}`,
    ...collegeSoftStyle(colorFor(block.collegeId), props.isDark),
});
</script>

<template>
    <div class="overflow-x-auto rounded-xl border" :class="isDark ? 'border-white/10' : 'border-slate-200'">
        <div
            class="grid min-w-[720px] text-xs"
            :class="isDark ? 'bg-[#141D33] text-slate-200' : 'bg-white text-slate-700'"
            :style="{
                gridTemplateColumns: `130px repeat(${days.length}, minmax(100px, 1fr))`,
                gridTemplateRows: `32px repeat(${rows.length}, 28px)`,
            }"
        >
            <!-- Header row -->
            <div class="border-b border-r" :class="isDark ? 'border-white/10 bg-white/5' : 'border-slate-200 bg-slate-50'"></div>
            <div
                v-for="day in days"
                :key="`h-${day}`"
                class="flex items-center justify-center border-b border-r font-bold"
                :class="isDark ? 'border-white/10 bg-white/5' : 'border-slate-200 bg-slate-50'"
            >
                {{ day }}
            </div>

            <!-- Time labels + empty cells (these draw the grid lines) -->
            <template v-for="(row, rowIndex) in rows" :key="`r-${row.minutes}`">
                <div
                    class="flex items-center justify-end border-b border-r px-2 text-[10px] whitespace-nowrap"
                    :class="[isDark ? 'border-white/10' : 'border-slate-200', rowIndex % 2 === 0 ? 'font-semibold' : 'text-slate-400']"
                    :style="{ gridColumn: 1, gridRow: rowIndex + 2 }"
                >
                    {{ row.label }}
                </div>
                <div
                    v-for="(day, dayIndex) in days"
                    :key="`c-${row.minutes}-${day}`"
                    class="border-b border-r"
                    :class="isDark ? 'border-white/10' : 'border-slate-200'"
                    :style="{ gridColumn: dayIndex + 2, gridRow: rowIndex + 2 }"
                ></div>
            </template>

            <!-- Blocks -->
            <div
                v-for="block in blocks"
                :key="block.key"
                class="z-[1] m-0.5 overflow-hidden rounded-md border px-1.5 py-1 leading-tight"
                :class="block.online ? 'border-dashed' : ''"
                :style="blockStyle(block)"
                :title="`${block.subject} — ${block.section} — ${block.room}`"
            >
                <p class="truncate text-[11px] font-semibold">{{ block.subject }}</p>
                <p class="truncate opacity-80">{{ block.section }}</p>
                <p class="truncate text-[10px] opacity-75">{{ block.room }}</p>
            </div>
        </div>
    </div>
</template>