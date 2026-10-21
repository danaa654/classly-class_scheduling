<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import FacultyWeekGrid from '@/Components/Scheduling/FacultyWeekGrid.vue';
import { useTheme } from '@/composables/useTheme';
import { useCollegeColors, collegeSoftStyle } from '@/composables/useCollegeColors';

/**
 * MY SCHEDULE — the one page a Faculty-role login can use (see
 * MyScheduleController / RestrictFacultyRole). Read-only for schedule data:
 * the faculty record is resolved on the server from the logged-in user, so
 * nothing here can be pointed at another faculty member. The ONLY thing a
 * faculty member can change here is their own profile photo.
 *
 * Layout: no sidebar (AppLayout hides it for Faculty). The page is a hero
 * banner (photo + greeting), a stat row, a "Today" panel beside a
 * week-at-a-glance strip, then the subjects as cards / table / weekly grid.
 */
const props = defineProps({
    // null when no faculty record is linked to this login.
    faculty: { type: Object, default: null },
    workload: { type: Object, default: () => ({}) },
    placements: { type: Array, default: () => [] },
    term: { type: Object, default: null },
    schedulingWindow: { type: Object, default: () => ({}) },
});

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const view = ref('cards');
const { colorFor } = useCollegeColors();

// School logo/name uploaded in Settings -> General (shared to every page).
const page = usePage();
const schoolBranding = computed(() => page.props.schoolBranding ?? { name: null, logoUrl: null });

/* ---------- formatting helpers ---------- */
const formatTime = (time) => {
    if (!time) return '—';
    const [hour, minute] = time.split(':').map(Number);
    const period = hour >= 12 ? 'PM' : 'AM';
    const displayHour = hour % 12 === 0 ? 12 : hour % 12;
    return `${displayHour}:${String(minute).padStart(2, '0')} ${period}`;
};
const toMinutes = (hhmm) => {
    const [h, m] = (hhmm || '00:00').split(':').map(Number);
    return h * 60 + (m || 0);
};
const titleCase = (s) => (s || '').toLowerCase().replace(/(^|[\s.-])([a-z])/g, (_, a, b) => a + b.toUpperCase());

// "PANTALLANO, MA. SOCORRO V." -> "Ma. Socorro" for the greeting.
const firstName = computed(() => {
    const full = props.faculty?.full_name || '';
    const afterComma = full.includes(',') ? full.split(',')[1] : full;
    const words = titleCase(afterComma.trim()).split(/\s+/).filter((w) => w.replace('.', '').length > 1 || w.endsWith('.'));
    const trimmed = words.filter((w, i) => !(i === words.length - 1 && words.length > 1 && w.replace('.', '').length === 1));
    return trimmed.join(' ') || 'there';
});
const initials = computed(() => (props.faculty?.full_name || '?').trim().charAt(0).toUpperCase());

const now = new Date();
const greeting = now.getHours() < 12 ? 'Good morning' : now.getHours() < 18 ? 'Good afternoon' : 'Good evening';
const todayShort = now.toLocaleDateString('en-US', { weekday: 'short' }); // Mon, Tue...
const todayLong = now.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });
const nowMinutes = now.getHours() * 60 + now.getMinutes();

// "Draft" is the scheduling workspace's word; to a faculty member it
// means the Registrar hasn't locked it in yet.
const statusLabel = (status) => (status === 'Scheduled' ? 'Scheduled' : 'Tentative');
const statusSeverity = (status) => (status === 'Scheduled' ? 'success' : 'warn');
const hasTentative = computed(() => props.placements.some((p) => p.status !== 'Scheduled'));
const termLabel = computed(() => (props.term ? [props.term.academic_year, props.term.semester].filter(Boolean).join(' · ') : null));

/* ---------- derived schedule data ---------- */
const WEEK = computed(() => (props.schedulingWindow?.available_days?.length ? props.schedulingWindow.available_days : ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']));

// One flat entry per (meeting x day) — drives Today, the week strip and hours.
const meetings = computed(() => {
    const out = [];
    props.placements.forEach((p) => {
        (p.schedules ?? []).forEach((s) => {
            if (!s.days || !s.start_time || !s.end_time) return;
            s.days
                .split(',')
                .map((d) => d.trim())
                .filter(Boolean)
                .forEach((day) => {
                    out.push({
                        key: `${s.id}-${day}`,
                        day,
                        start: s.start_time,
                        end: s.end_time,
                        startMin: toMinutes(s.start_time),
                        endMin: toMinutes(s.end_time),
                        code: p.subject_code,
                        title: p.subject_title,
                        section: p.section_code,
                        room: s.room_name || '—',
                        online: s.delivery_mode === 'online',
                        collegeId: p.college_id,
                    });
                });
        });
    });
    return out.sort((a, b) => a.startMin - b.startMin);
});

const todaysClasses = computed(() => meetings.value.filter((m) => m.day === todayShort));
const classState = (m) => (nowMinutes >= m.endMin ? 'done' : nowMinutes >= m.startMin ? 'live' : 'upcoming');

const weekHours = computed(() => {
    const mins = meetings.value.reduce((sum, m) => sum + Math.max(0, m.endMin - m.startMin), 0);
    return Math.round((mins / 60) * 10) / 10;
});

const week = computed(() =>
    WEEK.value.map((day) => ({
        day,
        isToday: day === todayShort,
        items: meetings.value.filter((m) => m.day === day),
    })),
);

/* ---------- load ring ---------- */
const loadBarColor = computed(() => {
    switch (props.workload?.status) {
        case 'overloaded':
            return '#ef4444';
        case 'high':
            return '#f59e0b';
        default:
            return '#10b981';
    }
});
const RING_R = 34;
const RING_C = 2 * Math.PI * RING_R;
const ringOffset = computed(() => RING_C * (1 - Math.min(props.workload?.percent ?? 0, 100) / 100));

/* ---------- profile photo (faculty can only change their own) ---------- */
const photoInput = ref(null);
const photoError = ref('');
const uploading = ref(false);

const pickPhoto = () => photoInput.value?.click();
const onPhotoChosen = (event) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    photoError.value = '';
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
        photoError.value = 'Please choose a JPG, PNG or WebP image.';
        return;
    }
    if (file.size > 2 * 1024 * 1024) {
        photoError.value = 'That photo is over 2 MB. Please choose a smaller one.';
        return;
    }
    uploading.value = true;
    router.post(
        route('my-schedule.photo.update'),
        { photo: file },
        {
            forceFormData: true,
            preserveScroll: true,
            onError: (errors) => {
                photoError.value = errors.photo || 'Upload failed. Please try again.';
            },
            onFinish: () => {
                uploading.value = false;
            },
        },
    );
};
const removePhoto = () => {
    photoError.value = '';
    router.delete(route('my-schedule.photo.destroy'), { preserveScroll: true });
};

