<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

class College extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** Cache key for the {college_id => hex} map shared to every page. */
    public const COLOR_CACHE_KEY = 'college_colors.v1';

    /**
     * Starter palette. A newly created College is automatically given the
     * first color here that no other College is using yet, so a new college
     * never shows up with the same color as an existing one. The Registrar /
     * Admin can change it any time in Settings -> College Colors.
     */
    public const COLOR_PALETTE = [
        '#EAB308', // yellow
        '#3B82F6', // blue
        '#8B5CF6', // violet
        '#F97316', // orange
        '#10B981', // emerald
        '#EC4899', // pink
        '#06B6D4', // cyan
        '#EF4444', // red
        '#84CC16', // lime
        '#6366F1', // indigo
        '#14B8A6', // teal
        '#A16207', // brown
    ];

    protected static function booted(): void
    {
        static::creating(function (College $college) {
            if (empty($college->color)) {
                $used = static::withTrashed()->whereNotNull('color')->pluck('color')
                    ->map(fn ($hex) => strtoupper($hex))->all();
                $free = array_values(array_diff(self::COLOR_PALETTE, $used));
                $college->color = $free[0]
                    ?? self::COLOR_PALETTE[static::withTrashed()->count() % count(self::COLOR_PALETTE)];
            }
        });

        // Any change to a college (or its color) refreshes the shared map.
        $forget = fn () => Cache::forget(self::COLOR_CACHE_KEY);
        static::saved($forget);
        static::deleted($forget);
        static::restored($forget);
    }

    /**
     * {college_id => '#RRGGBB'} for every College (cached).
     *
     * @return array<int, string>
     */
    public static function colorMap(): array
    {
        return Cache::rememberForever(self::COLOR_CACHE_KEY, function () {
            return static::withTrashed()
                ->whereNotNull('color')
                ->pluck('color', 'id')
                ->all();
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'short_name',
        'description',
        'status',
        'counts_as_gened',
        'color',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'counts_as_gened' => 'boolean',
    ];

    /**
     * The departments that belong to this college.
     *
     * @return HasMany<Department>
     */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    /**
     * The users (Deans / OICs / etc.) assigned to this college.
     *
     * @return HasMany<User>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}