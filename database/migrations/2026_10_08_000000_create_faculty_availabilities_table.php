<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A Faculty member's AVAILABLE teaching hours — one row per Day per
     * time window (e.g. a Part-time instructor free Mon-Fri 08:00-12:00
     * gets five rows). A faculty member with NO rows is unrestricted.
     * Enforced by ScheduleConflictService for Auto Generate, Recommend
     * Day & Time, and manual saves.
     */
    public function up(): void
    {
        Schema::create('faculty_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_id')->constrained()->cascadeOnDelete();
            $table->string('day', 3); // Mon, Tue, Wed, Thu, Fri, Sat, Sun
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->index(['faculty_id', 'day']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('faculty_availabilities');
    }
};