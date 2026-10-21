<script setup>
/**
 * Two-option segmented switch (Meter | Bars) for the Scheduling
 * Dashboard's utilization cards. v-model holds 'meter' or 'bars'.
 */
defineProps({
    modelValue: { type: String, required: true },
    isDark: { type: Boolean, default: false },
    label: { type: String, default: 'Chart view' },
});

const emit = defineEmits(['update:modelValue']);

const options = [
    { value: 'meter', label: 'Meter', icon: 'pi-align-left' },
    { value: 'bars', label: 'Bars', icon: 'pi-chart-bar' },
];
</script>

<template>
    <div
        role="group"
        :aria-label="label"
        class="inline-flex rounded-full p-0.5 text-xs font-semibold"
        :class="isDark ? 'bg-white/10' : 'bg-slate-100'"
    >
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            class="flex items-center gap-1 rounded-full px-2.5 py-1 transition-colors"
            :class="modelValue === option.value
                ? (isDark ? 'bg-[#5B9CFF]/20 text-[#5B9CFF]' : 'bg-white text-[#2563EB] shadow-sm')
                : (isDark ? 'text-slate-400 hover:text-slate-200' : 'text-slate-500 hover:text-slate-700')"
            :aria-pressed="modelValue === option.value"
            @click="emit('update:modelValue', option.value)"
        >
            <i class="pi text-[11px]" :class="option.icon" aria-hidden="true"></i>
            {{ option.label }}
        </button>
    </div>
</template>