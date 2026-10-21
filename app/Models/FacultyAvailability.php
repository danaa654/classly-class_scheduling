<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One available time window for one Day of one Faculty member
 * (e.g. Mon 08:00-12:00). See Faculty::availabilities().
 */
class FacultyAvailability extends Model
{
    protected $fillable = [
        'faculty_id',
        'day',
        'start_time',
        'end_time',
    ];

    /**
     * @return BelongsTo<Faculty, FacultyAvailability>
     */
    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }
}