/* ---------- welcome-page backdrop ---------- */
// Same look as the Welcome page hero: campus photo under a sky-blue wash,
// soft rings/dots, drifting fireflies and a wave at the bottom. Fixed behind
// the page, so content scrolls over it. Randomized per load like Welcome.
const fireflies = Array.from({ length: 22 }, (_, i) => ({
    id: i,
    left: Math.random() * 100,
    top: Math.random() * 100,
    size: 2 + Math.random() * 3,
    duration: 7 + Math.random() * 8,
    delay: -Math.random() * 12,
    drift: (Math.random() > 0.5 ? 1 : -1) * (20 + Math.random() * 40),
}));

/* ---------- hover spotlight ---------- */
// One delegated handler: tells the hovered card where the cursor is, so its
// CSS spotlight (see .fx-card / .fx-lift below) can follow the pointer.
const onFxMove = (event) => {
    const card = event.target.closest?.('.fx-card, .fx-lift');
    if (!card) return;
    const rect = card.getBoundingClientRect();
    card.style.setProperty('--mx', `${event.clientX - rect.left}px`);
    card.style.setProperty('--my', `${event.clientY - rect.top}px`);
};

/* ---------- print my schedule ---------- */
// Prints ONLY what the faculty is looking at: Weekly grid -> the colored
// timetable, Table -> the subject table, Cards -> the subject cards. Built as
// a clean print-only page in a hidden iframe (always light, so dark mode
// doesn't waste ink) and sent to the browser's print dialog. Uses only data
// this page already has, so she can only ever print her own schedule.
const escapeHtml = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const minutesLabel = (min) => formatTime(`${Math.floor(min / 60)}:${min % 60}`);
const cssOf = (style) =>
    Object.entries(style)
        .map(([k, v]) => `${k.replace(/[A-Z]/g, (ch) => `-${ch.toLowerCase()}`)}:${v}`)
        .join(';');
// Same College Colors as the on-screen grid, always in the light (print) palette.
const softCss = (collegeId) => cssOf(collegeSoftStyle(colorFor(collegeId), false));

const buildGridHtml = () => {
    const days = WEEK.value;
    const step = props.schedulingWindow?.interval_minutes || 30;
    const inWeek = meetings.value.filter((m) => days.includes(m.day));
    const windowStart = toMinutes(props.schedulingWindow?.start_time || '07:00');
    const windowEnd = toMinutes(props.schedulingWindow?.end_time || '17:00');
    const gridStart = Math.floor(Math.min(windowStart, ...inWeek.map((m) => m.startMin)) / step) * step;
    const gridEnd = Math.ceil(Math.max(windowEnd, ...inWeek.map((m) => m.endMin)) / step) * step;
    const rowCount = Math.max(1, Math.round((gridEnd - gridStart) / step));

    // Per day: group meetings that overlap into one block so none get lost.
    const clustersByDay = days.map((day) => {
        const blocks = inWeek
            .filter((m) => m.day === day)
            .map((m) => {
                const s0 = Math.floor((m.startMin - gridStart) / step);
                return { m, s: s0, e: Math.max(Math.ceil((m.endMin - gridStart) / step), s0 + 1) };
            })
            .sort((a, b) => a.s - b.s);
        const clusters = [];
        blocks.forEach((b) => {
            const last = clusters[clusters.length - 1];
            if (last && b.s < last.e) {
                last.items.push(b);
                last.e = Math.max(last.e, b.e);
            } else {
                clusters.push({ s: b.s, e: b.e, items: [b] });
            }
        });
        return clusters;
    });

    const blockHtml = (m, extraClass, style) =>
        `<div class="blk ${extraClass}" style="${style}"><strong>${escapeHtml(m.title || m.code)}</strong><br>${escapeHtml(m.section)}<br>${escapeHtml(m.online ? 'Online' : m.room)}<br><span class="t">${escapeHtml(formatTime(m.start))} – ${escapeHtml(formatTime(m.end))}</span></div>`;

    const skip = days.map(() => 0);
    let rowsHtml = '';
    for (let r = 0; r < rowCount; r++) {
        let cells = `<th class="time">${escapeHtml(minutesLabel(gridStart + r * step))}</th>`;
        days.forEach((_, di) => {
            if (skip[di] > 0) {
                skip[di]--;
                return;
            }
            const cluster = clustersByDay[di].find((c) => c.s === r);
            if (!cluster) {
                cells += '<td></td>';
                return;
            }
            skip[di] = cluster.e - cluster.s - 1;
            const single = cluster.items.length === 1;
            const first = cluster.items[0].m;
            // One class: the cell itself takes the college color (fills the whole block).
            // Overlapping classes: each one gets its own colored box inside the cell.
            const cellStyle = single ? `${softCss(first.collegeId)}${first.online ? ';border-style:dashed' : ''}` : '';
            const inner = cluster.items.map(({ m }) => blockHtml(m, single ? '' : 'multi', single ? '' : softCss(m.collegeId))).join('');
            cells += `<td class="cls" rowspan="${cluster.e - cluster.s}" style="${cellStyle}">${inner}</td>`;
        });
        rowsHtml += `<tr>${cells}</tr>`;
    }

    return `<table class="grid">
        <thead><tr><th></th>${days.map((d) => `<th>${escapeHtml(d)}</th>`).join('')}</tr></thead>
        <tbody>${rowsHtml}</tbody>
    </table>`;
};

