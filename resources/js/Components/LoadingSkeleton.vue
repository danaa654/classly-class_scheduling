<script setup>
/**
 * PERFORMANCE / PERCEIVED PERFORMANCE — loading skeletons.
 *
 * Pages like Sections/Index.vue already track a `loading` ref while
 * a filter/search/pagination request is in flight (router.get with
 * onFinish setting loading.value = false). Render this component in
 * place of the table/card content while `loading` is true so the page
 * never shows a blank or frozen area during a reload — the shimmering
 * placeholder communicates "this is updating" instead of "this is broken".
 *
 * Usage:
 *   <LoadingSkeleton v-if="loading" variant="table" :rows="8" :columns="5" />
 *   <DataTable v-else :value="sections" ... />
 *
 *   variant="card"      stacked cards
 *   variant="list"      avatar + two lines
 *   variant="subjects"  Section Subjects rows (details line + field line)
 *   variant="rooms"     Room Grid's room list (name + caption)
 *   variant="timetable" Room Grid's weekly timetable (:rows time slots,
 *                       :columns days)
 *
 * DELAY — nothing is drawn for the first `delay` ms (default 200). A load
 * that finishes inside that window never shows a skeleton at all, instead
 * of flashing one for a split second. Pass :delay="0" to show immediately.
 */
import { ref, onMounted, onBeforeUnmount } from 'vue';

const props = defineProps({
    variant: {
        type: String,
        default: 'table',
        validator: (value) => ['table', 'card', 'list', 'subjects', 'rooms', 'timetable'].includes(value),
    },
    rows: {
        type: Number,
        default: 6,
    },
    columns: {
        type: Number,
        default: 4,
    },
    delay: {
        type: Number,
        default: 200,
    },
});

const visible = ref(props.delay <= 0);
let timer = null;

onMounted(() => {
    if (!visible.value) {
        timer = setTimeout(() => {
            visible.value = true;
        }, props.delay);
    }
});

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div v-if="visible" class="skeleton-wrap" role="status" aria-live="polite" aria-label="Loading">
        <!-- Table variant: header bar + N rows of column-wide bars -->
        <template v-if="variant === 'table'">
            <div class="skeleton-row skeleton-row--header">
                <span
                    v-for="col in columns"
                    :key="`h-${col}`"
                    class="skeleton-bar skeleton-bar--header"
                />
            </div>
            <div v-for="row in rows" :key="`r-${row}`" class="skeleton-row">
                <span
                    v-for="col in columns"
                    :key="`r-${row}-c-${col}`"
                    class="skeleton-bar"
                />
            </div>
        </template>

        <!-- Card variant: stacked rectangular cards -->
        <template v-else-if="variant === 'card'">
            <div v-for="row in rows" :key="`card-${row}`" class="skeleton-card">
                <span class="skeleton-bar skeleton-bar--title" />
                <span class="skeleton-bar skeleton-bar--subtitle" />
            </div>
        </template>

        <!-- Subjects variant: mirrors a Section Subjects row — a details
             line (code / title / category / units / status) above a line
             of five field boxes (Faculty / Room / Days / Start / End). -->
        <template v-else-if="variant === 'subjects'">
            <div v-for="row in rows" :key="`subj-${row}`" class="skeleton-subject">
                <div class="skeleton-row skeleton-subject__details">
                    <span class="skeleton-bar skeleton-bar--code" />
                    <span class="skeleton-bar skeleton-bar--wide" />
                    <span class="skeleton-bar skeleton-bar--pill" />
                    <span class="skeleton-bar skeleton-bar--pill" />
                    <span class="skeleton-bar skeleton-bar--pill" />
                </div>
                <div class="skeleton-row">
                    <span class="skeleton-bar skeleton-bar--field skeleton-bar--grow" />
                    <span class="skeleton-bar skeleton-bar--field skeleton-bar--grow" />
                    <span class="skeleton-bar skeleton-bar--field skeleton-bar--short" />
                    <span class="skeleton-bar skeleton-bar--field skeleton-bar--short" />
                    <span class="skeleton-bar skeleton-bar--field skeleton-bar--short" />
                </div>
            </div>
        </template>

        <!-- Rooms variant: Room Grid's left-hand room list -->
        <template v-else-if="variant === 'rooms'">
            <div v-for="row in rows" :key="`room-${row}`" class="skeleton-room">
                <span class="skeleton-bar skeleton-bar--line1" />
                <span class="skeleton-bar skeleton-bar--line2" />
            </div>
        </template>

        <!-- Timetable variant: header + time-slot rows, with a few block
             shaped cells so it reads as a weekly schedule, not a table. -->
        <template v-else-if="variant === 'timetable'">
            <div class="skeleton-timetable" :style="{ gridTemplateColumns: `5rem repeat(${columns}, minmax(0, 1fr))` }">
                <span class="skeleton-bar skeleton-bar--header" />
                <span v-for="col in columns" :key="`th-${col}`" class="skeleton-bar skeleton-bar--header" />
                <template v-for="row in rows" :key="`tr-${row}`">
                    <span class="skeleton-bar skeleton-bar--time" />
                    <span
                        v-for="col in columns"
                        :key="`tc-${row}-${col}`"
                        class="skeleton-cell"
                        :class="{ 'skeleton-bar skeleton-cell--block': (row * 3 + col) % 5 === 0 }"
                    />
                </template>
            </div>
        </template>

        <!-- List variant: avatar + two lines, repeated -->
        <template v-else>
            <div v-for="row in rows" :key="`list-${row}`" class="skeleton-list-item">
                <span class="skeleton-avatar" />
                <span class="skeleton-lines">
                    <span class="skeleton-bar skeleton-bar--line1" />
                    <span class="skeleton-bar skeleton-bar--line2" />
                </span>
            </div>
        </template>
    </div>
