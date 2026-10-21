<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Faculty Master record.
 *
 * A Faculty record is a roster entry the registrar/admin maintains so
 * people can be assigned teaching loads during scheduling. It is NOT
 * itself a login. Optionally (faculties.user_id) a Faculty-role user
 * account can be linked to it — created from the Faculty page via
 * FacultyAccountService — so the faculty member can log in and see only
 * their own schedule (MyScheduleController). Subject/schedule/room/
 * section assignment is handled by other modules; this model only
 * stores faculty identity and eligibility information.
 */
class Faculty extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * Keep the linked login in step with the roster record, so a
     * deactivated/removed faculty member can't keep logging in and an
     * email fix doesn't leave the login on the old address.
     * (user_id itself is never mass-assignable — it's set only by
     * FacultyAccountService.)
     */
    protected static function booted(): void
    {
        static::saved(function (Faculty $faculty) {
            if (! $faculty->user_id) {
                return;
            }

            // Only on an actual status change — never re-activate an
            // account an admin deactivated by hand just because this
            // roster row was saved for some other reason.
            if ($faculty->wasChanged('status')) {
                \App\Models\User::query()->whereKey($faculty->user_id)
                    ->update(['status' => $faculty->status === 'Active' ? 'Active' : 'Inactive']);
            }

            if ($faculty->wasChanged('email') && filled($faculty->email)) {
                $email = \Illuminate\Support\Str::lower(trim($faculty->email));
                $taken = \App\Models\User::query()
                    ->whereRaw('LOWER(email) = ?', [$email])
                    ->where('id', '!=', $faculty->user_id)
                    ->exists();

                if (! $taken) {
                    \App\Models\User::query()->whereKey($faculty->user_id)->update(['email' => $email]);
                }
            }
        });

        // Soft delete (and force delete) both land here.
        static::deleted(function (Faculty $faculty) {
            if ($faculty->user_id) {
                \App\Models\User::query()->whereKey($faculty->user_id)->update(['status' => 'Inactive']);
            }
        });

        static::restored(function (Faculty $faculty) {
            if ($faculty->user_id && $faculty->status === 'Active') {
                \App\Models\User::query()->whereKey($faculty->user_id)->update(['status' => 'Active']);
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'faculty_id',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'employment_type',
        'college_id',
        'max_teaching_units',
        'workload_type',
        'max_weekly_hours',
        'status',
        'email',
        'contact_number',
        'remarks',
    ];

    /**
     * `faculty_category` is derived (see getFacultyCategoryAttribute()
     * below) rather than a real column, so it must be explicitly
     * appended to appear in JSON responses the same way the old stored
     * column did.
     *
     * @var list<string>
     */
    protected $appends = ['faculty_category', 'full_name'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_teaching_units' => 'integer',
            'max_weekly_hours' => 'integer',
        ];
    }

    /**
     * Every schedule placement (across every Section/College) this
     * Faculty member has ever been assigned to. Used by
     * FacultyWorkloadService — prefer that service's
     * currentLoad()/evaluate() over querying this relation directly,
     * since the service is what applies the "active semester only, no
     * Conflict status" workload scope.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<SectionSubject>
     */
    public function sectionSubjects(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SectionSubject::class);
    }

    /**
     * Delivery history for the Faculty Schedule Email System (Reports
     * -> Faculty Schedule -> Send via Email). See FacultyScheduleEmail.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<FacultyScheduleEmail>
     */
    public function scheduleEmails(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FacultyScheduleEmail::class);
    }

    /**
     * Time windows this faculty member is AVAILABLE to teach (one row
     * per Day per window). No rows at all means "no restriction" —
     * available any time within the school's class hours. Enforced by
     * ScheduleConflictService::findFacultyAvailabilityViolation() for
     * Auto Generate, Recommend Day & Time, and every manual save.
     *
     * @return HasMany<FacultyAvailability>
     */
    public function availabilities(): HasMany
    {
        return $this->hasMany(FacultyAvailability::class);
    }

    /**
     * The login account linked to this faculty member (Faculty role), if
     * one has been created. See FacultyAccountService.
     *
     * @return BelongsTo<User, Faculty>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The college this faculty member belongs to.
     *
     * @return BelongsTo<College, Faculty>
     */
    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    /**
     * Subjects this faculty member is qualified to teach.
     *
     * Teaching qualification is a pure many-to-many link (via
     * `faculty_subject`) — it carries no extra data of its own. The
     * scheduling engine's Genetic Algorithm reads this relationship to
     * ensure it never assigns a faculty member to a subject they are
     * not qualified for.
     *
     * @return BelongsToMany<Subject>
     */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'faculty_subject')->withTimestamps();
    }

    /**
     * Full display name, formatted "Last, First Middle Suffix".
     */
    public function getFullNameAttribute(): string
    {
        $middleInitial = $this->middle_name ? ' '.mb_substr($this->middle_name, 0, 1).'.' : '';
        $suffix = $this->suffix ? ' '.$this->suffix : '';

        return "{$this->last_name}, {$this->first_name}{$middleInitial}{$suffix}";
    }

    /**
     * Derived from `college_id` rather than stored, so this can never
     * drift out of sync with it: no College means General Education
     * Faculty (GenEd/Minor — English, Math, Filipino, NSTP, PE, etc.),
     * otherwise the faculty member belongs to a department (College).
     */
    public function getFacultyCategoryAttribute(): string
    {
        return $this->college_id === null
            ? 'General Education Faculty'
            : 'Department Faculty';
    }

    /**
     * RBAC query scope (spec Section 24 — QUERY SCOPING): restrict the
     * Faculty roster to what $user is authorized to see.
     * Admin/Registrar/Assistant Dean: unrestricted (full roster —
     * Assistant Dean needs to find Minor/GenEd-qualified faculty
     * regardless of which College they belong to). Dean/OIC: their
     * own College's faculty only, UNLESS $allColleges is true (the
     * "All Faculty" filter option — a read-mostly institution-wide
     * view; write access to rows outside their own College is still
     * blocked by FacultyPolicy::canAccess(), this only affects what
     * they can SEE). Anyone else: no rows.
     */
    public function scopeVisibleTo($query, ?\App\Models\User $user, bool $allColleges = false)
    {
        // Assistant Dean sees the FULL roster (like Admin/Registrar) —
        // Minor/GenEd subjects are taught by faculty who belong to any
        // College (e.g. a CCS faculty teaching an ITE minor for CTE/
        // BSHM/BSTM), so restricting this list to college_id-null
        // faculty hid exactly the people Assistant Dean needs to find
        // to assign them. What stays restricted to Assistant Dean is
        // WHICH qualifications/subjects they may manage for a given
        // faculty (Minor/GenEd only) — see FacultyPolicy::manageQualification()
        // — never which faculty rows they can see.
        if (\App\Support\AccessScope::isUnrestricted($user) || \App\Support\AccessScope::isAssistantDean($user) || $allColleges) {
            return $query;
        }

        $ids = \App\Support\AccessScope::visibleCollegeIds($user);

        return $query->whereIn('college_id', $ids ?? [-1]);
    }
}