const scheduleOf = (p) => (p.schedules ?? []).filter((x) => x.days);
const whenOf = (x) => `${escapeHtml(x.days)} &middot; ${escapeHtml(formatTime(x.start_time))} – ${escapeHtml(formatTime(x.end_time))}`;
const whereOf = (x) => escapeHtml(x.delivery_mode === 'online' ? 'Online' : x.room_name || '—');

const buildTableHtml = () => {
    const rows = props.placements
        .map((p) => {
            const sched = scheduleOf(p);
            return `<tr>
                <td><strong>${escapeHtml(p.subject_code)}</strong><br>${escapeHtml(p.subject_title)}</td>
                <td>${escapeHtml(p.section_code)}</td>
                <td>${sched.length ? sched.map(whenOf).join('<br>') : 'Not yet scheduled'}</td>
                <td>${sched.length ? sched.map(whereOf).join('<br>') : '—'}</td>
                <td>${escapeHtml(p.load)} ${escapeHtml(props.workload?.unit_label ?? '')}</td>
                <td>${escapeHtml(statusLabel(p.status))}</td>
            </tr>`;
        })
        .join('');
    return `<table class="list">
        <thead><tr><th>Subject</th><th>Section</th><th>Schedule</th><th>Room</th><th>Load</th><th>Status</th></tr></thead>
        <tbody>${rows}</tbody>
    </table>`;
};

const buildCardsHtml = () =>
    `<div class="cards">${props.placements
        .map((p) => {
            const sched = scheduleOf(p);
            const lines = sched.length
                ? sched.map((x) => `<div class="ln">${whenOf(x)}<br><span class="rm">${whereOf(x)}</span></div>`).join('')
                : '<div class="ln">Not yet scheduled</div>';
            return `<div class="card" style="border-left-color:${p.status === 'Scheduled' ? '#10b981' : '#f59e0b'}">
                <div class="code">${escapeHtml(p.subject_code)} <span class="st">${escapeHtml(statusLabel(p.status))}</span></div>
                <div class="ttl">${escapeHtml(p.subject_title)}</div>
                <div class="chips">${escapeHtml(p.section_code)} &nbsp;|&nbsp; ${escapeHtml(p.load)} ${escapeHtml(props.workload?.unit_label ?? '')}</div>
                ${lines}
            </div>`;
        })
        .join('')}</div>`;

