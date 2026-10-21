<script setup>
import { computed, reactive, ref, onMounted, onBeforeUnmount } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import Button from 'primevue/button';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import { useTheme } from '@/composables/useTheme';
import { useLandingMotion } from '@/composables/useLandingMotion';

const { theme } = useTheme();

// Scroll motion (reveals, parallax, active nav link) — see useLandingMotion.js.
const landingRoot = ref(null);
const { activeSection } = useLandingMotion(landingRoot);

// Hide the browser scrollbar on this page only (scrolling still works).
// The class is removed on leave so other pages keep their normal scrollbar.
onMounted(() => document.documentElement.classList.add('hide-scrollbar'));
onBeforeUnmount(() => document.documentElement.classList.remove('hide-scrollbar'));
const isDark = computed(() => theme.value === 'dark');

// SCHOOL BRANDING — shared globally by HandleInertiaRequests from
// Settings → General. This is distinct from CLASSLY's own system
// branding (name/logo/tagline below), which never changes based on
// this. Falls back gracefully: no logo shown if none configured.
const page = usePage();
const schoolBranding = computed(() => page.props.schoolBranding ?? { name: null, logoUrl: null });

const features = [
    {
        icon: 'pi pi-calendar',
        title: 'Smart Scheduling',
        description: 'Build conflict-free class schedules in minutes, not spreadsheets.',
        accent: '#2563EB',
        details: [
            'Auto-generate schedule drafts in seconds',
            'Instant conflict detection for rooms, faculty, and time slots',
            'Review and finalize a term schedule in one click',
        ],
    },
    {
        icon: 'pi pi-users',
        title: 'Faculties',
        description: 'Assign instructors with real-time availability and load checking.',
        accent: '#0EA5E9',
        details: [
            'See live faculty availability while assigning classes',
            'Automatic warnings before a teacher is overloaded',
            'Keep every faculty load fair and policy-compliant',
        ],
    },
    {
        icon: 'pi pi-building',
        title: 'Rooms',
        description: 'Match sections to rooms with type, capacity, and usage awareness.',
        accent: '#8B5CF6',
        details: [
            'Recommend rooms based on type and live availability',
            'Flag capacity and room-type mismatches automatically',
            'See utilization across every room, every term',
        ],
    },
    {
        icon: 'pi pi-chart-bar',
        title: 'Reports',
        description: 'Generate printable, term-ready scheduling reports on demand.',
        accent: '#F43F5E',
        details: [
            'Export section, faculty, and room schedules as PDFs',
            'Track scheduling progress across the whole term',
            'Share print-ready reports with administrators instantly',
        ],
    },
];

// About section: highlights + the sample schedule shown in the app preview.
const aboutHighlights = [
    { icon: 'pi pi-calendar', title: 'Auto Generation', text: 'Create optimized schedules in seconds.' },
    { icon: 'pi pi-shield', title: 'Conflict Detection', text: 'Real-time alerts for room, faculty, and time conflicts.' },
    { icon: 'pi pi-users', title: 'Manage Resources', text: 'Handle rooms, faculty, and assignments seamlessly.' },
    { icon: 'pi pi-file', title: 'Generate Reports', text: 'Get detailed schedules and official outputs instantly.' },
];

const previewDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
const previewSlots = ['7:00 – 8:00', '8:00 – 9:00', '9:00 – 10:00', '10:00 – 11:00', '11:00 – 12:00', '1:00 – 2:00', '2:00 – 3:00'];
const previewMenu = [
    { icon: 'pi pi-home', label: 'Dashboard' },
    { icon: 'pi pi-calendar', label: 'Class Schedule', active: true },
    { icon: 'pi pi-book', label: 'Prospectus' },
    { icon: 'pi pi-user', label: 'Faculty' },
    { icon: 'pi pi-building', label: 'Rooms' },
    { icon: 'pi pi-chart-bar', label: 'Reports' },
    { icon: 'pi pi-cog', label: 'Settings' },
];
const previewClasses = [
    { d: 0, s: 0, code: 'ENG 101', room: 'Room 201', c: 'blue' },
    { d: 1, s: 1, code: 'MATH 101', room: 'Room 203', c: 'green' },
    { d: 2, s: 2, code: 'SCI 101', room: 'Room 205', c: 'red' },
    { d: 3, s: 3, code: 'FIL 101', room: 'Room 202', c: 'amber' },
    { d: 4, s: 4, code: 'HIST 101', room: 'Room 204', c: 'purple' },
    { d: 1, s: 5, code: 'COMP 101', room: 'Lab 1', c: 'purple' },
    { d: 2, s: 6, code: 'ENG 101', room: 'Room 201', c: 'blue' },
    { d: 3, s: 6, code: 'MATH 101', room: 'Room 203', c: 'green' },
];
const chipAt = (d, s) => previewClasses.find((x) => x.d === d && x.s === s);
const chipLight = {
    blue: 'bg-sky-100 text-sky-700',
    green: 'bg-emerald-100 text-emerald-700',
    red: 'bg-rose-100 text-rose-600',
    amber: 'bg-amber-100 text-amber-700',
    purple: 'bg-violet-100 text-violet-700',
};
const chipDark = {
    blue: 'bg-sky-400/20 text-sky-300',
    green: 'bg-emerald-400/20 text-emerald-300',
    red: 'bg-rose-400/20 text-rose-300',
    amber: 'bg-amber-400/20 text-amber-300',
    purple: 'bg-violet-400/20 text-violet-300',
};
const chipClass = (c) => (isDark.value ? chipDark[c] : chipLight[c]);

