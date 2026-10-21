<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import InputText from 'primevue/inputtext';
import Password from 'primevue/password';
import Button from 'primevue/button';
import Message from 'primevue/message';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import { useTheme } from '@/composables/useTheme';

/**
 * ACCEPT INVITE — where a faculty member lands from the emailed (or
 * hand-delivered) "set your password" link. Posts to the invitation
 * endpoint (FacultyInviteController), which enforces the Settings >
 * Security password policy and marks the account as set up.
 *
 * Visual twin of the Login page: same campus photo, blurred schedule
 * preview, dots/rings, waves and neon-bordered card.
 */
const props = defineProps({
    email: { type: String, default: '' },
    token: { type: String, required: true },
    // False when the link is expired, already used, or the account can't use it.
    valid: { type: Boolean, default: false },
    policyDescription: { type: String, default: '' },
});

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('invite.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};

const inputClass = computed(() =>
    isDark.value
        ? '!text-white placeholder:!text-slate-500'
        : '!text-[#1E293B] placeholder:!text-slate-400'
);

// School branding footer (Settings → General), same as the Login card.
const page = usePage();
const schoolBranding = computed(() => page.props.schoolBranding ?? { name: null, logoUrl: null });

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
    <Head title="Set your password" />

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

        <!-- Top bar: brand (left) + theme toggle (right) -->
        <div class="absolute inset-x-0 top-0 z-20 flex items-center justify-between px-6 pt-6 lg:px-10">
            <Link :href="route('welcome')" class="flex items-center gap-2.5">
                <img src="/logo.png" alt="CLASSLY" class="h-10 w-10 rounded-xl bg-white object-contain p-1 shadow-md" />
                <span class="text-xl font-extrabold tracking-tight" style="font-family: 'Baloo 2', 'Inter', sans-serif;">
                    <span style="color: #1B2CC1;">CLASS</span><span class="text-red-500">LY</span>
                </span>
            </Link>
            <ThemeToggle />
        </div>

        <!-- Card, centered in the viewport. Only scrolls internally on short screens -->
        <div class="relative z-10 flex h-full w-full overflow-y-auto px-6 pb-4 pt-16">
            <div class="m-auto w-full max-w-md sm:max-w-lg">
                <div class="neu-card neu-spotlight neon-border rounded-3xl" style="--neon-color: #22C55E;">
                    <div class="rounded-3xl p-6 transition-colors duration-300">
                        <!-- Brand head (same as Login) -->
                        <div class="invite-head mb-3 flex flex-col items-center text-center">
                            <span
                                class="invite-logo neu-inset neu-glow mb-2 flex h-16 w-16 items-center justify-center rounded-full"
                                :style="{ '--neu-glow-color': isDark ? 'rgba(56, 189, 248, 0.35)' : 'rgba(37, 99, 235, 0.3)' }"
                            >
                                <img src="/logo.png" alt="CLASSLY" class="h-10 w-10" />
                            </span>
                            <h1 class="text-xl font-extrabold tracking-tight" :class="isDark ? 'text-white' : 'text-[#1E293B]'">CLASSLY</h1>
                            <p class="invite-tagline mt-0.5 text-xs font-medium text-[#2563EB]" :class="isDark && '!text-[#5B9CFF]'">
                                Class Scheduling and Management System
                            </p>
                        </div>

                        <!-- Link expired / already used -->
                        <template v-if="!valid">
                            <h2 class="text-xl font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">This link can't be used</h2>
                            <p class="mt-2 text-sm" :class="isDark ? 'text-slate-300' : 'text-slate-500'">
                                The invitation link has expired or was already used. If you already set your password, log in
                                below (use <strong>Forgot password?</strong> if you've forgotten it). Otherwise, ask the
                                Registrar's office to send you a new invitation.
                            </p>
                            <Link :href="route('login')" class="mt-6 block">
                                <Button
                                    type="button"
                                    label="Go to login"
                                    class="!w-full !border-[#2563EB] !bg-[#2563EB]"
                                    size="large"
                                />
                            </Link>
                        </template>

                        <!-- Set password -->
                        <template v-else>
                            <h2 class="text-xl font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Set your password</h2>
                            <p class="mt-1 text-sm" :class="isDark ? 'text-slate-300' : 'text-slate-500'">
                                Welcome! Choose a password to activate your CLASSLY account.
                            </p>

                            <form class="mt-4 flex flex-col gap-3" @submit.prevent="submit">
                                <Message
                                    v-if="form.errors.email"
                                    severity="error"
                                    :closable="false"
                                    :class="isDark ? '!border-red-400/30 !bg-red-400/10 !text-red-200' : '!border-red-300 !bg-red-50 !text-red-700'"
                                >
                                    {{ form.errors.email }}
                                </Message>

                                <div class="flex flex-col gap-1.5">
                                    <label for="email" class="text-sm font-medium" :class="isDark ? 'text-slate-200' : 'text-slate-700'">Email</label>
                                    <InputText
                                        id="email"
                                        v-model="form.email"
                                        type="email"
                                        readonly
                                        autocomplete="username"
                                        :class="['w-full neu-inset !rounded-xl !border-none', inputClass]"
                                    />
                                </div>

                                <div class="flex flex-col gap-1.5">
                                    <label for="password" class="text-sm font-medium" :class="isDark ? 'text-slate-200' : 'text-slate-700'">New Password</label>
                                    <Password
                                        id="password"
                                        v-model="form.password"
                                        toggleMask
                                        :feedback="false"
                                        autocomplete="new-password"
                                        :inputClass="['w-full neu-inset !rounded-xl !border-none', inputClass]"
                                        class="w-full"
                                        :invalid="!!form.errors.password"
                                        placeholder="••••••••"
                                        autofocus
                                    />
                                    <small v-if="policyDescription" :class="isDark ? 'text-slate-400' : 'text-slate-500'">{{ policyDescription }}</small>
                                    <small v-if="form.errors.password" class="text-red-400">
                                        {{ form.errors.password }}
                                    </small>
                                </div>

                                <div class="flex flex-col gap-1.5">
                                    <label for="password_confirmation" class="text-sm font-medium" :class="isDark ? 'text-slate-200' : 'text-slate-700'">
                                        Confirm New Password
                                    </label>
                                    <Password
                                        id="password_confirmation"
                                        v-model="form.password_confirmation"
                                        :feedback="false"
                                        toggleMask
                                        autocomplete="new-password"
                                        :inputClass="['w-full neu-inset !rounded-xl !border-none', inputClass]"
                                        class="w-full"
                                        :invalid="!!form.errors.password_confirmation"
                                        placeholder="••••••••"
                                    />
                                    <small v-if="form.errors.password_confirmation" class="text-red-400">
                                        {{ form.errors.password_confirmation }}
                                    </small>
                                </div>

                                <Button
                                    type="submit"
                                    label="Set password"
                                    class="!w-full !border-[#2563EB] !bg-[#2563EB]"
                                    :loading="form.processing"
                                />
                            </form>
                        </template>
                    </div>
                </div>

                <!-- School branding footer (Settings → General) -->
                <p
                    v-if="schoolBranding.name"
                    class="mt-2 text-center text-xs"
                    :class="isDark ? 'text-slate-500' : 'text-slate-400'"
                >
                    &copy; 2026 {{ schoolBranding.name }} — by Classly @ DJS
                </p>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Very short viewports (small laptops, landscape phones): tighten further so
   the card still fits on one screen without scrolling. */
@media (max-height: 760px) {
    .invite-tagline {
        display: none;
    }
    .invite-logo {
        height: 3rem;
        width: 3rem;
        margin-bottom: 0.25rem;
    }
    .invite-logo > img {
        height: 1.75rem;
        width: 1.75rem;
    }
}
</style>    