const printSchedule = () => {
    if (!props.faculty || !props.placements.length) return;

    const mode = view.value;
    const heading = mode === 'grid' ? 'Weekly timetable' : 'Assigned subjects';
    const body = mode === 'grid' ? buildGridHtml() : mode === 'table' ? buildTableHtml() : buildCardsHtml();

    // Absolute URL so the hidden print frame can always load the uploaded logo.
    const logoSrc = new URL(schoolBranding.value.logoUrl || '/logo.png', window.location.origin).href;
    const appLogoSrc = new URL('/logo.png', window.location.origin).href; // CLASSLY logo, right side
    const schoolName = schoolBranding.value.name || 'Professional Academy of the Philippines';
    const printedOn = new Date().toLocaleString('en-US', { dateStyle: 'long', timeStyle: 'short' });
    const meta = [props.faculty.college || 'General Education', props.faculty.employment_type, termLabel.value].filter(Boolean).map(escapeHtml).join(' &nbsp;|&nbsp; ');

    const html = `<!doctype html><html><head><meta charset="utf-8"><title>Schedule - ${escapeHtml(props.faculty.full_name)}</title>
<style>
    @page { size: A4 landscape; margin: 10mm; }
    * { box-sizing: border-box; }
    body { font-family: Arial, Helvetica, sans-serif; color: #0f172a; font-size: 11px; margin: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .head { display: flex; align-items: center; gap: 12px; border-bottom: 2px solid #1e3a8a; padding-bottom: 8px; margin-bottom: 10px; }
    .head img { height: 52px; width: 52px; object-fit: contain; }
    .head img.app { height: 44px; width: 44px; }
    .head .school { font-size: 11px; letter-spacing: .06em; text-transform: uppercase; color: #475569; }
    .head h1 { margin: 2px 0; font-size: 18px; color: #1e3a8a; }
    .head .who { font-size: 13px; font-weight: bold; }
    .head .meta { font-size: 10.5px; color: #475569; margin-top: 2px; }
    .stats { margin-left: auto; text-align: right; font-size: 10.5px; color: #475569; }
    h2 { font-size: 12px; margin: 12px 0 5px; color: #1e3a8a; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #94a3b8; padding: 3px 5px; vertical-align: top; text-align: left; }
    thead th { background: #e2e8f0; text-align: center; }
    .grid { table-layout: fixed; }
    .grid td { height: 18px; }
    .grid th.time { width: 84px; background: #f1f5f9; font-weight: normal; font-size: 9.5px; white-space: nowrap; }
    .grid td.cls { border-width: 1.5px; font-size: 9.5px; line-height: 1.3; padding: 2px 4px; }
    .grid td.cls .blk strong { font-size: 10px; }
    .blk .t { opacity: .85; }
    .blk.multi { border: 1px solid; border-radius: 3px; padding: 2px 3px; margin-bottom: 2px; }
    .list thead th { text-align: left; }
    .list tr { page-break-inside: avoid; }
    .cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
    .card { border: 1px solid #cbd5e1; border-left: 5px solid #10b981; border-radius: 6px; padding: 6px 8px; page-break-inside: avoid; }
    .card .code { font-size: 13px; font-weight: bold; }
    .card .st { float: right; font-size: 9px; font-weight: bold; color: #475569; }
    .card .ttl { color: #475569; font-size: 10px; margin-bottom: 3px; }
    .card .chips { font-size: 10px; font-weight: bold; margin-bottom: 3px; }
    .card .ln { margin-top: 3px; }
    .card .rm { color: #475569; font-size: 10px; }
    .note { margin-top: 8px; font-size: 10px; color: #475569; }
    .foot { margin-top: 10px; font-size: 9.5px; color: #64748b; display: flex; justify-content: space-between; }
</style></head><body>
    <div class="head">
        <img src="${escapeHtml(logoSrc)}" alt="">
        <div>
            <div class="school">${escapeHtml(schoolName)}</div>
            <h1>Faculty Schedule</h1>
            <div class="who">${escapeHtml(props.faculty.full_name)}</div>
            <div class="meta">${meta}</div>
        </div>
        <div class="stats">${escapeHtml(props.workload?.assigned_subjects ?? props.placements.length)} subject(s)<br>${escapeHtml(weekHours.value)} hours / week</div>
        <img class="app" src="${escapeHtml(appLogoSrc)}" alt="CLASSLY">
    </div>

    <h2>${heading}</h2>
    ${body}
    ${hasTentative.value ? '<div class="note">Subjects marked <strong>Tentative</strong> have not been locked in yet and may still change.</div>' : ''}
    <div class="foot"><span>Generated by CLASSLY</span><span>Printed on ${escapeHtml(printedOn)}</span></div>
</body></html>`;

    const frame = document.createElement('iframe');
    frame.setAttribute('aria-hidden', 'true');
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
    const previousTitle = document.title;
    const cleanup = () => {
        document.title = previousTitle;
        frame.remove();
    };
    frame.onload = () => {
        const w = frame.contentWindow;
        if (!w) return cleanup();
        document.title = `Schedule - ${props.faculty.full_name}`;
        w.onafterprint = cleanup;
        w.focus();
        w.print();
        setTimeout(() => frame.isConnected && cleanup(), 120000);
    };
    frame.srcdoc = html;
    document.body.appendChild(frame);
};

/* ---------- styling ---------- */
// Dark mode uses the same frosted dark glass as the Assigned-subjects panel so
// every card stays readable over the campus photo backdrop.
const cardClass = computed(() => (isDark.value ? 'border-white/10 bg-[#0B1220]/60 shadow-xl shadow-black/20 backdrop-blur-xl' : 'border-slate-200 bg-white'));
const mutedClass = computed(() => (isDark.value ? 'text-slate-400' : 'text-slate-500'));
</script>

