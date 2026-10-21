<script setup>
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import InputText from 'primevue/inputtext';
import Password from 'primevue/password';
import FloatLabel from 'primevue/floatlabel';
import Button from 'primevue/button';
import PasswordRequirementsChecklist from '@/Components/PasswordRequirementsChecklist.vue';
import { useCollegeColors, collegeSolidStyle } from '@/composables/useCollegeColors';

/**
 * Manage Account — the signed-in user's own profile + password.
 *
 * Shared by Settings > Manage Account (Registrar / Dean / OIC / Assistant Dean)
 * and User Management > Manage Account (Administrator), so both look and behave
 * the same. The parent owns the Inertia form and the save/photo handlers; this
 * component only renders them.
 *
 *  - form:          the parent's useForm() (first/middle/last name, suffix, email,
 *                   photo, remove_photo, password, password_confirmation)
 *  - avatarUrl:     saved photo URL or the local preview of a newly picked file
 *  - initials:      fallback when there is no photo
 *  - passwordPolicy / isDark: same values the page already has
 */
const props = defineProps({
    form: { type: Object, required: true },
    avatarUrl: { type: String, default: null },
    initials: { type: String, default: '' },
    passwordPolicy: { type: Object, default: () => ({}) },
    isDark: { type: Boolean, default: false },
});
const emit = defineEmits(['submit', 'photo-selected', 'remove-photo']);

const page = usePage();
const { colorFor } = useCollegeColors();

// Same colour language as the User Management ID cards: the user's College
// colour, or the shared "system" indigo for accounts with no College.
const SYSTEM_COLOR = '#4338ca';
const accent = computed(() => {
    const collegeId = page.props.auth?.user?.college_id;
    return collegeId != null ? colorFor(collegeId) : SYSTEM_COLOR;
});
const onAccent = computed(() => collegeSolidStyle(accent.value).color);

const roles = computed(() => page.props.auth?.roles ?? []);
// School logo + name (Settings -> school branding), shared on every page.
const school = computed(() => page.props.schoolBranding ?? { name: null, logoUrl: null });
const logoBroken = ref(false);

