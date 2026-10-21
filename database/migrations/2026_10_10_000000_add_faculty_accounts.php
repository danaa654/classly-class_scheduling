<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * FACULTY LOGIN ACCOUNTS — lets a Faculty Master record have its own
 * login so the faculty member can see their OWN schedule (and nothing
 * else).
 *
 * - faculties.user_id: the link between a Faculty record and the
 *   login account created for it. Nullable (most rows start without
 *   an account) and unique (one login per faculty, one faculty per
 *   login). nullOnDelete so deleting a user never deletes a faculty.
 * - users.invited_at / invite_accepted_at / invite_error: delivery
 *   tracking for the "set your password" invitation, so the Faculty
 *   page can show No account / Invite pending / Active / Send failed.
 * - The "Faculty" role. Created here (not in RoleSeeder::ROLES) on
 *   purpose: ROLES also feeds the Users page's role dropdown and
 *   validation, and Faculty accounts are provisioned from the Faculty
 *   page, never hand-created in User Management.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('invited_at')->nullable()->after('must_change_password');
            $table->timestamp('invite_accepted_at')->nullable()->after('invited_at');
            $table->text('invite_error')->nullable()->after('invite_accepted_at');
        });

        Schema::table('faculties', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->unique()->after('id')
                ->constrained('users')->nullOnDelete();
        });

        Role::firstOrCreate(['name' => 'Faculty', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('faculties', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['invited_at', 'invite_accepted_at', 'invite_error']);
        });

        Role::where('name', 'Faculty')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};