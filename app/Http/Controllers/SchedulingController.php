<?php

namespace App\Http\Controllers;

use App\Models\College;
use App\Models\Room;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Faculty;
use App\Models\ActivityLog;
use App\Services\ActivityLogService;
use App\Services\RoomUtilizationService;
use App\Support\AccessScope;
use App\Support\ViewingTerm;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SchedulingController extends Controller
{
    // RoomUtilizationService is the single source of truth for Room
    // Utilization (see its own class docblock) — the Rooms page and
    // the Auto Scheduler's room ranking both read from it, and this
    // Dashboard now does too, rather than recomputing its own
    // (previously buggy, previously a meaningless "vs. busiest room"
    // ratio) version that could disagree with what Rooms actually shows.
    public function __construct(private readonly RoomUtilizationService $roomUtilization) {}

    /**
     * Display the Scheduling Dashboard — a read-only control center
     * summarizing scheduling progress, conflicts, and utilization for
     * the term this user is currently viewing. All actual schedule
     * editing happens on Scheduling > Sections > Section Subjects;
     * this page never writes to section_subjects itself.
     */
    public function index(Request $request): Response
    {
        $this->authorize('view-scheduling');

        // THIS user's Viewing Term (their session override if
        // Admin/Registrar switched it, else the real system-wide
        // Active term) — see App\Support\ViewingTerm. Everyone else
        // (Dean/OIC/Assistant Dean) always resolves straight to the
        // real Active term, same as before this feature existed.
        $activeTerm = ViewingTerm::resolve($request);
        $activeTerm?->loadMissing(['schoolYear:id,name', 'semester:id,name']);

        // Sections/SectionSubjects don't carry a foreign key to
        // AcademicTerm — they're scoped by the plain academic_year /
        // semester strings, matched via AcademicTerm::matchingSectionsQuery()
        // rather than a raw string compare (Sections spell Semester
        // "First Semester"/"Second Semester"; the Semester model spells
        // it "1st Semester"/"2nd Semester" — see that method's docblock).
        $sectionsQuery = $activeTerm ? $activeTerm->matchingSectionsQuery() : Section::query();
        $user = auth()->user();

        // Section + subject scope for THIS user (see
        // Section::scopeSchedulingScopeFor() / SectionSubject::
        // scopeVisibleTo()): a Dean who is also flagged as GenEd/Minor
        // Assistant Dean sees their own College's subjects PLUS
        // GenEd/Minor everywhere — never other Colleges' Major
        // subjects. Every number below is built from this one scope so
        // the cards, alerts, charts and activity can't disagree.
        $sectionsQuery->schedulingScopeFor($user);

        $sectionIds = (clone $sectionsQuery)->pluck('id');

        $totalSections = $sectionIds->count();

        $sectionSubjectsQuery = SectionSubject::query()
            ->whereIn('section_id', $sectionIds)
            ->visibleTo($user);

        $totalSubjects = (clone $sectionSubjectsQuery)->count();
        $scheduledSubjects = (clone $sectionSubjectsQuery)->where('status', 'Scheduled')->count();
        $conflictSubjects = (clone $sectionSubjectsQuery)->where('status', 'Conflict')->count();
        $remainingSubjects = $totalSubjects - $scheduledSubjects;

        $completion = $totalSubjects > 0
            ? (int) round(($scheduledSubjects / $totalSubjects) * 100)
            : 0;

        $activeRoomsUsed = (clone $sectionSubjectsQuery)->whereNotNull('room_id')->distinct('room_id')->count('room_id');
        $activeFacultyAssigned = (clone $sectionSubjectsQuery)->whereNotNull('faculty_id')->distinct('faculty_id')->count('faculty_id');

        // Practicum/OJT rows never need Faculty either (Subject::isPracticum()) —
        // same exclusion as noRoomCount below, for the same reason.
        $noFacultyCount = (clone $sectionSubjectsQuery)
            ->whereNull('faculty_id')
            ->whereDoesntHave('subject', function ($subjectQuery) {
                $subjectQuery->where('subject_type', 'practicum');
            })
            ->count();
        // Rows that are correctly room-less by design don't belong in
        // this count: 'online' delivery_mode rows are scheduled via
        // Section Grid's Days/Time instead of a Room (see
        // SectionSubject::requiresRoom()), and Practicum/OJT rows never
        // need Faculty/Room/Days/Time at all (Subject::isPracticum()).
        // Same exclusions SectionController::unassignedSectionSubjectQuery()
        // already applies for the Sections page's "Fully Scheduled" badge —
        // kept in sync here so this alert and that badge never disagree.
        $noRoomCount = (clone $sectionSubjectsQuery)
            ->whereNull('room_id')
            ->where('delivery_mode', '!=', 'online')
            ->whereDoesntHave('subject', function ($subjectQuery) {
                $subjectQuery->where('subject_type', 'practicum');
            })
            ->count();

        $sectionsNeedingScheduling = (clone $sectionSubjectsQuery)
            ->where('status', '!=', 'Scheduled')
            ->distinct('section_id')
            ->count('section_id');

        // Faculty load — mirrors FacultyWorkloadService::sumLoad()'s
        // exact rules so this widget can never disagree with the
        // Faculty Workload tab it links to: only 'Scheduled'/'Draft'
        // placements count (never 'Conflict'), a merged Irregular-
        // section rider row is never counted a second time
        // (merged_into_section_subject_id), and a Face-to-Face/Online
        // split pair (same section_id+subject_id) is deduped down to
        // ONE contribution instead of being summed twice. The previous
        // version here summed every row unconditionally, which is why
        // this widget could show e.g. 42/24 while the Faculty's own
        // Workload tab — built on FacultyWorkloadService — showed
        // 24/24 for the same faculty member. Scoped to $sectionIds
        // (this Dashboard's Viewing-Term + visibleTo() scope) rather
        // than calling FacultyWorkloadService directly, since that
        // service always resolves the real Active term and doesn't
        // apply per-user Section visibility.
        $facultyPlacements = SectionSubject::query()
            ->visibleTo($user)
            ->whereIn('section_subjects.section_id', $sectionIds)
            ->whereNotNull('section_subjects.faculty_id')
            ->whereIn('section_subjects.status', ['Scheduled', 'Draft'])
            ->whereNull('section_subjects.merged_into_section_subject_id')
            ->with([
                'faculty:id,first_name,last_name,max_teaching_units,max_weekly_hours,workload_type',
                'subject:id,units,lecture_hours,laboratory_hours',
            ])
            ->get()
            ->filter(fn (SectionSubject $ss) => $ss->faculty !== null && $ss->subject !== null);

        $facultyLoads = $facultyPlacements
            ->unique(fn (SectionSubject $ss) => $ss->faculty_id.'-'.$ss->section_id.'-'.$ss->subject_id)
            ->groupBy('faculty_id')
            ->map(function ($placements) {
                $faculty = $placements->first()->faculty;
                $usesHours = $faculty->workload_type === 'hours';

                $assignedUnits = $placements->sum(fn (SectionSubject $ss) => $usesHours
                    ? (int) $ss->subject->lecture_hours + (int) $ss->subject->laboratory_hours
                    : (int) $ss->subject->units);

                $maxTeachingUnits = $usesHours
                    ? (int) ($faculty->max_weekly_hours ?? 0)
                    : (int) ($faculty->max_teaching_units ?? 0);

                return (object) [
                    'id' => $faculty->id,
                    'first_name' => $faculty->first_name,
                    'last_name' => $faculty->last_name,
                    'assigned_units' => $assignedUnits,
                    'max_teaching_units' => $maxTeachingUnits,
                    'unit_label' => $usesHours ? 'Hours' : 'Units',
                ];
            })
            ->sortByDesc('assigned_units')
            ->values();

        $facultyOverloadCount = $facultyLoads->filter(
            fn ($f) => $f->max_teaching_units && $f->assigned_units > $f->max_teaching_units
        )->count();

        // Faculty Utilization — EVERY faculty member, ranked. This used to be
        // "top 8 by assigned units", built only from faculty who already had
        // a placement, so anyone with nothing scheduled yet never appeared.
        // Now it starts from the roster itself (Active faculty this user may
        // see, per Faculty::scopeVisibleTo()) and left-joins the loads
        // computed above, defaulting to 0. Faculty who DO carry a load in
        // this viewed term are always included even if the roster scope
        // wouldn't normally show them (e.g. a GenEd faculty teaching a Dean's
        // GenEd subject, or a faculty member since marked Inactive), so no
        // existing row can disappear from the widget.
        $assignedLoads = $facultyLoads->keyBy('id');

        $rosterIds = Faculty::query()
            ->visibleTo($user)
            ->where('status', 'Active')
            ->pluck('id')
            ->merge($assignedLoads->keys())
            ->unique()
            ->values();

        $facultyUtilization = Faculty::query()
            ->whereIn('id', $rosterIds)
            // user_id + the linked login's photo, so a faculty member who
            // uploaded a profile photo (My Schedule) shows it here too.
            ->with('user:id,profile_photo_path')
            ->get(['id', 'user_id', 'first_name', 'last_name', 'max_teaching_units', 'max_weekly_hours', 'workload_type'])
            ->map(function (Faculty $faculty) use ($assignedLoads) {
                $usesHours = $faculty->workload_type === 'hours';
                $max = $usesHours
                    ? (int) ($faculty->max_weekly_hours ?? 0)
                    : (int) ($faculty->max_teaching_units ?? 0);
                $units = (int) ($assignedLoads->get($faculty->id)?->assigned_units ?? 0);

                return [
                    'id' => $faculty->id,
                    'name' => trim("{$faculty->first_name} {$faculty->last_name}"),
                    'photo_url' => $faculty->user?->profile_photo_url,
                    'units' => $units,
                    'max' => $max,
                    'percent' => $max > 0 ? (int) round(($units / $max) * 100) : 0,
                    'unit_label' => $usesHours ? 'Hours' : 'Units',
                ];
            })
            // Rank: highest utilization first, then most units, then name —
            // so faculty with nothing assigned sit at the bottom, A to Z.
            ->sortBy([['percent', 'desc'], ['units', 'desc'], ['name', 'asc']])
            ->values();

        // Room conflicts: same room, overlapping day tokens, overlapping time.
        $roomConflictCount = $this->countRoomConflicts($sectionIds, $user);

        // Room utilization — delegated entirely to RoomUtilizationService
        // (see its class docblock: "the single source of truth for Room
        // Utilization"), the same service the Rooms page itself calls, so
        // the % for any Room is identical to what the Rooms page shows.
        // This lists EVERY Active Room this user may schedule into (unused
        // ones at 0%), ranked by utilization — previously only Rooms with
        // a placement in this term appeared, capped at the top 8. Rooms
        // that DO have placements in this term are always included, even if
        // they're outside the user's usual room scope or since deactivated.
        $roomsUsedIds = (clone $sectionSubjectsQuery)->whereNotNull('room_id')->distinct()->pluck('room_id');

        $roomIds = Room::query()
            ->usableBy($user)
            ->where('status', 'Active')
            ->pluck('id')
            ->merge($roomsUsedIds)
            ->unique()
            ->values();

        $rooms = Room::query()->whereIn('id', $roomIds)->get()->keyBy('id');
        $roomSummaries = $this->roomUtilization->summarizeRooms($rooms);

        $roomUtilization = collect($roomSummaries)
            ->map(fn ($summary) => [
                'id' => $summary['room_id'],
                'name' => $rooms->get($summary['room_id'])?->room_name ?? '—',
                'occupancy' => (int) round($summary['utilization_percent']),
            ])
            ->sortBy([['occupancy', 'desc'], ['name', 'asc']])
            ->values();

        // Progress per college, via Section -> Major -> Department -> College.
        $collegeProgress = College::query()
            ->where('status', 'Active')
            ->orderBy('name')
            ->get(['id', 'name', 'short_name'])
            ->map(function ($college) use ($sectionIds, $user) {
                $ids = Section::query()
                    ->whereIn('id', $sectionIds)
                    ->whereHas('major.department', fn ($q) => $q->where('college_id', $college->id))
                    ->pluck('id');

                $total = SectionSubject::query()->whereIn('section_id', $ids)->visibleTo($user)->count();
                $scheduled = SectionSubject::query()->whereIn('section_id', $ids)->visibleTo($user)->where('status', 'Scheduled')->count();

                return [
                    'id' => $college->id,
                    'name' => $college->short_name ?: $college->name,
                    'total' => $total,
                    'scheduled' => $scheduled,
                    'percent' => $total > 0 ? (int) round(($scheduled / $total) * 100) : 0,
                ];
            })
            ->filter(fn ($c) => $c['total'] > 0)
            ->values();

        // Recent activity: most recently updated placements (Faculty/
        // Room/Time assignments) merged with Faculty roster events
        // (added, units changed, workload overridden — spec: Dean's
        // direct "Add Faculty"/"Add Units" should surface here too,
        // not just in Settings > Activity Log), newest first across
        // both sources.
        $placementActivity = SectionSubject::query()
            ->whereIn('section_id', $sectionIds)
            ->visibleTo($user)
            ->with(['section:id,section_code', 'subject:id,subject_title'])
            ->orderByDesc('updated_at')
            ->take(8)
            ->get()
            ->map(fn ($ss) => [
                'label' => $ss->status === 'Scheduled'
                    ? "Scheduled {$ss->section?->section_code} — {$ss->subject?->subject_title}"
                    : "Updated {$ss->section?->section_code} — {$ss->subject?->subject_title}",
                'status' => $ss->status,
                'updated_at' => $ss->updated_at,
                'sort_at' => $ss->updated_at,
            ]);

        // Same college scoping as everything else on this dashboard —
        // null means unrestricted (Admin/Registrar see every college's
        // Faculty events too), a Dean/OIC/Assistant Dean only sees
        // their own college's (or, for Assistant Dean, the
        // no-college/GenEd pool's — see visibleCollegeIds()'s docblock).
        $visibleCollegeIds = AccessScope::visibleCollegeIds(auth()->user());

        $facultyActivityQuery = ActivityLog::query()
            ->where('subject_type', Faculty::class)
            ->whereIn('action', [ActivityLogService::FACULTY_CREATED, ActivityLogService::FACULTY_UPDATED])
            ->with('subject')
            ->orderByDesc('created_at')
            ->take(8)
            ->get();

        if ($visibleCollegeIds !== null) {
            $facultyActivityQuery = $facultyActivityQuery->filter(
                fn ($log) => $log->subject && in_array($log->subject->college_id, $visibleCollegeIds, true)
            );
        }

        $facultyActivity = $facultyActivityQuery
            ->map(fn ($log) => [
                'label' => $log->description,
                'status' => null,
                'updated_at' => $log->created_at,
                'sort_at' => $log->created_at,
            ]);

        $recentActivity = $placementActivity
            ->concat($facultyActivity)
            ->sortByDesc('sort_at')
            ->take(8)
            ->map(fn ($item) => [
                'label' => $item['label'],
                'status' => $item['status'],
                'updated_at' => optional($item['updated_at'])->diffForHumans(),
            ])
            ->values();

        return Inertia::render('Scheduling/Index', [
            'activeTerm' => $activeTerm ? [
                'school_year' => $activeTerm->schoolYear?->name,
                'semester' => $activeTerm->semester?->name,
            ] : null,
            'stats' => [
                'total_sections' => $totalSections,
                'total_subjects' => $totalSubjects,
                'scheduled_subjects' => $scheduledSubjects,
                'remaining_subjects' => $remainingSubjects,
                'completion' => $completion,
                'active_rooms' => $activeRoomsUsed,
                'active_faculty' => $activeFacultyAssigned,
            ],
            'alerts' => [
                'no_faculty' => $noFacultyCount,
                'no_room' => $noRoomCount,
                'faculty_overload' => $facultyOverloadCount,
                'room_conflicts' => $roomConflictCount,
                'sections_needing_scheduling' => $sectionsNeedingScheduling,
                'conflict_subjects' => $conflictSubjects,
            ],
            'collegeProgress' => $collegeProgress,
            'facultyUtilization' => $facultyUtilization,
            'roomUtilization' => $roomUtilization,
            'recentActivity' => $recentActivity,
        ]);
    }

    /**
     * Naive room-conflict counter: counts placement pairs that share a
     * room with an overlapping day token and overlapping time range.
     * Used only for the dashboard alert count — the authoritative
     * conflict check lives in the scheduling engine's own validation.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $sectionIds
     */
    private function countRoomConflicts($sectionIds, $user = null): int
    {
        $placements = SectionSubject::query()
            ->visibleTo($user)
            ->whereIn('section_id', $sectionIds)
            ->whereNotNull('room_id')
            ->whereNotNull('days')
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->get(['id', 'room_id', 'days', 'start_time', 'end_time']);

        $conflicts = 0;

        foreach ($placements->groupBy('room_id') as $roomPlacements) {
            $list = $roomPlacements->values();

            for ($i = 0; $i < $list->count(); $i++) {
                for ($j = $i + 1; $j < $list->count(); $j++) {
                    $a = $list[$i];
                    $b = $list[$j];

                    $daysA = array_filter(explode(',', (string) $a->days));
                    $daysB = array_filter(explode(',', (string) $b->days));

                    if (empty(array_intersect($daysA, $daysB))) {
                        continue;
                    }

                    if ($a->start_time < $b->end_time && $b->start_time < $a->end_time) {
                        $conflicts++;
                    }
                }
            }
        }

        return $conflicts;
    }
}