const titleCase = (value) =>
    (value ?? '')
        .toLowerCase()
        .replace(/(^|[\s\-'(])([a-zà-ÿ])/g, (m, lead, ch) => lead + ch.toUpperCase())
        .replace(/\b(Ii|Iii|Iv)\b/g, (m) => m.toUpperCase());

const displayName = computed(() =>
    titleCase([props.form.first_name, props.form.middle_name, props.form.last_name, props.form.suffix].filter(Boolean).join(' ')),
);

// Same requirements the checklist beside the field shows (policy comes from the
// server), counted here so a segmented meter can fill as each one is met.
const pwChecks = computed(() => {
    const pwd = props.form.password ?? '';
    const policy = props.passwordPolicy ?? {};
    const list = [pwd.length >= (policy.minLength ?? 8)];
    if (policy.requireUppercase) list.push(/[A-Z]/.test(pwd));
    if (policy.requireNumber) list.push(/[0-9]/.test(pwd));
    if (policy.requireSymbol) list.push(/[^A-Za-z0-9]/.test(pwd));
    return list;
});
const pwMet = computed(() => pwChecks.value.filter(Boolean).length);
const pwConfirmState = computed(() => {
    const { password, password_confirmation: confirm } = props.form;
    if (!confirm) return null;
    return password === confirm ? 'match' : 'differ';
});

const photoInput = ref(null);
const pickPhoto = () => photoInput.value?.click();
const onPick = (event) => emit('photo-selected', event);
const onRemove = () => {
    if (photoInput.value) photoInput.value.value = '';
    emit('remove-photo');
};
</script>

<template>
    <form
        class="acct neu-form"
        :class="{ 'is-dark': isDark }"
        :style="{ '--accent': accent, '--on': onAccent }"
        autocomplete="off"
        @submit.prevent="emit('submit')"
    >
        <!-- Profile card -->
        <aside class="acct-id">
            <div class="acct-id-band" aria-hidden="true"><span class="acct-id-slot"></span></div>

            <div class="acct-photo">
                <img v-if="avatarUrl" :src="avatarUrl" class="acct-photo-img" alt="Your profile photo" />
                <span v-else class="acct-photo-initials" aria-hidden="true">{{ initials || '?' }}</span>
                <button type="button" class="acct-photo-cam" aria-label="Upload a new profile photo" @click="pickPhoto">
                    <i class="pi pi-camera"></i>
                </button>
            </div>

            <h2 class="acct-name">{{ displayName || 'Your name' }}</h2>
            <p class="acct-email">{{ form.email }}</p>

            <div v-if="roles.length" class="acct-roles">
                <span v-for="role in roles" :key="role" class="acct-role">{{ role }}</span>
            </div>

            <input ref="photoInput" type="file" accept="image/*" class="hidden" @change="onPick" />
            <div class="acct-photo-actions">
                <Button type="button" label="Upload Photo" icon="pi pi-camera" size="small" outlined @click="pickPhoto" />
                <Button v-if="avatarUrl" type="button" label="Remove" icon="pi pi-trash" size="small" text severity="danger" @click="onRemove" />
            </div>
            <p class="acct-hint">JPG or PNG, up to 2MB.</p>
            <small v-if="form.errors.photo" class="acct-error">{{ form.errors.photo }}</small>

            <!-- School seal: fills the rest of the card so it matches the height of the form beside it -->
            <div v-if="school.logoUrl || school.name" class="acct-school">
                <div v-if="school.logoUrl && !logoBroken" class="acct-school-logo">
                    <img :src="school.logoUrl" :alt="school.name ? `${school.name} logo` : 'School logo'" @error="logoBroken = true" />
                </div>
                <p v-if="school.name" class="acct-school-name">{{ school.name }}</p>
                <span class="acct-school-stripe" aria-hidden="true"></span>
            </div>
        </aside>

        <!-- Fields -->
        <div class="acct-main">
            <section class="acct-sec">
                <header class="acct-sec-head">
                    <span class="acct-sec-icon" aria-hidden="true"><i class="pi pi-user"></i></span>
                    <div class="acct-sec-title">
                        <h3>Profile details</h3>
                        <p>Your name and the email you sign in with.</p>
                    </div>
                    <span class="acct-sec-note">* required</span>
                </header>

                <div class="acct-sec-body">
                    <div class="acct-group">
                        <p class="acct-group-label"><i class="pi pi-id-card"></i>Full name</p>
                        <div class="acct-grid">
                            <FloatLabel variant="on">
                                <InputText v-uppercase id="accFirstName" size="large" v-model="form.first_name" class="w-full" autocomplete="off" :invalid="!!form.errors.first_name" />
                                <label for="accFirstName">First Name *</label>
                            </FloatLabel>
                            <FloatLabel variant="on">
                                <InputText v-uppercase id="accMiddleName" size="large" v-model="form.middle_name" class="w-full" autocomplete="off" />
                                <label for="accMiddleName">Middle Name</label>
                            </FloatLabel>
                            <FloatLabel variant="on">
                                <InputText v-uppercase id="accLastName" size="large" v-model="form.last_name" class="w-full" autocomplete="off" :invalid="!!form.errors.last_name" />
                                <label for="accLastName">Last Name *</label>
                            </FloatLabel>
                            <FloatLabel variant="on">
                                <InputText v-uppercase id="accSuffix" size="large" v-model="form.suffix" class="w-full" autocomplete="off" />
                                <label for="accSuffix">Suffix</label>
                            </FloatLabel>
                        </div>
                        <small v-if="form.errors.first_name" class="acct-error">{{ form.errors.first_name }}</small>
                        <small v-if="form.errors.last_name" class="acct-error">{{ form.errors.last_name }}</small>
                    </div>

                    <div class="acct-group">
                        <p class="acct-group-label"><i class="pi pi-envelope"></i>Sign-in email</p>
                        <FloatLabel variant="on">
                            <InputText id="accEmail" size="large" v-model="form.email" type="email" class="w-full" autocomplete="off" :invalid="!!form.errors.email" />
                            <label for="accEmail">Email *</label>
                        </FloatLabel>
                        <small v-if="form.errors.email" class="acct-error">{{ form.errors.email }}</small>
                    </div>
                </div>
            </section>

            <section class="acct-sec">
                <header class="acct-sec-head">
                    <span class="acct-sec-icon" aria-hidden="true"><i class="pi pi-lock"></i></span>
                    <div class="acct-sec-title">
                        <h3>Password</h3>
                        <p>Leave both fields blank to keep your current password.</p>
                    </div>
                    <span class="acct-sec-note" :class="{ 'is-live': form.password }">
                        {{ form.password ? 'Will change on save' : 'Not changing' }}
                    </span>
                </header>

                <div class="acct-sec-body">
                    <div class="acct-pw">
                        <div class="acct-pw-fields">
                            <FloatLabel variant="on">
                                <Password id="accPassword" size="large" v-model="form.password" toggleMask :feedback="true" inputClass="w-full" class="w-full" autocomplete="new-password" :invalid="!!form.errors.password" />
                                <label for="accPassword">New Password</label>
                            </FloatLabel>

                            <div v-if="form.password" class="acct-meter" role="img" :aria-label="`${pwMet} of ${pwChecks.length} password requirements met`">
                                <span class="acct-meter-bar">
                                    <i v-for="(ok, i) in pwChecks" :key="i" :class="{ 'is-on': i < pwMet }"></i>
                                </span>
                                <span class="acct-meter-label">{{ pwMet }} of {{ pwChecks.length }} requirements met</span>
                            </div>

                            <FloatLabel variant="on">
                                <Password id="accPasswordConfirm" size="large" v-model="form.password_confirmation" toggleMask :feedback="false" inputClass="w-full" class="w-full" autocomplete="new-password" :invalid="!!form.errors.password" />
                                <label for="accPasswordConfirm">Confirm New Password</label>
                            </FloatLabel>
                            <p v-if="pwConfirmState" class="acct-match" :class="pwConfirmState === 'match' ? 'is-ok' : 'is-bad'">
                                <i class="pi" :class="pwConfirmState === 'match' ? 'pi-check-circle' : 'pi-exclamation-circle'"></i>
                                {{ pwConfirmState === 'match' ? 'Passwords match' : 'Passwords don\'t match yet' }}
                            </p>
                            <small v-if="form.errors.password" class="acct-error">{{ form.errors.password }}</small>
                        </div>
                        <div class="acct-pw-rules">
                            <PasswordRequirementsChecklist :password="form.password" :policy="passwordPolicy" :is-dark="isDark" />
                        </div>
                    </div>
                </div>
            </section>

            <div class="acct-save">
                <span v-if="form.isDirty" class="acct-dirty"><i></i>You have unsaved changes</span>
                <Button type="submit" label="Save Changes" icon="pi pi-check" :loading="form.processing" :disabled="!form.isDirty" />
            </div>
        </div>
    </form>
</template>

<style scoped>
.acct {
    --accent: #4338ca;
    --on: #fff;
    display: grid;
    gap: 1.5rem;
    grid-template-columns: 19rem minmax(0, 1fr);
    align-items: stretch;
}
@media (max-width: 960px) {
    .acct { grid-template-columns: minmax(0, 1fr); }
}

/* ---- profile card ---- */
.acct-id {
    display: flex; flex-direction: column; align-items: center; text-align: center;
    padding: 0 1.25rem 1.5rem;
    border-radius: 1rem; overflow: hidden;
    background: #fff;
    border: 1px solid rgba(100, 116, 139, 0.22);
}
.acct-id-band {
    position: relative; align-self: stretch; height: 5.5rem; margin: 0 -1.25rem;
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.14), transparent 70%), var(--accent);
}
.acct-id-slot {
    position: absolute; top: 0.5rem; left: 50%; transform: translateX(-50%);
    width: 2.8rem; height: 0.5rem; border-radius: 9999px;
    background: color-mix(in srgb, var(--accent) 55%, #000);
    box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.35);
}
.acct-photo { position: relative; margin-top: -3.25rem; width: 6.5rem; height: 6.5rem; }
.acct-photo-img, .acct-photo-initials {
    width: 100%; height: 100%; border-radius: 9999px; object-fit: cover;
    border: 4px solid #fff;
    box-shadow: 0 0 0 1px color-mix(in srgb, var(--accent) 40%, transparent);
    background: #fff;
}
.acct-photo-initials {
    display: flex; align-items: center; justify-content: center;
    font-size: 1.9rem; font-weight: 700;
    color: color-mix(in srgb, var(--accent) 60%, #000);
    background: color-mix(in srgb, var(--accent) 12%, #fff);
}
.acct-photo-cam {
    position: absolute; right: -0.1rem; bottom: 0.1rem;
    display: flex; align-items: center; justify-content: center;
    width: 2rem; height: 2rem; border-radius: 9999px; cursor: pointer;
    background: var(--accent); color: var(--on);
    border: 3px solid #fff;
    transition: transform .15s;
}
.acct-photo-cam:hover { transform: scale(1.08); }
.acct-photo-cam:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
.acct-photo-cam .pi { font-size: 0.8rem; }
.acct-name { margin-top: 0.9rem; font-size: 1.2rem; font-weight: 700; line-height: 1.25; color: #1E293B; overflow-wrap: anywhere; }
.acct-email { margin-top: 0.2rem; font-size: 0.85rem; color: #64748B; overflow-wrap: anywhere; }
.acct-roles { display: flex; flex-wrap: wrap; justify-content: center; gap: 0.4rem; margin-top: 0.75rem; }
.acct-role {
    padding: 0.2rem 0.65rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600;
    color: color-mix(in srgb, var(--accent) 60%, #000);
    background: color-mix(in srgb, var(--accent) 14%, transparent);
}
.acct-photo-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 0.5rem; margin-top: 1.1rem; }
.acct-hint { margin-top: 0.5rem; font-size: 0.75rem; color: #94A3B8; }

/* ---- school seal ---- */
.acct-school {
    position: relative; isolation: isolate; overflow: hidden;
    flex: 1 1 auto; align-self: stretch; min-height: 16rem;
    margin: 1.5rem -1.25rem -1.5rem;
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1.1rem;
    padding: 2rem 1.25rem 2.25rem;
    border-top: 1px dashed color-mix(in srgb, var(--accent) 45%, transparent);
    background: linear-gradient(180deg, transparent, color-mix(in srgb, var(--accent) 9%, transparent));
}
/* sunburst behind the seal, fading out toward the edges */
.acct-school::before {
    content: ''; position: absolute; z-index: -1; inset: -40%;
    background: repeating-conic-gradient(from 0deg at 50% 50%, color-mix(in srgb, var(--accent) 16%, transparent) 0 5deg, transparent 5deg 10deg);
    -webkit-mask-image: radial-gradient(circle at 50% 50%, #000 0, transparent 52%);
    mask-image: radial-gradient(circle at 50% 50%, #000 0, transparent 52%);
}
.acct-school-logo {
    display: flex; align-items: center; justify-content: center;
    width: 7.5rem; height: 7.5rem; border-radius: 9999px; background: #fff;
    border: 4px solid #fff;
    box-shadow:
        0 0 0 1px color-mix(in srgb, var(--accent) 45%, transparent),
        0 0 0 9px color-mix(in srgb, var(--accent) 12%, transparent),
        0 0 0 10px color-mix(in srgb, var(--accent) 30%, transparent),
        0 8px 20px rgba(15, 23, 42, 0.15);
}
.acct-school-logo img { width: 100%; height: 100%; object-fit: contain; border-radius: 9999px; }
.acct-school-name {
    max-width: 14rem; font-size: 0.85rem; font-weight: 700; line-height: 1.35; text-align: center;
    color: color-mix(in srgb, var(--accent) 55%, #000);
}
.acct-school-stripe {
    position: absolute; left: 0; right: 0; bottom: 0; height: 0.6rem;
    background: repeating-linear-gradient(135deg, var(--accent) 0 8px, color-mix(in srgb, var(--accent) 55%, #000) 8px 16px);
}

/* ---- field sections ---- */
.acct-main { display: flex; flex-direction: column; gap: 1.25rem; min-width: 0; }
.acct-sec {
    position: relative; overflow: hidden;
    border-radius: 1rem;
    background: #fff;
    border: 1px solid rgba(100, 116, 139, 0.22);
    border-left: 4px solid var(--accent);
}
.acct-sec-head {
    display: flex; align-items: center; gap: 0.9rem;
    padding: 1.15rem 1.5rem;
    background: linear-gradient(90deg, color-mix(in srgb, var(--accent) 9%, transparent), transparent 75%);
    border-bottom: 1px dashed color-mix(in srgb, var(--accent) 35%, transparent);
}
.acct-sec-icon {
    display: flex; flex: none; align-items: center; justify-content: center;
    width: 2.6rem; height: 2.6rem; border-radius: 0.8rem;
    background: var(--accent); color: var(--on); font-size: 1.05rem;
    box-shadow: 0 4px 10px color-mix(in srgb, var(--accent) 35%, transparent);
}
.acct-sec-title { flex: 1; min-width: 0; }
.acct-sec-title h3 { font-size: 1.05rem; font-weight: 700; color: #1E293B; }
.acct-sec-title p { margin-top: 0.1rem; font-size: 0.85rem; color: #64748B; }
.acct-sec-note {
    flex: none; padding: 0.25rem 0.7rem; border-radius: 9999px;
    font-size: 0.75rem; font-weight: 600; color: #64748B;
    background: rgba(100, 116, 139, 0.12);
}
.acct-sec-note.is-live { color: #b45309; background: rgba(245, 158, 11, 0.16); }
@media (max-width: 520px) { .acct-sec-note { display: none; } }
.acct-sec-body { display: flex; flex-direction: column; gap: 1.5rem; padding: 1.35rem 1.5rem 1.6rem; }
.acct-group { display: flex; flex-direction: column; gap: 0.9rem; }
.acct-group-label {
    display: flex; align-items: center; gap: 0.45rem;
    font-size: 0.8rem; font-weight: 600; color: #475569;
}
.acct-group-label .pi { font-size: 0.8rem; color: color-mix(in srgb, var(--accent) 70%, #000); }
.acct-grid { display: grid; gap: 1.25rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
@media (max-width: 640px) { .acct-grid { grid-template-columns: minmax(0, 1fr); } }
.acct-pw { display: grid; gap: 1.5rem; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr); align-items: start; }
.acct-pw-fields { display: flex; flex-direction: column; gap: 1.1rem; }
.acct-pw-rules {
    padding: 0.2rem 1rem 0.9rem; border-radius: 0.75rem;
    background: color-mix(in srgb, var(--accent) 6%, transparent);
    border: 1px dashed color-mix(in srgb, var(--accent) 40%, transparent);
}
@media (max-width: 760px) { .acct-pw { grid-template-columns: minmax(0, 1fr); } }
.acct-meter { display: flex; align-items: center; gap: 0.75rem; margin-top: -0.25rem; }
.acct-meter-bar { display: flex; flex: 1; gap: 4px; }
.acct-meter-bar i { flex: 1; height: 0.4rem; border-radius: 9999px; background: rgba(100, 116, 139, 0.25); transition: background-color .2s; }
.acct-meter-bar i.is-on { background: #10b981; }
.acct-meter-label { flex: none; font-size: 0.75rem; font-weight: 600; color: #64748B; }
.acct-match { display: flex; align-items: center; gap: 0.4rem; margin-top: -0.25rem; font-size: 0.8rem; font-weight: 600; }
.acct-match.is-ok { color: #047857; }
.acct-match.is-bad { color: #b45309; }
.acct-error { display: block; font-size: 0.8rem; color: #dc2626; }

/* ---- save bar ---- */
.acct-save {
    position: sticky; bottom: 0.75rem; z-index: 2;
    display: flex; align-items: center; justify-content: flex-end; gap: 1rem;
    padding: 0.75rem 1rem; border-radius: 1rem;
    background: rgba(255, 255, 255, 0.85);
    backdrop-filter: blur(12px);
    border: 1px solid rgba(100, 116, 139, 0.22);
}
.acct-dirty { display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; font-weight: 600; color: #b45309; }
.acct-dirty i { width: 0.55rem; height: 0.55rem; border-radius: 9999px; background: currentColor; }

/* ---- dark ---- */
.is-dark .acct-id, .is-dark .acct-sec { background: rgba(255, 255, 255, 0.05); border-color: rgba(255, 255, 255, 0.12); }
.is-dark .acct-sec { border-left-color: var(--accent); }
.is-dark .acct-photo-img, .is-dark .acct-photo-initials { border-color: #17213f; }
.is-dark .acct-photo-initials { background: color-mix(in srgb, var(--accent) 30%, #0F1730); color: #fff; }
.is-dark .acct-photo-cam { border-color: #17213f; }
.is-dark .acct-name, .is-dark .acct-sec-title h3 { color: #F8FAFC; }
.is-dark .acct-email, .is-dark .acct-sec-title p { color: #94A3B8; }
.is-dark .acct-sec-note { color: #94A3B8; background: rgba(255, 255, 255, 0.08); }
.is-dark .acct-sec-note.is-live { color: #FBBF24; background: rgba(245, 158, 11, 0.18); }
.is-dark .acct-group-label { color: #CBD5E1; }
.is-dark .acct-group-label .pi { color: color-mix(in srgb, var(--accent) 45%, #fff); }
.is-dark .acct-meter-label { color: #94A3B8; }
.is-dark .acct-match.is-ok { color: #34D399; }
.is-dark .acct-match.is-bad { color: #FBBF24; }
.is-dark .acct-role { color: color-mix(in srgb, var(--accent) 40%, #fff); background: color-mix(in srgb, var(--accent) 28%, transparent); }
.is-dark .acct-hint { color: #64748B; }
.is-dark .acct-school-name { color: color-mix(in srgb, var(--accent) 35%, #fff); }
.is-dark .acct-school-logo { border-color: #17213f; }
.is-dark .acct-pw-rules { background: color-mix(in srgb, var(--accent) 14%, transparent); border-color: color-mix(in srgb, var(--accent) 50%, transparent); }
.is-dark .acct-save { background: rgba(15, 23, 48, 0.85); border-color: rgba(255, 255, 255, 0.12); }
.is-dark .acct-dirty { color: #FBBF24; }
.is-dark .acct-error { color: #f87171; }
</style>