// Frosted-glass surface for the feature cards (light / dark).
const cardSurface = computed(() =>
    isDark.value
        ? 'border-white/10 bg-white/5 shadow-xl shadow-black/20'
        : 'border-white/80 bg-white/60 shadow-xl shadow-sky-500/15'
);

// Which feature cards are currently showing their back (info) face.
const flipped = reactive({});
const toggleFlip = (title) => {
    flipped[title] = !flipped[title];
};

// Spawns a short-lived "bubble" ripple at the click point on a feature card.
const spawnRipple = (event) => {
    const card = event.currentTarget;
    const rect = card.getBoundingClientRect();
    const ripple = document.createElement('span');
    const size = Math.max(rect.width, rect.height) * 1.4;
    ripple.className = 'click-ripple';
    ripple.style.width = ripple.style.height = `${size}px`;
    ripple.style.left = `${event.clientX - rect.left - size / 2}px`;
    ripple.style.top = `${event.clientY - rect.top - size / 2}px`;
    card.appendChild(ripple);
    ripple.addEventListener('animationend', () => ripple.remove());
};

const onFeatureCardClick = (event, title) => {
    spawnRipple(event);
    toggleFlip(title);
};

// Hero-only firefly effect: amber in dark mode, green in light mode.
// Randomized per page load so it doesn't feel like a fixed, repeating
// pattern.
const fireflies = Array.from({ length: 22 }, (_, i) => ({
    id: i,
    left: Math.random() * 100,
    top: Math.random() * 100,
    size: 2 + Math.random() * 3,
    duration: 7 + Math.random() * 8,
    delay: -Math.random() * 12,
    drift: (Math.random() > 0.5 ? 1 : -1) * (20 + Math.random() * 40),
}));
</script>