</template>

<style scoped>
.skeleton-wrap {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    width: 100%;
}

.skeleton-row {
    display: flex;
    gap: 0.75rem;
}

.skeleton-row--header {
    opacity: 0.6;
}

.skeleton-bar {
    display: block;
    height: 1rem;
    flex: 1;
    border-radius: 0.5rem;
    background: linear-gradient(90deg, rgba(148, 163, 184, 0.25) 25%, rgba(148, 163, 184, 0.4) 37%, rgba(148, 163, 184, 0.25) 63%);
    background-size: 400% 100%;
    animation: skeleton-shimmer 1.4s ease-in-out infinite;
}

.skeleton-bar--header {
    height: 1.1rem;
    opacity: 0.9;
}

.skeleton-card {
    padding: 1rem;
    border-radius: 0.75rem;
    background: rgba(148, 163, 184, 0.08);
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.skeleton-bar--title {
    width: 40%;
    height: 1.25rem;
}

.skeleton-bar--subtitle {
    width: 70%;
}

/* --- subjects --- */
.skeleton-subject {
    padding: 0.85rem 1rem;
    border-radius: 0.75rem;
    background: rgba(148, 163, 184, 0.08);
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.skeleton-subject__details {
    align-items: center;
}

.skeleton-bar--code {
    flex: 0 0 6rem;
}

.skeleton-bar--wide {
    flex: 2 1 0;
}

.skeleton-bar--pill {
    flex: 0 0 4rem;
    border-radius: 9999px;
}

.skeleton-bar--field {
    height: 2.25rem;
    border-radius: 0.6rem;
}

.skeleton-bar--grow {
    flex: 3 1 0;
}

.skeleton-bar--short {
    flex: 1 1 0;
}

/* --- rooms --- */
.skeleton-room {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    padding: 0.5rem 0.6rem;
    border-radius: 0.5rem;
    background: rgba(148, 163, 184, 0.08);
}

.skeleton-room .skeleton-bar {
    flex: none;
}

/* --- timetable --- */
.skeleton-timetable {
    display: grid;
    gap: 0.5rem;
    align-items: stretch;
}

.skeleton-bar--time {
    height: 0.8rem;
    align-self: center;
    opacity: 0.7;
}

.skeleton-cell {
    display: block;
    min-height: 1.75rem;
    border-radius: 0.4rem;
    background: rgba(148, 163, 184, 0.06);
}

/* Declared after .skeleton-cell so its plain background doesn't win over
   the shimmer gradient. */
.skeleton-cell--block {
    min-height: 3.5rem;
    background: linear-gradient(90deg, rgba(148, 163, 184, 0.25) 25%, rgba(148, 163, 184, 0.4) 37%, rgba(148, 163, 184, 0.25) 63%);
    background-size: 400% 100%;
    animation: skeleton-shimmer 1.4s ease-in-out infinite;
}

.skeleton-list-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.skeleton-avatar {
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 9999px;
    flex-shrink: 0;
    background: linear-gradient(90deg, rgba(148, 163, 184, 0.25) 25%, rgba(148, 163, 184, 0.4) 37%, rgba(148, 163, 184, 0.25) 63%);
    background-size: 400% 100%;
    animation: skeleton-shimmer 1.4s ease-in-out infinite;
}

.skeleton-lines {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}

.skeleton-bar--line1 {
    width: 50%;
}

.skeleton-bar--line2 {
    width: 30%;
    opacity: 0.7;
}

@keyframes skeleton-shimmer {
    0% {
        background-position: 100% 50%;
    }
    100% {
        background-position: 0 50%;
    }
}

@media (prefers-reduced-motion: reduce) {
    .skeleton-bar,
    .skeleton-cell--block,
    .skeleton-avatar {
        animation: none;
    }
}
</style>