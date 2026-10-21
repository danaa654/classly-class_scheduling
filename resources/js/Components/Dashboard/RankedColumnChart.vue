<script setup>
import { nextTick, onMounted, ref } from 'vue';

/**
 * A ranked column chart: one column per item, in the order given (the
 * caller ranks them), on a fixed 0-100% axis. Used by the Scheduling
 * Dashboard's Faculty and Room Utilization cards. Wide lists scroll
 * sideways inside the card instead of squeezing the columns.
 *
 * items: [{ key, label, valueLabel, percent, color, zero, title }]
 *   percent  - column height as a % of the axis (capped at 100)
 *   zero     - true when nothing is assigned/booked: draws a short stub
 *   color    - any CSS color for the column
 */
const props = defineProps({
    items: { type: Array, default: () => [] },
    isDark: { type: Boolean, default: false },
    selectable: { type: Boolean, default: false },
    selectedKey: { type: [String, Number], default: null },
    plotHeight: { type: Number, default: 180 },
    colWidth: { type: Number, default: 52 },
    labelHeight: { type: Number, default: 104 },
});

const emit = defineEmits(['select']);

// Room above the 100% line for the value label of a full-height column.
const TOP_PAD = 22;
const TICKS = [100, 50, 0];

// Columns start flat and grow once mounted, so the chart animates in a
// single time on load (skipped for reduced-motion, see the style block).
const ready = ref(false);
onMounted(() => nextTick(() => requestAnimationFrame(() => { ready.value = true; })));

const columnHeight = (item) => {
    if (!ready.value) return 0;
    if (item.zero) return 3;
    const pct = Math.max(0, Math.min(100, item.percent ?? 0));
    return Math.max(4, Math.round((pct / 100) * props.plotHeight));
};
</script>

<template>
    <div class="flex">
        <!-- Y axis (stays put while the columns scroll) -->
        <div class="relative w-10 flex-none" :style="{ height: plotHeight + TOP_PAD + 'px' }" aria-hidden="true">
            <span
                v-for="t in TICKS"
                :key="t"
                class="absolute right-2 translate-y-1/2 text-[11px]"
                :class="isDark ? 'text-slate-500' : 'text-slate-400'"
                :style="{ bottom: (t / 100) * plotHeight + 'px' }"
            >{{ t }}%</span>
        </div>

        <div class="min-w-0 flex-1 overflow-x-auto pb-1">
            <div class="relative" :style="{ minWidth: items.length * colWidth + 'px' }">
                <!-- Gridlines -->
                <div class="pointer-events-none absolute inset-x-0 top-0" :style="{ height: plotHeight + TOP_PAD + 'px' }" aria-hidden="true">
                    <div
                        v-for="t in TICKS"
                        :key="t"
                        class="absolute inset-x-0 border-t border-dashed"
                        :class="isDark ? 'border-white/10' : 'border-slate-200'"
                        :style="{ bottom: (t / 100) * plotHeight + 'px' }"
                    ></div>
                </div>

                <!-- Columns -->
                <div class="relative flex items-stretch">
                    <component
                        :is="selectable ? 'button' : 'div'"
                        v-for="(item, i) in items"
                        :key="item.key"
                        :type="selectable ? 'button' : undefined"
                        class="flex flex-none flex-col items-center rounded-lg transition-colors"
                        :class="[
                            selectable ? 'cursor-pointer' : '',
                            selectable && selectedKey === item.key
                                ? (isDark ? 'bg-white/10' : 'bg-blue-50')
                                : (selectable ? (isDark ? 'hover:bg-white/5' : 'hover:bg-slate-50') : ''),
                        ]"
                        :style="{ width: colWidth + 'px' }"
                        :title="item.title"
                        :aria-label="item.title"
                        :aria-pressed="selectable ? selectedKey === item.key : undefined"
                        @click="selectable && emit('select', item.key)"
                    >
                        <div class="flex w-full flex-col items-center justify-end" :style="{ height: plotHeight + TOP_PAD + 'px' }">
                            <span class="mb-0.5 text-[11px] leading-none tabular-nums" :class="isDark ? 'text-slate-400' : 'text-slate-500'">{{ item.valueLabel }}</span>
                            <div
                                class="rank-col rounded-t-md"
                                :style="{ width: '60%', maxWidth: '34px', height: columnHeight(item) + 'px', backgroundColor: item.color }"
                            ></div>
                        </div>
                        <span class="mt-1.5 text-[11px] tabular-nums" :class="isDark ? 'text-slate-500' : 'text-slate-400'">{{ i + 1 }}</span>
                        <span
                            class="mt-1 mb-1 block overflow-hidden text-ellipsis whitespace-nowrap text-xs font-medium"
                            :class="isDark ? 'text-slate-200' : 'text-slate-700'"
                            :style="{ writingMode: 'vertical-rl', transform: 'rotate(180deg)', height: labelHeight + 'px' }"
                        >{{ item.label }}</span>
                    </component>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.rank-col {
    transition: height 700ms cubic-bezier(0.22, 1, 0.36, 1);
}

@media (prefers-reduced-motion: reduce) {
    .rank-col {
        transition: none;
    }
}
</style>