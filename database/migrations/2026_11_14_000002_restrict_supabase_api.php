<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }foreach (['users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'clients', 'projects', 'project_user', 'tasks', 'checklist_items', 'notes', 'assets', 'activities', 'invitations', 'drive_connections'] as $table) {
            DB::statement('ALTER TABLE "'.$table.'" ENABLE ROW LEVEL SECURITY');
            foreach (['anon', 'authenticated'] as $role) {
                if (DB::selectOne('SELECT 1 FROM pg_roles WHERE rolname = ?', [$role])) {
                    DB::statement('REVOKE ALL ON TABLE "'.$table.'" FROM "'.$role.'"');
                }
            }
        }
    }

    public function down(): void
    { /* Never restore public API access during rollback. */
    }
};