<template>
    <Head title="Welcome" />

    <!-- Outer canvas: a soft powder-blue backdrop the rounded hero "card" sits on,
         echoing the teal-frame / inset-card layout of the reference design. -->
    <!-- The 1.1 zoom is desktop-only: on a phone it enlarged everything
         10% past the screen width, which pushed the right side of the
         nav (theme toggle, school name) and hero text off-screen. -->
    <div ref="landingRoot" class="min-h-screen md:[zoom:1.1]" :class="isDark ? 'bg-[#020617]' : 'bg-[#BAE6FD]'">
        <div class="relative overflow-hidden" :class="isDark ? 'bg-[#0B1220]' : 'bg-[#F2F2F2]'">

            <!-- ============ HERO (powder-blue gradient card) ============ -->
            <div
                data-section="hero"
                data-parallax
                class="relative overflow-hidden bg-gradient-to-br lg:aspect-[1824/862]"
                :class="isDark ? 'from-[#075985] via-[#0369A1] to-[#0284C7]' : 'from-[#7DD3FC] via-[#38BDF8] to-[#0284C7]'"
            >
                <!-- Campus photo background (public/bg.png). Anchored bottom-right so the whole
                     building stays visible; only a light wash on the left keeps the text readable. -->
                <div class="pointer-events-none absolute inset-0">
                    <img :src="isDark ? '/bg-dark.png' : '/bg.png'" alt="" aria-hidden="true" class="h-full w-full object-cover object-[right_bottom]" />
                    <div
                        class="absolute inset-0 bg-gradient-to-r"
                        :class="isDark
                            ? 'from-[#075985]/90 via-[#0369A1]/50 to-[#0B1220]/25'
                            : 'from-[#1D9BF0]/70 via-[#38BDF8]/25 to-transparent'"
                    ></div>
                    <div v-if="isDark" class="absolute inset-0 bg-[#0B1220]/30"></div>
                </div>

                <!-- decorative blobs / dotted pattern, like the reference hero -->
                <div class="pointer-events-none absolute inset-0 overflow-hidden">
                  <div class="absolute inset-0" data-parallax-layer="-0.08">
                    <div class="absolute -left-10 top-24 h-16 w-16 rounded-full border-4 border-white/30"></div>
                    <div class="absolute left-16 top-16 h-10 w-10 rounded-full bg-white/15"></div>
                    <div class="absolute right-[18%] top-10 h-8 w-8 rounded-full bg-white/20"></div>
                    <div class="absolute bottom-40 right-[8%] h-24 w-24 rounded-full border-4 border-white/25"></div>
                    <div class="absolute -bottom-10 left-1/3 h-6 w-6 rotate-45 bg-white/20"></div>
                    <svg class="absolute right-6 top-28 h-24 w-24 text-white/25 lg:right-16" viewBox="0 0 60 60" fill="currentColor">
                        <circle v-for="n in 16" :key="n" :cx="(n % 4) * 16 + 4" :cy="Math.floor(n / 4) * 16 + 4" r="2" />
                    </svg>
                  </div>
                </div>

                <!-- Fireflies: amber in dark mode, green in light mode. Scoped to the hero only. -->
                <div class="pointer-events-none absolute inset-0 z-[5] overflow-hidden">
                    <span
                        v-for="fly in fireflies"
                        :key="'fly-' + fly.id"
                        class="firefly"
                        :class="{ 'firefly--green': !isDark }"
                        :style="{
                            left: fly.left + '%',
                            top: fly.top + '%',
                            width: fly.size + 'px',
                            height: fly.size + 'px',
                            animationDuration: fly.duration + 's',
                            animationDelay: fly.delay + 's',
                            '--drift': fly.drift + 'px',
                        }"
                    />
                </div>

                <!-- Navigation: liquid-glass floating pill -->
                <nav class="relative z-10 mx-auto max-w-6xl px-3 pt-4 sm:px-6 sm:pt-6 lg:px-10">
                    <div class="liquid-glass-nav flex items-center justify-between gap-2 rounded-full px-3 py-2.5 sm:px-5 sm:py-3">
                        <!-- min-w-0 lets the brand group shrink instead of pushing the
                             theme toggle / Sign In off the edge on narrow phones. -->
                        <div class="flex min-w-0 items-center gap-2 sm:gap-2.5">
                            <img src="/logo.png" alt="CLASSLY" class="h-9 w-9 shrink-0 rounded-lg bg-white/90 object-contain p-1" />
                            <span class="shrink-0 text-base font-extrabold tracking-tight sm:text-lg" style="font-family: 'Baloo 2', 'Inter', sans-serif;"><span style="color: #1B2CC1;">CLASS</span><span class="text-red-500">LY</span></span>
                            <template v-if="schoolBranding.name">
                                <span class="hidden h-5 w-px shrink-0 sm:block" :class="isDark ? 'bg-white/30' : 'bg-[#0F172A]/20'"></span>
                                <span class="hidden min-w-0 truncate text-[11px] font-medium uppercase tracking-wide sm:block" :class="isDark ? 'text-white/80' : 'text-[#0F172A]/70'">{{ schoolBranding.name }}</span>
                            </template>
                        </div>

                        <div class="hidden items-center gap-8 text-sm font-medium md:flex" :class="isDark ? 'text-white/90' : 'text-[#0F172A]/80'">
                            <a
                                href="#features"
                                class="nav-link transition-colors"
                                :class="[isDark ? 'hover:text-white' : 'hover:text-[#0284C7]', activeSection === 'features' ? (isDark ? 'text-white' : 'text-[#0284C7]') : '']"
                                :aria-current="activeSection === 'features' ? 'location' : undefined"
                            >Features</a>
                            <a
                                href="#about"
                                class="nav-link transition-colors"
                                :class="[isDark ? 'hover:text-white' : 'hover:text-[#0284C7]', activeSection === 'about' ? (isDark ? 'text-white' : 'text-[#0284C7]') : '']"
                                :aria-current="activeSection === 'about' ? 'location' : undefined"
                            >About</a>
                        </div>

                        <div class="flex shrink-0 items-center gap-2 sm:gap-3">
                            <ThemeToggle />
                            <Link :href="route('login')">
                                <Button label="Sign In" class="!border-0 !bg-[#0284C7] !px-3 !text-sm !text-white hover:!bg-[#026CA3] sm:!px-4 sm:!text-base" />
                            </Link>
                        </div>
                    </div>
                </nav>

                <!-- Hero content -->
                <section class="relative z-10 mx-auto w-full max-w-7xl px-4 pb-28 pt-8 sm:px-6 lg:px-10 lg:pb-28 lg:pt-10">
                    <div class="max-w-xl text-center lg:pl-10 lg:text-left">
                        <p class="hero-in mb-5 inline-flex items-center rounded-full bg-white/80 px-3 py-1 text-[10px] font-semibold uppercase tracking-widest text-[#0369A1] shadow-lg backdrop-blur-xl" style="--hero-delay: 0ms;">
                            Class Scheduling &amp; Management System
                        </p>
                        <h1
                            class="hero-in text-4xl font-extrabold leading-none text-white sm:text-5xl lg:text-6xl"
                            style="font-family: 'Baloo 2', 'Inter', sans-serif; --hero-delay: 100ms;"
                        >
                            WELCOME TO
                            <span class="mt-1 block text-5xl sm:text-6xl lg:text-7xl"><span style="color: #1B2CC1;">CLASS</span><span class="text-red-500">LY</span></span>
                        </h1>
                        <p class="hero-in mt-1 text-lg font-semibold text-white/90 sm:text-xl" style="--hero-delay: 200ms;">
                            Your <span style="color: #1B2CC1;">Friend</span><span class="text-red-500">ly</span> Class Scheduler
                        </p>
                        <p class="hero-in mx-auto mt-5 max-w-md text-sm text-white/85 lg:mx-0" style="--hero-delay: 280ms;">
                            Professional Academy of the Philippines' centralized platform for class schedules,
                            faculty assignments, rooms, and academic planning.
                        </p>

                        <div class="hero-in mt-8 flex flex-wrap items-center justify-center gap-4 lg:justify-start" style="--hero-delay: 360ms;">
                            <Link :href="route('login')">
                                <Button
                                    label="Get Started"
                                    class="!border-0 !bg-white !px-6 !py-3 !font-semibold !text-[#0284C7] shadow-lg hover:!bg-white/90"
                                />
                            </Link>
                            <a
                                href="#features"
                                class="flex items-center gap-2 rounded-full border border-white/40 px-5 py-3 text-sm font-semibold text-white transition-colors hover:bg-white/10"
                            >
                                Explore Features
                                <i class="pi pi-arrow-right text-xs"></i>
                            </a>
                        </div>
                    </div>
                </section>

                <!-- Scroll cue -->
                <div class="absolute bottom-20 left-1/2 z-20 -translate-x-1/2 sm:bottom-24">
                    <a
                        href="#about"
                        class="flex h-10 w-10 items-center justify-center rounded-full !border-white/40 bg-white/15 text-white backdrop-blur-xl transition-colors hover:bg-white/25"
                        style="animation: academic-glow-pulse 2.5s ease-in-out infinite;"
                    >
                        <i class="pi pi-chevron-down"></i>
                    </a>
                </div>

                <!-- Wave divider laid over the bottom of the photo: a translucent back wave
                     plus a solid front wave that blends into the section below. -->
                <svg class="pointer-events-none absolute bottom-0 left-0 z-10 block w-full" viewBox="0 0 1440 160" preserveAspectRatio="none" style="height: clamp(70px, 11vw, 150px);">
                    <path
                        :fill="isDark ? 'rgba(11,18,32,0.45)' : 'rgba(255,255,255,0.5)'"
                        d="M0,70 C180,10 340,30 520,70 C700,110 840,60 1020,30 C1200,0 1340,30 1440,60 L1440,160 L0,160 Z"
                    />
                    <path
                        :fill="isDark ? '#0B1220' : '#F2F2F2'"
                        d="M0,110 C150,60 300,70 460,100 C640,135 760,150 940,115 C1120,80 1280,60 1440,95 L1440,160 L0,160 Z"
                    />
                </svg>
            </div>

            <!-- ============ ABOUT (Smart Scheduling + app preview) ============ -->
            <section id="about" data-section="about" data-parallax class="relative z-10 overflow-hidden px-6 pb-12 pt-2 lg:px-10">
                <div class="pointer-events-none absolute inset-0" data-parallax-layer="-0.05">
                <!-- decorative dots + rings -->
                <svg class="pointer-events-none absolute left-6 top-16 hidden h-14 w-14 opacity-40 lg:block" :class="isDark ? 'text-white' : 'text-[#0284C7]'" viewBox="0 0 60 60" fill="currentColor">
                    <circle v-for="n in 16" :key="'al-' + n" :cx="(n % 4) * 16 + 4" :cy="Math.floor(n / 4) * 16 + 4" r="2" />
                </svg>
                <svg class="pointer-events-none absolute right-6 top-28 hidden h-12 w-12 opacity-40 lg:block" :class="isDark ? 'text-white' : 'text-[#0284C7]'" viewBox="0 0 60 60" fill="currentColor">
                    <circle v-for="n in 12" :key="'ar-' + n" :cx="(n % 3) * 16 + 4" :cy="Math.floor(n / 3) * 16 + 4" r="2" />
                </svg>
                <div class="pointer-events-none absolute bottom-16 left-4 hidden h-12 w-12 rounded-full border-4 lg:block" :class="isDark ? 'border-white/15' : 'border-sky-200'"></div>
                <div class="pointer-events-none absolute -right-10 bottom-10 hidden h-20 w-20 rounded-full border-4 lg:block" :class="isDark ? 'border-white/10' : 'border-sky-100'"></div>
                </div>

                <div class="relative mx-auto grid max-w-6xl items-center gap-8 lg:grid-cols-2 lg:pl-10">
                    <!-- Copy + highlights -->
                    <div class="text-center lg:text-left">
                        <p data-reveal class="mb-3 inline-flex items-center rounded-full px-3 py-1 text-[10px] font-bold uppercase tracking-widest" :class="isDark ? 'bg-sky-400/15 text-sky-300' : 'bg-sky-100 text-[#0284C7]'">
                            Class Scheduling
                        </p>
                        <h2 data-reveal class="text-2xl font-extrabold leading-tight sm:text-3xl lg:text-4xl" style="font-family: 'Baloo 2', 'Inter', sans-serif; --reveal-delay: 80ms;">
                            <span :class="isDark ? 'text-white' : 'text-[#0F172A]'">Smart Scheduling</span><br />
                            <span class="text-[#0284C7]">for a Better Tomorrow</span>
                        </h2>
                        <p data-reveal class="mx-auto mt-3 max-w-md text-sm leading-relaxed lg:mx-0" style="--reveal-delay: 160ms;" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                            Generate class schedules automatically, avoid conflicts in real time, and manage
                            rooms, faculty, and assignments with ease — all in one system.
                        </p>

                        <div class="mt-6 grid grid-cols-2 gap-x-4 gap-y-5 text-left sm:grid-cols-4">
                            <div v-for="(item, i) in aboutHighlights" :key="item.title" data-reveal :style="{ '--reveal-delay': 240 + i * 90 + 'ms' }">
                                <div class="mb-2 flex h-9 w-9 items-center justify-center rounded-xl text-base" :class="isDark ? 'bg-sky-400/15 text-sky-300' : 'bg-sky-100 text-[#0284C7]'">
                                    <i :class="item.icon"></i>
                                </div>
                                <h3 class="text-xs font-bold" :class="isDark ? 'text-white' : 'text-[#0F172A]'">{{ item.title }}</h3>
                                <p class="mt-1 text-[11px] leading-relaxed" :class="isDark ? 'text-slate-400' : 'text-slate-500'">{{ item.text }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- App preview -->
                    <div data-reveal="scale" class="relative mx-auto w-full max-w-md pb-6 pt-8 lg:max-w-md" style="--reveal-delay: 150ms;">
                        <div class="absolute inset-x-2 inset-y-4 rounded-[3rem] blur-2xl" :class="isDark ? 'bg-sky-500/10' : 'bg-sky-200/60'"></div>

                        <div class="relative lg:[transform:perspective(1600px)_rotateY(-9deg)_rotateX(3deg)_rotate(1.5deg)]">
                            <div class="flex overflow-hidden rounded-2xl shadow-2xl ring-1" :class="isDark ? 'bg-[#111C30] ring-white/10' : 'bg-white ring-slate-200'">
                                <!-- sidebar -->
                                <div class="hidden w-24 shrink-0 flex-col gap-0.5 bg-[#0B3D91] px-2 py-3 text-white sm:flex">
                                    <div class="mb-2 px-2 text-base"><i class="pi pi-graduation-cap"></i></div>
                                    <div
                                        v-for="m in previewMenu"
                                        :key="m.label"
                                        class="flex items-center gap-1.5 rounded-lg px-1.5 py-1.5 text-[8px] font-medium"
                                        :class="m.active ? 'bg-sky-500 text-white' : 'text-white/80'"
                                    >
                                        <i :class="m.icon" class="text-[9px]"></i>{{ m.label }}
                                    </div>
                                </div>

                                <!-- schedule -->
                                <div class="min-w-0 flex-1 p-3">
                                    <div class="mb-2 flex items-center justify-between gap-2">
                                        <div class="flex items-center gap-1.5 text-xs font-bold sm:text-sm" :class="isDark ? 'text-white' : 'text-[#0F172A]'">
                                            <i class="pi pi-calendar text-[#0284C7]"></i>Class Schedule
                                        </div>
                                        <span class="rounded-md border px-1.5 py-0.5 text-[7px]" :class="isDark ? 'border-white/15 text-slate-300' : 'border-slate-200 text-slate-500'">1st Semester 2025–2026</span>
                                    </div>

                                    <div class="grid text-[7px] sm:text-[8px]" style="grid-template-columns: 2.8rem repeat(5, minmax(0, 1fr));">
                                        <div></div>
                                        <div v-for="day in previewDays" :key="day" class="pb-1.5 text-center font-semibold" :class="isDark ? 'text-slate-300' : 'text-slate-600'">{{ day }}</div>

                                        <template v-for="(slot, si) in previewSlots" :key="slot">
                                            <div class="flex h-7 items-center border-t pr-1 sm:h-8" :class="isDark ? 'border-white/10 text-slate-400' : 'border-slate-100 text-slate-500'">{{ slot }}</div>
                                            <div
                                                v-for="(day, di) in previewDays"
                                                :key="slot + day"
                                                class="h-7 border-l border-t p-0.5 sm:h-8"
                                                :class="isDark ? 'border-white/10' : 'border-slate-100'"
                                            >
                                                <div v-if="chipAt(di, si)" class="flex h-full flex-col justify-center rounded-md px-1 leading-tight" :class="chipClass(chipAt(di, si).c)">
                                                    <span class="font-bold">{{ chipAt(di, si).code }}</span>
                                                    <span class="opacity-80">{{ chipAt(di, si).room }}</span>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- floating: conflict -->
                        <div data-reveal class="absolute right-0 top-0 flex w-52 items-start gap-2 rounded-2xl p-2.5 shadow-xl backdrop-blur-xl sm:-right-2" style="--reveal-delay: 450ms;" :class="isDark ? 'bg-[#1B2740]/95' : 'bg-white/95'">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-rose-100 text-sm text-rose-500"><i class="pi pi-exclamation-triangle"></i></span>
                            <div>
                                <p class="text-[11px] font-bold" :class="isDark ? 'text-white' : 'text-[#0F172A]'">Schedule Conflict Detected</p>
                                <p class="mt-0.5 text-[9px] leading-snug" :class="isDark ? 'text-slate-400' : 'text-slate-500'">MATH 101 conflicts with SCI 101 on Room 203 (Tue 8:00–9:00).</p>
                            </div>
                        </div>

                        <!-- floating: generated -->
                        <div data-reveal class="absolute bottom-0 left-0 flex w-48 items-center gap-2 rounded-2xl p-2.5 shadow-xl backdrop-blur-xl sm:-left-4" style="--reveal-delay: 600ms;" :class="isDark ? 'bg-[#1B2740]/95' : 'bg-white/95'">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-sm text-white"><i class="pi pi-check"></i></span>
                            <div>
                                <p class="text-[11px] font-bold" :class="isDark ? 'text-white' : 'text-[#0F172A]'">Schedule Generated</p>
                                <p class="text-[9px]" :class="isDark ? 'text-slate-400' : 'text-slate-500'">No conflicts found.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Wave divider: white → powder blue (tall, organic blob-style wave) -->
            <svg class="relative z-10 -mb-1 block w-full" viewBox="0 0 1440 220" preserveAspectRatio="none" style="height: 160px;">
                <path
                    :fill="isDark ? '#0C2340' : '#CFE8FD'"
                    d="M0,120 C160,180 320,60 480,90 C640,120 720,30 880,60 C1040,90 1120,160 1280,130 C1360,115 1400,95 1440,105 L1440,220 L0,220 Z"
                />
            </svg>

            <!-- ============ FOOTER: FEATURES (frosted glass cards, flip on click) ============ -->
            <footer
                id="features"
                data-section="features"
                data-parallax
                class="relative overflow-hidden bg-gradient-to-b px-6 pb-16 pt-12 lg:px-10"
                :class="isDark ? 'from-[#0C2340] via-[#0B1B33] to-[#0B1220]' : 'from-[#CFE8FD] via-[#BDE0FC] to-[#E3F2FE]'"
            >
                <!-- faded campus photo on the right, washed out so the cards stay readable -->
                <div class="pointer-events-none absolute inset-0">
                    <img :src="isDark ? '/bg-dark.png' : '/bg.png'" alt="" aria-hidden="true" class="h-full w-full object-cover object-right blur-[1px]" :class="isDark ? 'opacity-20' : 'opacity-30'" />
                    <div
                        class="absolute inset-0 bg-gradient-to-r"
                        :class="isDark ? 'from-[#0B1220]/90 via-[#0B1220]/70 to-transparent' : 'from-[#CFE8FD]/90 via-[#CFE8FD]/60 to-transparent'"
                    ></div>
                </div>

                <!-- decorative dots, rings and bubbles -->
                <div class="pointer-events-none absolute inset-0" data-parallax-layer="-0.06">
                <svg class="pointer-events-none absolute left-[18%] top-16 hidden h-14 w-14 opacity-50 lg:block" :class="isDark ? 'text-white' : 'text-[#0A6CF0]'" viewBox="0 0 60 60" fill="currentColor">
                    <circle v-for="n in 16" :key="'fl-' + n" :cx="(n % 4) * 16 + 4" :cy="Math.floor(n / 4) * 16 + 4" r="2" />
                </svg>
                <svg class="pointer-events-none absolute bottom-24 right-8 hidden h-14 w-14 opacity-50 lg:block" :class="isDark ? 'text-white' : 'text-[#0A6CF0]'" viewBox="0 0 60 60" fill="currentColor">
                    <circle v-for="n in 16" :key="'fr-' + n" :cx="(n % 4) * 16 + 4" :cy="Math.floor(n / 4) * 16 + 4" r="2" />
                </svg>
                <div class="pointer-events-none absolute left-[5%] top-10 hidden h-20 w-20 rounded-full border lg:block" :class="isDark ? 'border-white/20' : 'border-white/90'"></div>
                <div class="pointer-events-none absolute left-[3%] top-24 hidden h-10 w-10 rounded-full lg:block" :class="isDark ? 'bg-white/10' : 'bg-white/70'"></div>
                <div class="pointer-events-none absolute bottom-40 right-[3%] hidden h-12 w-12 rounded-full lg:block" :class="isDark ? 'bg-white/10' : 'bg-white/70'"></div>
                </div>

                <!-- soft wave along the bottom -->
                <svg class="pointer-events-none absolute bottom-0 left-0 block w-full" viewBox="0 0 1440 200" preserveAspectRatio="none" style="height: 140px;">
                    <path :fill="isDark ? 'rgba(2,132,199,0.18)' : 'rgba(56,189,248,0.35)'" d="M0,90 C200,40 380,60 560,100 C760,145 960,120 1160,70 C1280,45 1380,50 1440,70 L1440,200 L0,200 Z" />
                    <path :fill="isDark ? 'rgba(2,132,199,0.28)' : 'rgba(14,165,233,0.35)'" d="M0,140 C220,100 420,110 640,140 C860,170 1100,150 1440,110 L1440,200 L0,200 Z" />
                </svg>

                <!-- heading -->
                <div class="relative z-10 mx-auto mb-10 max-w-2xl text-center">
                    <p
                        data-reveal
                        class="mb-4 inline-flex items-center gap-4 rounded-full border px-5 py-1.5 text-[11px] font-bold uppercase tracking-widest"
                        :class="isDark ? 'border-sky-400/50 bg-white/5 text-sky-200' : 'border-[#0A6CF0]/60 bg-white/50 text-[#0B3D91]'"
                    >
                        <span class="h-1.5 w-1.5 rounded-full bg-[#0A6CF0]"></span>
                        Why Choose CLASSLY
                        <span class="h-1.5 w-1.5 rounded-full bg-[#0A6CF0]"></span>
                    </p>
                    <div data-reveal class="flex items-center justify-center gap-4" style="--reveal-delay: 80ms;">
                        <svg class="hidden h-10 w-10 text-sky-400 sm:block" viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round">
                            <path d="M12 6 L20 14" />
                            <path d="M6 30 L17 27" />
                        </svg>
                        <h2 class="text-4xl font-extrabold sm:text-5xl" style="font-family: 'Baloo 2', 'Inter', sans-serif;">
                            <span :class="isDark ? 'text-white' : 'text-[#0B2A6F]'">Our</span>
                            <span :class="isDark ? 'text-sky-400' : 'text-[#0A7CF0]'">Features</span>
                        </h2>
                        <svg class="hidden h-10 w-10 -scale-x-100 text-sky-400 sm:block" viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round">
                            <path d="M12 6 L20 14" />
                            <path d="M6 30 L17 27" />
                        </svg>
                    </div>
                    <p data-reveal class="mt-3 text-sm sm:text-base" style="--reveal-delay: 160ms;" :class="isDark ? 'text-slate-300' : 'text-[#1E3A5F]'">
                        Everything the registrar's office needs to plan a conflict-free term, in one place.
                        Tap a card to see the details.
                    </p>
                </div>

                <!-- cards -->
                <div class="relative z-10 mx-auto grid max-w-7xl gap-6 sm:grid-cols-2 lg:grid-cols-4">
                  <!-- Wrapper carries the scroll reveal so the card's own hover-scale / flip transforms stay untouched. -->
                  <div v-for="(feature, index) in features" :key="feature.title" data-reveal :style="{ '--reveal-delay': index * 100 + 'ms' }">
                    <div
                        class="feature-flip-scene relative cursor-pointer select-none overflow-hidden text-left"
                        role="button"
                        tabindex="0"
                        :aria-pressed="!!flipped[feature.title]"
                        @click="onFeatureCardClick($event, feature.title)"
                        @keydown.enter.prevent="toggleFlip(feature.title)"
                        @keydown.space.prevent="toggleFlip(feature.title)"
                    >
                        <div class="feature-flip-card" :class="{ 'is-flipped': flipped[feature.title] }">
                            <!-- Front face -->
                            <div class="feature-flip-face feature-flip-face--front">
                                <div class="relative flex h-full flex-col items-start rounded-3xl border p-6 backdrop-blur-xl" :class="cardSurface">
                                    <span class="absolute right-5 top-4 text-3xl font-extrabold" :class="isDark ? 'text-white/20' : 'text-sky-300/80'">{{ String(index + 1).padStart(2, '0') }}</span>
                                    <div
                                        class="mb-5 flex h-16 w-16 items-center justify-center rounded-2xl shadow-md"
                                        :style="{ background: `linear-gradient(135deg, ${feature.accent}33, ${feature.accent}14)`, color: feature.accent }"
                                    >
                                        <i :class="feature.icon" class="text-3xl"></i>
                                    </div>
                                    <h3 class="mb-1 text-xl font-bold" :class="isDark ? 'text-white' : 'text-[#0B2A6F]'">{{ feature.title }}</h3>
                                    <p class="text-sm leading-relaxed" :class="isDark ? 'text-slate-300' : 'text-slate-600'">{{ feature.description }}</p>
                                    <span class="mt-4 flex items-center gap-1.5 text-xs font-semibold" :class="isDark ? 'text-sky-300' : 'text-[#0A6CF0]'">
                                        Tap to learn more
                                        <i class="pi pi-arrow-up-right text-[10px]"></i>
                                    </span>
                                    <span class="absolute bottom-0 left-1/2 h-1 w-24 -translate-x-1/2 rounded-full" :style="{ background: feature.accent }"></span>
                                </div>
                            </div>

                            <!-- Back face -->
                            <div class="feature-flip-face feature-flip-face--back">
                                <div class="relative flex h-full flex-col rounded-3xl border p-6 text-left backdrop-blur-xl" :class="cardSurface">
                                    <h3 class="mb-3 text-xl font-bold" :class="isDark ? 'text-white' : 'text-[#0B2A6F]'">{{ feature.title }}</h3>
                                    <ul class="flex flex-col gap-2">
                                        <li v-for="detail in feature.details" :key="detail" class="flex items-start gap-2 text-sm" :class="isDark ? 'text-slate-300' : 'text-slate-600'">
                                            <i class="pi pi-check-circle mt-0.5 shrink-0" :style="{ color: feature.accent }"></i>
                                            <span>{{ detail }}</span>
                                        </li>
                                    </ul>
                                    <span class="absolute bottom-0 left-1/2 h-1 w-24 -translate-x-1/2 rounded-full" :style="{ background: feature.accent }"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                  </div>
                </div>

                <!-- login button -->
                <div data-reveal class="relative z-10 mx-auto mt-10 max-w-md text-center">
                    <Link
                        :href="route('login')"
                        class="inline-flex items-center gap-4 rounded-full bg-gradient-to-r from-[#0A6CF0] to-[#1D9BF0] py-3 pl-8 pr-3 font-semibold text-white shadow-xl shadow-blue-500/40 ring-4 ring-white/60 transition hover:brightness-110"
                    >
                        Login to CLASSLY
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-white/20"><i class="pi pi-arrow-right text-sm"></i></span>
                    </Link>
                </div>

                <div data-reveal="fade" class="relative z-10 mx-auto mt-12 max-w-7xl border-t pt-6 text-center text-sm" :class="isDark ? 'border-white/15 text-white/60' : 'border-[#0A6CF0]/20 text-[#1E3A5F]/70'">
                    <template v-if="schoolBranding.name">&copy; 2026 {{ schoolBranding.name }} — by CLASSLY @ DJS</template>
                    <template v-else>CLASSLY &copy; 2026 -DJS</template>
                </div>
            </footer>
        </div>
    </div>
</template>

<style>
html.hide-scrollbar {
    scrollbar-width: none;
    -ms-overflow-style: none;
}
html.hide-scrollbar::-webkit-scrollbar {
    display: none;
}
</style>    