<template>
    <Head title="My Schedule" />

    <AppLayout>
        <!-- BACKDROP — mirrors the Welcome page hero (see Welcome.vue) -->
        <div
            class="pointer-events-none fixed inset-0 -z-10 overflow-hidden bg-gradient-to-br"
            :class="isDark ? 'from-[#075985] via-[#0369A1] to-[#0284C7]' : 'from-[#7DD3FC] via-[#38BDF8] to-[#0284C7]'"
            aria-hidden="true"
        >
            <img :src="isDark ? '/bg-dark.png' : '/bg.png'" alt="" class="h-full w-full object-cover object-[right_bottom]" />
            <div
                class="absolute inset-0 bg-gradient-to-r"
                :class="isDark ? 'from-[#075985]/90 via-[#0369A1]/50 to-[#0B1220]/25' : 'from-[#1D9BF0]/70 via-[#38BDF8]/25 to-transparent'"
            ></div>
            <div v-if="isDark" class="absolute inset-0 bg-[#0B1220]/30"></div>

            <!-- rings, dots, diamond -->
            <div class="absolute -left-10 top-24 h-16 w-16 rounded-full border-4 border-white/30"></div>
            <div class="absolute left-16 top-16 h-10 w-10 rounded-full bg-white/15"></div>
            <div class="absolute right-[18%] top-24 h-8 w-8 rounded-full bg-white/20"></div>
            <div class="absolute bottom-40 right-[8%] h-24 w-24 rounded-full border-4 border-white/25"></div>
            <div class="absolute bottom-10 left-1/3 h-6 w-6 rotate-45 bg-white/20"></div>
            <svg class="absolute right-6 top-28 h-24 w-24 text-white/25 lg:right-16" viewBox="0 0 60 60" fill="currentColor">
                <circle v-for="n in 16" :key="n" :cx="(n % 4) * 16 + 4" :cy="Math.floor(n / 4) * 16 + 4" r="2" />
            </svg>

            <!-- fireflies: amber in dark mode, green in light mode -->
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

            <!-- wave along the bottom -->
            <svg class="absolute bottom-0 left-0 block w-full" viewBox="0 0 1440 160" preserveAspectRatio="none" style="height: clamp(70px, 11vw, 150px)">
                <path :fill="isDark ? 'rgba(11,18,32,0.45)' : 'rgba(255,255,255,0.5)'" d="M0,70 C180,10 340,30 520,70 C700,110 840,60 1020,30 C1200,0 1340,30 1440,60 L1440,160 L0,160 Z" />
                <path :fill="isDark ? '#0B1220' : '#F2F2F2'" d="M0,110 C150,60 300,70 460,100 C640,135 760,150 940,115 C1120,80 1280,60 1440,95 L1440,160 L0,160 Z" />
            </svg>
        </div>

        <div class="mx-auto w-full max-w-6xl pb-24" :class="isDark ? 'dark-scope' : ''" @pointermove="onFxMove">
            <!-- No faculty record linked to this login -->
            <div v-if="!faculty" class="rounded-2xl border p-8 text-center" :class="cardClass">
                <i class="pi pi-user-minus text-3xl" :class="mutedClass"></i>
                <h1 class="mt-3 text-lg font-semibold">No faculty profile is linked to this account</h1>
                <p class="mt-1 text-sm" :class="mutedClass">
                    Please contact the Registrar's office so they can link your account to your faculty record.
                </p>
            </div>

            <template v-else>
                <!-- HERO -->
                <section class="fx-card fx-calm relative mb-5 overflow-hidden rounded-3xl p-5 text-white shadow-xl ring-1 ring-white/30 sm:p-7" style="background: linear-gradient(120deg, #0f1b4d 0%, #1d4ed8 55%, #7c3aed 100%)">
                    <div class="pointer-events-none absolute -right-16 -top-20 h-64 w-64 rounded-full bg-emerald-400/20 blur-3xl"></div>
                    <div class="pointer-events-none absolute -bottom-24 left-1/3 h-56 w-56 rounded-full bg-sky-300/20 blur-3xl"></div>

                    <div class="relative flex flex-col items-center gap-5 text-center sm:flex-row sm:text-left">
                        <!-- Photo with upload -->
                        <div class="shrink-0">
                            <button
                                type="button"
                                class="group relative block h-28 w-28 overflow-hidden rounded-full ring-4 ring-white/30 transition hover:ring-emerald-300/70 focus:outline-none focus-visible:ring-emerald-300 sm:h-32 sm:w-32"
                                :aria-label="faculty.photo_url ? 'Change profile photo' : 'Upload profile photo'"
                                :disabled="uploading"
                                @click="pickPhoto"
                            >
                                <img v-if="faculty.photo_url" :src="faculty.photo_url" alt="Your profile photo" class="h-full w-full object-cover" />
                                <span v-else class="flex h-full w-full items-center justify-center bg-white/15 text-5xl font-bold">{{ initials }}</span>
                                <span class="absolute inset-0 flex flex-col items-center justify-center gap-1 bg-black/55 text-xs font-semibold opacity-0 transition group-hover:opacity-100 group-focus-visible:opacity-100" :class="uploading ? '!opacity-100' : ''">
                                    <i :class="uploading ? 'pi pi-spin pi-spinner' : 'pi pi-camera'" class="text-lg"></i>
                                    {{ uploading ? 'Uploading…' : faculty.photo_url ? 'Change photo' : 'Add photo' }}
                                </span>
                            </button>
                            <input ref="photoInput" type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="onPhotoChosen" />
                            <button v-if="faculty.photo_url" type="button" class="mt-2 w-full text-[11px] text-white/70 underline-offset-2 hover:text-white hover:underline" @click="removePhoto">
                                Remove photo
                            </button>
                        </div>

                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-medium uppercase tracking-widest text-white/70">{{ todayLong }}</p>
                            <h1 class="mt-1 text-2xl font-bold tracking-tight sm:text-3xl">{{ greeting }}, {{ firstName }}!</h1>
                            <p class="mt-1 truncate text-sm text-white/80">{{ faculty.full_name }}</p>
                            <div class="mt-3 flex flex-wrap justify-center gap-2 sm:justify-start">
                                <span class="rounded-full bg-white/15 px-3 py-1 text-xs font-medium backdrop-blur">{{ faculty.college || 'General Education' }}</span>
                                <span class="rounded-full bg-white/15 px-3 py-1 text-xs font-medium backdrop-blur">{{ faculty.employment_type }}</span>
                                <span v-if="termLabel" class="rounded-full bg-emerald-400/25 px-3 py-1 text-xs font-medium backdrop-blur">{{ termLabel }}</span>
                            </div>
                            <p v-if="photoError" class="mt-2 text-xs font-medium text-amber-200">{{ photoError }}</p>
                        </div>
                    </div>
                </section>

                <!-- STATS -->
                <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div class="fx-card rounded-2xl border p-4" :class="cardClass">
                        <p class="text-xs font-medium uppercase tracking-wide" :class="mutedClass">Subjects</p>
                        <p class="mt-1 text-3xl font-bold">{{ workload.assigned_subjects ?? 0 }}</p>
                        <p class="text-xs" :class="mutedClass">assigned this term</p>
                    </div>
                    <div class="fx-card rounded-2xl border p-4" :class="cardClass">
                        <p class="text-xs font-medium uppercase tracking-wide" :class="mutedClass">Hours / week</p>
                        <p class="mt-1 text-3xl font-bold">{{ weekHours }}</p>
                        <p class="text-xs" :class="mutedClass">of contact time</p>
                    </div>
                    <div class="fx-card rounded-2xl border p-4" :class="cardClass">
                        <p class="text-xs font-medium uppercase tracking-wide" :class="mutedClass">Classes today</p>
                        <p class="mt-1 text-3xl font-bold">{{ todaysClasses.length }}</p>
                        <p class="text-xs" :class="mutedClass">{{ todayShort }}</p>
                    </div>
                    <div class="fx-card flex items-center gap-3 rounded-2xl border p-4" :class="cardClass">
                        <svg viewBox="0 0 80 80" class="h-16 w-16 shrink-0 -rotate-90" aria-hidden="true">
                            <circle cx="40" cy="40" :r="RING_R" fill="none" stroke-width="9" :stroke="isDark ? 'rgba(255,255,255,0.1)' : '#e2e8f0'" />
                            <circle cx="40" cy="40" :r="RING_R" fill="none" stroke-width="9" stroke-linecap="round" :stroke="loadBarColor" :stroke-dasharray="RING_C" :stroke-dashoffset="ringOffset" style="transition: stroke-dashoffset 0.6s ease" />
                        </svg>
                        <div class="min-w-0">
                            <p class="text-xs font-medium uppercase tracking-wide" :class="mutedClass">Teaching load</p>
                            <p class="text-xl font-bold leading-tight">
                                {{ workload.current ?? 0 }}<span class="text-sm font-medium" :class="mutedClass"> / {{ workload.max ?? 0 }}</span>
                            </p>
                            <p class="text-xs" :class="mutedClass">{{ workload.unit_label }}</p>
                        </div>
                    </div>
                </div>

                <!-- TODAY + WEEK AT A GLANCE (fills the page instead of leaving it empty) -->
                <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-5">
                    <section class="fx-card rounded-2xl border p-4 lg:col-span-2" :class="cardClass">
                        <h2 class="mb-3 flex items-center gap-2 text-base font-semibold"><i class="pi pi-sun text-amber-500"></i> Today</h2>
                        <div v-if="!todaysClasses.length" class="rounded-xl border border-dashed p-6 text-center text-sm" :class="isDark ? 'border-white/15 text-slate-400' : 'border-slate-200 text-slate-400'">
                            No classes today — enjoy your day!
                        </div>
                        <ul v-else class="space-y-2">
                            <li
                                v-for="m in todaysClasses"
                                :key="m.key"
                                class="fx-lift flex items-center gap-3 rounded-xl border p-3"
                                :class="[
                                    classState(m) === 'live' ? (isDark ? 'border-emerald-400/50 bg-emerald-400/10' : 'border-emerald-300 bg-emerald-50') : (isDark ? 'border-white/10' : 'border-slate-200'),
                                    classState(m) === 'done' ? 'opacity-55' : '',
                                ]"
                            >
                                <div class="w-20 shrink-0 text-xs font-semibold leading-tight">
                                    {{ formatTime(m.start) }}<br /><span :class="mutedClass" class="font-normal">to {{ formatTime(m.end) }}</span>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-semibold">{{ m.code }} <span class="font-normal" :class="mutedClass">· {{ m.section }}</span></p>
                                    <p class="truncate text-xs" :class="mutedClass"><i :class="m.online ? 'pi pi-video' : 'pi pi-map-marker'" class="mr-1 text-[10px]"></i>{{ m.online ? 'Online' : m.room }}</p>
                                </div>
                                <span v-if="classState(m) === 'live'" class="rounded-full bg-emerald-500 px-2 py-0.5 text-[10px] font-bold uppercase text-white">Now</span>
                                <span v-else-if="classState(m) === 'done'" class="text-[10px] font-semibold uppercase" :class="mutedClass">Done</span>
                            </li>
                        </ul>
                    </section>

                    <section class="fx-card rounded-2xl border p-4 lg:col-span-3" :class="cardClass">
                        <h2 class="mb-3 flex items-center gap-2 text-base font-semibold"><i class="pi pi-calendar text-blue-500"></i> My week</h2>
                        <div class="grid gap-2" :style="{ gridTemplateColumns: `repeat(${week.length}, minmax(0, 1fr))` }">
                            <div
                                v-for="d in week"
                                :key="d.day"
                                class="fx-lift flex min-h-[8.5rem] flex-col rounded-xl border p-2"
                                :class="d.isToday ? (isDark ? 'border-emerald-400/60 bg-emerald-400/10' : 'border-emerald-300 bg-emerald-50') : (isDark ? 'border-white/10' : 'border-slate-200')"
                            >
                                <p class="text-center text-[11px] font-bold uppercase tracking-wide" :class="d.isToday ? 'text-emerald-600' : mutedClass">{{ d.day }}</p>
                                <div class="mt-1.5 flex flex-1 flex-col gap-1">
                                    <div v-for="m in d.items" :key="m.key" class="rounded-md px-1.5 py-1 text-[10px] leading-tight" :class="isDark ? 'bg-blue-400/15' : 'bg-blue-50'" :title="`${m.code} · ${formatTime(m.start)}–${formatTime(m.end)}`">
                                        <p class="truncate font-semibold">{{ m.code }}</p>
                                        <p :class="mutedClass">{{ formatTime(m.start).replace(':00', '') }}</p>
                                    </div>
                                    <p v-if="!d.items.length" class="my-auto text-center text-[10px]" :class="mutedClass">Free</p>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

                <!-- Tentative notice -->
                <div
                    v-if="hasTentative"
                    class="mb-4 flex items-start gap-2 rounded-xl border px-4 py-3 text-sm"
                    :class="isDark ? 'border-amber-400/30 bg-amber-400/10 text-amber-200' : 'border-amber-200 bg-amber-50 text-amber-800'"
                >
                    <i class="pi pi-info-circle mt-0.5"></i>
                    <span>Subjects marked <strong>Tentative</strong> haven't been locked in yet and may still change.</span>
                </div>

                <!-- Subjects sit on a frosted-glass panel so they read cleanly over the photo -->
                <div class="fx-card fx-calm rounded-3xl border p-4 shadow-xl backdrop-blur-xl sm:p-5" :class="isDark ? 'border-white/10 bg-[#0B1220]/55 shadow-black/20' : 'border-white/80 bg-white/60 shadow-sky-500/15'">
                <!-- View toggle -->
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-base font-semibold">Assigned subjects</h2>
                    <div class="flex flex-wrap items-center justify-end gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                        :class="isDark ? 'bg-white/10 text-slate-200 hover:bg-white/20' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                        :disabled="!placements.length"
                        title="Print my schedule"
                        @click="printSchedule"
                    >
                        <i class="pi pi-print text-[12px]"></i>
                        Print
                    </button>
                    <div class="inline-flex rounded-lg p-0.5" :class="isDark ? 'bg-white/10' : 'bg-slate-100'">
                        <button
                            v-for="opt in [{ v: 'cards', l: 'Cards' }, { v: 'table', l: 'Table' }, { v: 'grid', l: 'Weekly grid' }]"
                            :key="opt.v"
                            type="button"
                            class="rounded-md px-3 py-1.5 text-xs font-semibold transition-colors"
                            :class="view === opt.v ? 'bg-emerald-500 text-white' : (isDark ? 'text-slate-400' : 'text-slate-500')"
                            @click="view = opt.v"
                        >
                            {{ opt.l }}
                        </button>
                    </div>
                    </div>
                </div>

                <div
                    v-if="!placements.length"
                    class="rounded-xl border border-dashed p-8 text-center text-sm"
                    :class="isDark ? 'border-white/15 text-slate-400' : 'border-slate-200 text-slate-400'"
                >
                    No subjects have been assigned to you for this semester yet.
                </div>

                <!-- Cards -->
                <div v-else-if="view === 'cards'" class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <article
                        v-for="p in placements"
                        :key="p.id"
                        class="fx-card relative overflow-hidden rounded-2xl border p-4 pl-5"
                        :class="cardClass"
                    >
                        <span class="absolute inset-y-0 left-0 w-1.5" :class="p.status === 'Scheduled' ? 'bg-emerald-500' : 'bg-amber-400'"></span>
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-lg font-bold leading-tight">{{ p.subject_code }}</p>
                                <p class="truncate text-xs" :class="mutedClass">{{ p.subject_title }}</p>
                            </div>
                            <Tag :value="statusLabel(p.status)" :severity="statusSeverity(p.status)" />
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                            <span class="rounded-md px-2 py-0.5 font-semibold" :class="isDark ? 'bg-white/10' : 'bg-slate-100'">{{ p.section_code }}</span>
                            <span class="rounded-md px-2 py-0.5 font-semibold" :class="isDark ? 'bg-white/10' : 'bg-slate-100'">{{ p.load }} {{ workload.unit_label }}</span>
                        </div>
                        <ul class="mt-3 space-y-1.5 text-sm">
                            <li v-for="s in p.schedules" :key="s.id" class="flex items-start gap-2">
                                <i class="pi pi-clock mt-1 text-[11px]" :class="mutedClass"></i>
                                <span v-if="s.days">
                                    {{ s.days }} · {{ formatTime(s.start_time) }}–{{ formatTime(s.end_time) }}
                                    <span class="block text-xs" :class="mutedClass"><i :class="s.delivery_mode === 'online' ? 'pi pi-video' : 'pi pi-map-marker'" class="mr-1 text-[10px]"></i>{{ s.delivery_mode === 'online' ? 'Online' : (s.room_name || '—') }}</span>
                                    <span v-if="s.shared_with_section" class="mt-0.5 inline-block rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700">Shared with {{ s.shared_with_section }}</span>
                                </span>
                                <span v-else :class="mutedClass">Not yet scheduled</span>
                            </li>
                        </ul>
                    </article>
                </div>

                <!-- Table -->
                <DataTable
                    v-else-if="view === 'table'"
                    :value="placements"
                    dataKey="id"
                    stripedRows
                    responsiveLayout="scroll"
                    class="overflow-hidden rounded-xl"
                    :class="isDark ? 'sched-table-dark' : 'neu-inset neu-table'"
                >
                    <Column field="subject_code" header="Subject" style="min-width: 12rem">
                        <template #body="{ data }">
                            <div class="font-medium">{{ data.subject_code }}</div>
                            <div class="text-xs" :class="mutedClass">{{ data.subject_title }}</div>
                        </template>
                    </Column>
                    <Column field="section_code" header="Section" style="min-width: 8rem" />
                    <Column header="Schedule" style="min-width: 14rem">
                        <template #body="{ data }">
                            <div class="flex flex-col gap-1.5">
                                <div v-for="schedule in data.schedules" :key="schedule.id">
                                    <span v-if="schedule.days">
                                        {{ schedule.days }} &middot; {{ formatTime(schedule.start_time) }}–{{ formatTime(schedule.end_time) }}
                                        <span
                                            v-if="schedule.shared_with_section"
                                            class="ml-1 inline-block rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700"
                                        >
                                            Shared with {{ schedule.shared_with_section }}
                                        </span>
                                    </span>
                                    <span v-else :class="mutedClass">Not yet scheduled</span>
                                </div>
                            </div>
                        </template>
                    </Column>
                    <Column header="Room" style="min-width: 8rem">
                        <template #body="{ data }">
                            <div class="flex flex-col gap-1.5">
                                <div v-for="schedule in data.schedules" :key="schedule.id">{{ schedule.room_name || '—' }}</div>
                            </div>
                        </template>
                    </Column>
                    <Column header="Load" style="width: 7rem">
                        <template #body="{ data }">{{ data.load }} {{ workload.unit_label }}</template>
                    </Column>
                    <Column header="Status" style="width: 8rem">
                        <template #body="{ data }">
                            <Tag :value="statusLabel(data.status)" :severity="statusSeverity(data.status)" />
                        </template>
                    </Column>
                </DataTable>

                <FacultyWeekGrid
                    v-else
                    :placements="placements"
                    :scheduling-window="schedulingWindow"
                    :is-dark="isDark"
                />
                </div>
            </template>

            <!-- Footer (same wording as the Welcome page), on a frosted pill so it reads over the photo -->
            <div class="mt-8 flex justify-center">
                <p
                    class="rounded-full border px-4 py-1.5 text-center text-xs backdrop-blur-md"
                    :class="isDark ? 'border-white/10 bg-[#0B1220]/55 text-white/70' : 'border-white/80 bg-white/70 text-slate-600'"
                >
                    <template v-if="schoolBranding.name">&copy; 2026 {{ schoolBranding.name }} — by CLASSLY @ DJS</template>
                    <template v-else>CLASSLY &copy; 2026 - DJS</template>
                </p>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Dark-mode table for My Schedule. (app.css's .neu-table-dark uses :deep(),
   which only works inside a scoped block, so it never applied — the table
   stayed white. These rules live here so they actually take effect.) */
