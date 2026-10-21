<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';

// PERFORMANCE (unnecessary re-renders) — the live clock lives in its
// own component so the once-per-second tick re-renders only these
// three spans, not the entire Dashboard (KPI cards, progress,
// conflict panel) that used to depend on the ticking `now` ref.
defineProps({
    isDark: {
        type: Boolean,
        default: false,
    },
});

const now = ref(new Date());
let clockInterval = null;

onMounted(() => {
    clockInterval = setInterval(() => {
        now.value = new Date();
    }, 1000);
});

onUnmounted(() => {
    if (clockInterval) clearInterval(clockInterval);
});

const currentDateLabel = computed(() =>
    now.value.toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }),
);
const currentTimeLabel = computed(() =>
    now.value.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' }),
);
</script>

<template>
    <span>{{ currentDateLabel }}</span>
    <span class="opacity-50">•</span>
    <span class="font-mono font-semibold tabular-nums" :class="isDark ? 'text-slate-200' : 'text-slate-700'">{{ currentTimeLabel }}</span>
</template>