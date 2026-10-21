import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * College colors — one hex color per College, set by the Admin/Registrar in
 * Settings -> College Colors and shared to every page as `collegeColors`
 * ({ [collegeId]: '#RRGGBB' }) by HandleInertiaRequests.
 *
 * Used by Room Grid and Section Grid so a block is colored by the College
 * that owns its Section (e.g. BSIT -> CCS -> yellow), not one fixed color.
 */

// Used only when a block has no College (or the College has no color yet).
export const FALLBACK_COLLEGE_COLOR = '#64748B';

const HEX = /^#[0-9a-f]{6}$/i;

const toRgb = (hex) => {
    const h = HEX.test(hex) ? hex : FALLBACK_COLLEGE_COLOR;
    return [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16));
};

const mix = (rgb, target, t) => rgb.map((c, i) => Math.round(c * (1 - t) + target[i] * t));
const rgba = (rgb, a) => `rgba(${rgb[0]}, ${rgb[1]}, ${rgb[2]}, ${a})`;
const rgbStr = (rgb) => `rgb(${rgb[0]}, ${rgb[1]}, ${rgb[2]})`;

// Perceived brightness 0..1 — decides white vs dark text on a solid fill
// (yellow/orange need dark text; violet/blue need white).
const luminance = ([r, g, b]) => (0.299 * r + 0.587 * g + 0.114 * b) / 255;

/**
 * Soft block style (Room Grid): tinted fill, college-colored border, and a
 * darkened (light mode) / lightened (dark mode) version of the college color
 * for the text so it stays readable even on yellow.
 */
export function collegeSoftStyle(hex, isDark = false) {
    const rgb = toRgb(hex);
    if (isDark) {
        return {
            backgroundColor: rgba(rgb, 0.28),
            borderColor: rgba(rgb, 0.8),
            color: rgbStr(mix(rgb, [255, 255, 255], 0.78)),
        };
    }
    return {
        backgroundColor: rgba(rgb, 0.22),
        borderColor: rgbStr(rgb),
        color: rgbStr(mix(rgb, [0, 0, 0], 0.62)),
    };
}

/**
 * Solid block style (Section Grid): full college color with white or dark
 * text depending on how bright the color is.
 */
export function collegeSolidStyle(hex) {
    const rgb = toRgb(hex);
    return {
        backgroundColor: rgbStr(rgb),
        color: luminance(rgb) > 0.6 ? '#1F2937' : '#FFFFFF',
    };
}

export function useCollegeColors() {
    const page = usePage();
    const colors = computed(() => page.props.collegeColors ?? {});

    const colorFor = (collegeId) => {
        const hex = collegeId != null ? colors.value[collegeId] : null;
        return hex && HEX.test(hex) ? hex : FALLBACK_COLLEGE_COLOR;
    };

    return { colors, colorFor };
}