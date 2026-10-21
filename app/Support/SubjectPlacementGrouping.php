<?php

namespace App\Support;

use App\Models\SectionSubject;
use Illuminate\Support\Collection;

/**
 * Canonical "one row per distinct subject offering" grouping for
 * SectionSubject placements.
 *
 * WHY THIS EXISTS
 * ------------------------------------------------------------------
 * A Subject normally occupies exactly one `section_subjects` row. A
 * Registrar can split its weekly hours into two rows instead — a
 * Lecture + Laboratory component, or a Face-to-Face + Online delivery
 * pair — sharing the same `section_id` + `subject_id` (and one EDP
 * code). Any widget that counts "subjects" rather than raw scheduling
 * rows must collapse a split pair back into ONE subject, or it
 * silently double-counts every split subject.
 *
 * This is exactly why the Home dashboard, the Scheduling page, and
 * the Reports page could each show a different "total subjects" for
 * the same term (337 vs 331, a 6-row gap from 6 split subjects) even
 * though every one of them was querying the same table correctly —
 * only Reports was collapsing split rows.
 *
 * Every place that needs a subject-level (not row-level) count should
 * go through this helper instead of re-deriving its own groupBy(), so
 * the definition of "one subject" can never quietly drift apart again.
 */
class SubjectPlacementGrouping
{
    /**
     * The key that identifies one distinct subject offering: every
     * row sharing this key is the same Subject inside the same
     * Section, split across delivery modes/components.
     */
    public static function key(SectionSubject $placement): string
    {
        return $placement->section_id.'-'.$placement->subject_id;
    }

    /**
     * Groups a collection of SectionSubject rows into one entry per
     * distinct subject offering.
     *
     * @param  Collection<int, SectionSubject>  $placements
     * @return Collection<string, Collection<int, SectionSubject>>
     */
    public static function group(Collection $placements): Collection
    {
        return $placements->groupBy(fn (SectionSubject $ss) => self::key($ss));
    }

    /**
     * A subject offering only counts as Scheduled once EVERY one of
     * its component rows is individually Scheduled — the same rule
     * the Sections page's "N/N Fully Scheduled" badge and the Faculty
     * Workload tab already apply.
     *
     * @param  Collection<int, SectionSubject>  $group
     */
    public static function isScheduled(Collection $group): bool
    {
        return $group->every(fn (SectionSubject $ss) => $ss->status === 'Scheduled');
    }

    /**
     * A subject offering only counts as fully assigned on a given
     * column (faculty_id/room_id) once EVERY one of its component
     * rows carries a value — a split subject with Faculty on its
     * Lecture half but not its Lab half is still "in progress", not
     * "assigned". An Online-delivery row that never needs a Room
     * (see SectionSubject::requiresRoom()) is excluded from the
     * room_id check so it can't hold a split subject back from
     * showing as Room-assigned.
     *
     * @param  Collection<int, SectionSubject>  $group
     */
    public static function isFullyAssigned(Collection $group, string $column): bool
    {
        $rows = $column === 'room_id'
            ? $group->filter(fn (SectionSubject $ss) => $ss->requiresRoom())
            : $group;

        if ($rows->isEmpty()) {
            return true;
        }

        return $rows->every(fn (SectionSubject $ss) => $ss->{$column} !== null);
    }
}