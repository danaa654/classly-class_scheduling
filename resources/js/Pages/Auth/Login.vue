<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import AuthFlipCard from '@/Components/AuthFlipCard.vue';
import { useTheme } from '@/composables/useTheme';

defineProps({
    status: {
        type: String,
    },
});

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

// Decorative, blurred schedule preview on the left (purely visual).
const ghostDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
const ghostSlots = ['7:00 - 8:00', '8:00 - 9:00', '9:00 - 10:00', '10:00 - 11:00'];
const ghostChips = [
    { d: 0, s: 0, code: 'ENG 101', c: 'bg-sky-200 text-sky-700' },
    { d: 1, s: 1, code: 'MATH 101', c: 'bg-emerald-200 text-emerald-700' },
    { d: 2, s: 2, code: 'SCI 101', c: 'bg-rose-200 text-rose-600' },
    { d: 3, s: 3, code: 'FIL 101', c: 'bg-amber-200 text-amber-700' },
];
const ghostChipAt = (d, s) => ghostChips.find((x) => x.d === d && x.s === s);
</script>

<template>
    <Head title="Login" />

    <!-- Fixed to the viewport height: the page itself never scrolls -->
    <div
        class="relative h-screen w-full overflow-hidden bg-gradient-to-br"
        style="height: 100dvh;"
        :class="isDark ? 'from-[#0C2340] via-[#0B1B33] to-[#0B1220]' : 'from-[#E3F2FE] via-[#BDE0FC] to-[#7DD3FC]'"
    >
        <!-- Campus photo, faded on the right -->
        <div class="pointer-events-none absolute inset-0">
            <img :src="isDark ? '/bg-dark.png' : '/bg.png'" alt="" aria-hidden="true" class="h-full w-full object-cover object-right blur-[2px]" :class="isDark ? 'opacity-20' : 'opacity-35'" />
            <div
                class="absolute inset-0 bg-gradient-to-r"
                :class="isDark ? 'from-[#0B1220]/80 via-[#0B1220]/40 to-transparent' : 'from-[#E3F2FE]/80 via-[#BDE0FC]/30 to-transparent'"
            ></div>
        </div>

        <!-- Blurred schedule preview (left) -->
        <div
            class="pointer-events-none absolute -left-10 top-[34%] hidden w-[34rem] -rotate-6 rounded-2xl p-5 blur-[2px] lg:block"
            :class="isDark ? 'bg-white/5 opacity-50' : 'bg-white/50 opacity-70'"
            aria-hidden="true"
        >
            <p class="mb-3 text-sm font-bold" :class="isDark ? 'text-sky-200' : 'text-[#0A6CF0]'">Class Schedule</p>
            <div class="grid text-[9px]" style="grid-template-columns: 4rem repeat(5, minmax(0, 1fr));">
                <div></div>
                <div v-for="day in ghostDays" :key="day" class="pb-2 text-center font-semibold" :class="isDark ? 'text-slate-300' : 'text-slate-500'">{{ day }}</div>
                <template v-for="(slot, si) in ghostSlots" :key="slot">
                    <div class="flex h-10 items-center border-t pr-1" :class="isDark ? 'border-white/10 text-slate-400' : 'border-slate-200 text-slate-500'">{{ slot }}</div>
                    <div v-for="(day, di) in ghostDays" :key="slot + day" class="h-10 border-l border-t p-0.5" :class="isDark ? 'border-white/10' : 'border-slate-200'">
                        <div v-if="ghostChipAt(di, si)" class="flex h-full items-center rounded-md px-1.5 font-bold" :class="ghostChipAt(di, si).c">{{ ghostChipAt(di, si).code }}</div>
                    </div>
                </template>
            </div>
        </div>

        <!-- Decorative dots, rings and bubbles -->
        <div class="pointer-events-none absolute inset-0 overflow-hidden">
            <div class="absolute -left-8 top-32 h-24 w-24 rounded-full border-2" :class="isDark ? 'border-white/20' : 'border-white/90'"></div>
            <div class="absolute left-[3%] top-16 h-10 w-10 rounded-full" :class="isDark ? 'bg-white/10' : 'bg-white/70'"></div>
            <div class="absolute right-[14%] top-24 h-8 w-8 rounded-full" :class="isDark ? 'bg-white/10' : 'bg-white/60'"></div>
            <div class="absolute -right-8 top-40 h-24 w-24 rounded-full" :class="isDark ? 'bg-white/10' : 'bg-white/50'"></div>
            <div class="absolute -right-6 top-[55%] h-20 w-20 rounded-full border-2" :class="isDark ? 'border-white/15' : 'border-white/80'"></div>
            <div class="absolute bottom-10 left-[2%] h-32 w-32 rounded-full border-2" :class="isDark ? 'border-white/15' : 'border-white/80'"></div>
            <div class="absolute bottom-6 right-[2%] h-24 w-24 rounded-full border-2" :class="isDark ? 'border-white/15' : 'border-white/80'"></div>
            <svg class="absolute left-[7%] top-[22%] hidden h-16 w-16 opacity-70 lg:block" :class="isDark ? 'text-white/60' : 'text-white'" viewBox="0 0 60 60" fill="currentColor">
                <circle v-for="n in 16" :key="'l-' + n" :cx="(n % 4) * 16 + 4" :cy="Math.floor(n / 4) * 16 + 4" r="2" />
            </svg>
            <svg class="absolute right-[8%] top-[14%] hidden h-16 w-16 opacity-70 lg:block" :class="isDark ? 'text-white/60' : 'text-white'" viewBox="0 0 60 60" fill="currentColor">
                <circle v-for="n in 16" :key="'r-' + n" :cx="(n % 4) * 16 + 4" :cy="Math.floor(n / 4) * 16 + 4" r="2" />
            </svg>
            <svg class="absolute bottom-[22%] right-[6%] hidden h-16 w-16 opacity-70 lg:block" :class="isDark ? 'text-white/60' : 'text-white'" viewBox="0 0 60 60" fill="currentColor">
                <circle v-for="n in 16" :key="'b-' + n" :cx="(n % 4) * 16 + 4" :cy="Math.floor(n / 4) * 16 + 4" r="2" />
            </svg>
        </div>

        <!-- Waves along the bottom -->
        <svg class="pointer-events-none absolute bottom-0 left-0 block w-full" viewBox="0 0 1440 220" preserveAspectRatio="none" style="height: 22vh; min-height: 120px;">
            <path :fill="isDark ? 'rgba(2,132,199,0.18)' : 'rgba(255,255,255,0.55)'" d="M0,110 C200,40 420,60 640,110 C860,160 1100,150 1440,60 L1440,220 L0,220 Z" />
            <path :fill="isDark ? 'rgba(2,132,199,0.3)' : 'rgba(56,189,248,0.45)'" d="M0,150 C240,90 460,110 700,150 C940,190 1200,170 1440,100 L1440,220 L0,220 Z" />
            <path :fill="isDark ? 'rgba(2,132,199,0.45)' : 'rgba(14,165,233,0.55)'" d="M0,185 C260,150 520,165 760,190 C1000,215 1220,200 1440,160 L1440,220 L0,220 Z" />
        </svg>

        <!-- Top bar: brand (left) + theme toggle (right). Absolute so it doesn't add to page height -->
        <div class="absolute inset-x-0 top-0 z-20 flex items-center justify-between px-6 pt-6 lg:px-10">
            <Link :href="route('welcome')" class="flex items-center gap-2.5">
                <img src="/logo.png" alt="CLASSLY" class="h-10 w-10 rounded-xl bg-white object-contain p-1 shadow-md" />
                <span class="text-xl font-extrabold tracking-tight" style="font-family: 'Baloo 2', 'Inter', sans-serif;">
                    <span style="color: #1B2CC1;">CLASS</span><span class="text-red-500">LY</span>
                </span>
            </Link>
            <ThemeToggle />
        </div>

        <!-- Login card, centered in the viewport. Only scrolls internally on very short screens -->
        <div class="relative z-10 flex h-full w-full overflow-y-auto px-6 pb-6 pt-20">
            <div class="m-auto w-full max-w-md sm:max-w-lg">
                <AuthFlipCard initial-face="login" :is-dark="isDark" :login-status="status" />
            </div>
        </div>
    </div>
</template> 