.sched-table-dark {
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.1);
}
.sched-table-dark :deep(.p-datatable-table-container),
.sched-table-dark :deep(.p-datatable-table),
.sched-table-dark :deep(.p-datatable-tbody > tr),
.sched-table-dark :deep(.p-datatable-tbody > tr > td) {
    background: transparent !important;
    color: #e2e8f0 !important;
}
.sched-table-dark :deep(.p-datatable-thead > tr > th) {
    background: rgba(255, 255, 255, 0.07) !important;
    color: #cbd5e1 !important;
    border-color: rgba(255, 255, 255, 0.1) !important;
}
.sched-table-dark :deep(.p-datatable-tbody > tr > td) {
    border-color: rgba(255, 255, 255, 0.08) !important;
}
.sched-table-dark :deep(.p-datatable-tbody > tr:nth-child(even)) {
    background: rgba(255, 255, 255, 0.045) !important;
}
.sched-table-dark :deep(.p-datatable-tbody > tr:hover) {
    background: rgba(255, 255, 255, 0.09) !important;
}

/* ===== Card effects =====
   .fx-card : a light that travels around the card's border + hover lift,
              glow and a spotlight that follows the cursor.
   .fx-lift : hover lift + cursor spotlight only (small inner tiles). */
@property --fx-angle {
    syntax: '<angle>';
    inherits: false;
    initial-value: 0deg;
}
@keyframes fx-spin {
    to {
        --fx-angle: 360deg;
    }
}
.fx-card,
.fx-lift {
    position: relative;
    --mx: 50%;
    --my: 50%;
    transition: transform 0.25s ease, box-shadow 0.25s ease;
}
.fx-card:hover,
.fx-lift:hover {
    transform: translateY(-3px);
}
.fx-card.fx-calm:hover {
    transform: none;
}
.fx-card:hover {
    box-shadow: 0 14px 34px -10px rgba(56, 189, 248, 0.55), 0 0 0 1px rgba(125, 211, 252, 0.35);
}
.fx-lift:hover {
    box-shadow: 0 8px 20px -8px rgba(56, 189, 248, 0.5);
}

/* travelling border light */
.fx-card::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    padding: 2px;
    background: conic-gradient(from var(--fx-angle), transparent 0 62%, rgba(56, 189, 248, 0.95) 80%, rgba(167, 139, 250, 0.95) 90%, #fff 96%, transparent 100%);
    -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
    -webkit-mask-composite: xor;
    mask: linear-gradient(#000 0 0) content-box exclude, linear-gradient(#000 0 0);
    animation: fx-spin 5s linear infinite;
    pointer-events: none;
    opacity: 0.85;
    transition: opacity 0.25s ease;
}
.fx-card:hover::before {
    opacity: 1;
}

/* cursor spotlight */
.fx-card::after,
.fx-lift::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    background: radial-gradient(240px circle at var(--mx) var(--my), rgba(125, 211, 252, 0.2), transparent 65%);
    opacity: 0;
    transition: opacity 0.25s ease;
    pointer-events: none;
}
.fx-card:hover::after,
.fx-lift:hover::after {
    opacity: 1;
}

@media (prefers-reduced-motion: reduce) {
    .fx-card::before {
        animation: none;
    }
    .fx-card:hover,
    .fx-lift:hover {
        transform: none;
    }
}
</style>