<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /*
     * Audit integrity: the audit trail is append-only in the database itself, not only in the
     * AuditLog model — an UPDATE or DELETE through the query builder or raw SQL is refused.
     */
    public function up(): void
    {
        match (DB::getDriverName()) {
            'pgsql' => DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION audit_logs_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'audit_logs is append-only: % is not allowed', TG_OP;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER audit_logs_append_only
                    BEFORE UPDATE OR DELETE ON audit_logs
                    FOR EACH ROW EXECUTE FUNCTION audit_logs_append_only();
                SQL),
            'sqlite' => DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs
                BEGIN SELECT RAISE(ABORT, 'audit_logs is append-only: UPDATE is not allowed'); END;
                CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs
                BEGIN SELECT RAISE(ABORT, 'audit_logs is append-only: DELETE is not allowed'); END;
                SQL),
            default => null,
        };
    }

    public function down(): void
    {
        match (DB::getDriverName()) {
            'pgsql' => DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_append_only ON audit_logs; DROP FUNCTION IF EXISTS audit_logs_append_only();'),
            'sqlite' => DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update; DROP TRIGGER IF EXISTS audit_logs_no_delete;'),
            default => null,
        };
    }
};
