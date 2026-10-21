<?php

namespace App\Http\Controllers;

use App\Models\Faculty;
use App\Models\SchoolYear;
use App\Services\FacultyWorkloadService;
use App\Services\ImageOptimizationService;
use App\Support\ViewingTerm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FACULTY LOGIN ACCOUNTS — "My Schedule", the one page a Faculty-role
 * login can use.
 *
 * SCOPING: the faculty record is ALWAYS resolved from the logged-in
 * user (faculties.user_id = auth user). This action takes no faculty
 * id from the URL or the request, so there is nothing to tamper with —
 * a faculty member cannot be pointed at someone else's schedule.
 *
 * Read-only by design: no edit/move endpoints exist for this role (and
 * RestrictFacultyRole blocks every write route anyway).
 */
class MyScheduleController extends Controller
{
    public function __construct(
        private readonly FacultyWorkloadService $workloadService,
        private readonly ImageOptimizationService $imageOptimizer,
    ) {}

    /**
     * Faculty sets their OWN profile photo. Always acts on the logged-in
     * user — no user id is read from the request.
     */
    public function updatePhoto(Request $request): RedirectResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $user = $request->user();

        if ($user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
        }

        // Same compression pipeline the Manage Account tab uses.
        $user->profile_photo_path = $this->imageOptimizer->storeOptimized($request->file('photo'), 'profile-photos');
        $user->save();

        return back()->with('success', 'Profile photo updated.');
    }

    public function destroyPhoto(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
            $user->profile_photo_path = null;
            $user->save();
        }

        return back()->with('success', 'Profile photo removed.');
    }

    public function index(Request $request): Response
    {
        $faculty = Faculty::query()
            ->where('user_id', $request->user()->id)
            ->with(['college' => fn ($query) => $query->withTrashed()])
            ->first();

        // Logged in, but no faculty record is linked to this login (e.g. the
        // faculty was removed). Show an explanation instead of an error page.
        if (! $faculty) {
            return Inertia::render('MySchedule', ['faculty' => null]);
        }

        // Same engine the Faculty Details Workload tab uses, so the numbers
        // and the list can never disagree with what the Registrar sees.
        // Active semester only; Scheduled + Draft placements (the page
        // labels Draft ones "Tentative").
        $workload = $this->workloadService->evaluate($faculty, includePlacements: true);

        // A Faculty-role login can't use the Viewing Term switch, so this is
        // always the real Active term.
        $term = ViewingTerm::resolve($request);
        $term?->loadMissing(['schoolYear:id,name', 'semester:id,name']);

        $yearName = $term?->schoolYear?->name;
        $schoolYear = $yearName ? SchoolYear::query()->where('name', $yearName)->first() : null;
        $schoolYear ??= SchoolYear::active();

        return Inertia::render('MySchedule', [
            'faculty' => [
                'full_name' => $faculty->full_name,
                'faculty_id' => $faculty->faculty_id,
                'employment_type' => $faculty->employment_type,
                'college' => $faculty->college?->name,
                'category' => $faculty->faculty_category,
                'photo_url' => $request->user()->profile_photo_url,
            ],
            'workload' => [
                'current' => $workload['current'],
                'max' => $workload['max'],
                'percent' => $workload['percent'],
                'status' => $workload['status'],
                'unit_label' => $workload['unit_label'],
                'assigned_subjects' => $workload['assigned_subjects'],
            ],
            'placements' => $workload['assigned_placements'] ?? [],
            'term' => $term ? [
                'academic_year' => $term->schoolYear?->name,
                'semester' => $term->semester?->name,
            ] : null,
            'schedulingWindow' => [
                'start_time' => $schoolYear?->classStartTime() ?? SchoolYear::DEFAULT_CLASS_START_TIME,
                'end_time' => $schoolYear?->classEndTime() ?? SchoolYear::DEFAULT_CLASS_END_TIME,
                'available_days' => $schoolYear?->allowedDays() ?? SchoolYear::DEFAULT_CLASS_DAYS,
                'interval_minutes' => $schoolYear?->intervalMinutes() ?? SchoolYear::DEFAULT_TIME_INTERVAL_MINUTES,
            ],
        ]);
    }
}