<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PERFORMANCE — indexes for columns the list pages, filters and
 * dashboard aggregates actually filter/sort on. Verified against the
 * real schema (faculties, notifications.recipient_user_id, etc.).
 * Foreign-key columns are already indexed by MySQL, so only the
 * non-FK filter columns and useful composites are added here.
 * Idempotent: skips anything that already exists (Schema::hasIndex).
 */
return new class extends Migration
{
    /** @return array<string, array<string, list<string>>> table => [index name => columns] */
    private function map(): array
    {
        return [
            'sections' => [
                'sections_term_idx' => ['academic_year', 'semester'],
                'sections_year_level_idx' => ['year_level'],
                'sections_status_idx' => ['status'],
                'sections_is_finalized_idx' => ['is_finalized'],
            ],
            'section_subjects' => [
                'section_subjects_status_idx' => ['status'],
                'section_subjects_auto_idx' => ['is_auto_generated'],
            ],
            'faculties' => [
                'faculties_status_idx' => ['status'],
                'faculties_college_status_idx' => ['college_id', 'status'],
            ],
            'rooms' => [
                'rooms_status_idx' => ['status'],
                'rooms_building_idx' => ['building'],
            ],
            'users' => [
                'users_status_idx' => ['status'],
            ],
            'subjects' => [
                'subjects_category_idx' => ['category'],
                'subjects_is_active_idx' => ['is_active'],
            ],
            'curriculum_items' => [
                'curriculum_items_lookup_idx' => ['curriculum_id', 'year_level', 'semester'],
            ],
            'notifications' => [
                'notifications_recipient_created_idx' => ['recipient_user_id', 'created_at'],
            ],
            'faculty_load_requests' => [
                'faculty_load_requests_status_idx' => ['status'],
            ],
            'chat_messages' => [
                'chat_messages_conversation_created_idx' => ['conversation_id', 'created_at'],
            ],
        ];
    }

    public function up(): void
    {
        foreach ($this->map() as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $name => $columns) {
                if (! Schema::hasColumns($table, $columns) || Schema::hasIndex($table, $name)) {
                    continue;
                }

                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach ($this->map() as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (array_keys($indexes) as $name) {
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};