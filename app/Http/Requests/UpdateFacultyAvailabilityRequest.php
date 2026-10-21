<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Faculty Details > Availability tab. Authorization is handled by
 * FacultyController::updateAvailability() via the Faculty `update`
 * policy, same as editing the faculty record.
 */
class UpdateFacultyAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An empty list is valid — it clears the restriction (available
     * any time). Each window applies to every Day selected in it.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'availability' => ['present', 'array', 'max:20'],
            'availability.*.days' => ['required', 'array', 'min:1'],
            'availability.*.days.*' => ['string', Rule::in(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'])],
            'availability.*.start_time' => ['required', 'date_format:H:i'],
            'availability.*.end_time' => ['required', 'date_format:H:i', 'after:availability.*.start_time'],
        ];
    }
}