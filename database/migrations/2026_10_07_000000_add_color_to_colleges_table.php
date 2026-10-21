<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each College gets its own color (Settings -> College Colors). The Room
     * Grid and Section Grid color their blocks by the owning College instead
     * of one fixed color for everything.
     *
     * Existing colleges are backfilled once with the school's current colors;
     * any other college is given the next free palette color.
     */
    public function up(): void
    {
        Schema::table('colleges', function (Blueprint $table) {
            $table->string('color', 7)->nullable()->after('counts_as_gened');
        });

        $defaults = [
            'CCS' => '#EAB308',  // yellow
            'CTE' => '#3B82F6',  // blue
            'COC' => '#8B5CF6',  // violet
            'SHTM' => '#F97316', // orange
        ];

        foreach ($defaults as $code => $hex) {
            DB::table('colleges')->where('code', $code)->whereNull('color')->update(['color' => $hex]);
        }

        // Any other existing college (not one of the four above).
        $palette = ['#10B981', '#EC4899', '#06B6D4', '#EF4444', '#84CC16', '#6366F1', '#14B8A6', '#A16207'];
        $i = 0;
        foreach (DB::table('colleges')->whereNull('color')->orderBy('id')->pluck('id') as $id) {
            DB::table('colleges')->where('id', $id)->update(['color' => $palette[$i % count($palette)]]);
            $i++;
        }
    }

    public function down(): void
    {
        Schema::table('colleges', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};