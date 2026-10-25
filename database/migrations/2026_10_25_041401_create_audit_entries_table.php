<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Audit entries are append-only. On SQLite, database triggers reject any
     * attempt to change or delete an entry, even from outside the application.
     */
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->morphs('auditable');
            $table->string('event');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_entries_prevent_update BEFORE UPDATE ON audit_entries
                BEGIN
                    SELECT RAISE(ABORT, 'Audit entries cannot be changed.');
                END;
                SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_entries_prevent_delete BEFORE DELETE ON audit_entries
                BEGIN
                    SELECT RAISE(ABORT, 'Audit entries cannot be deleted.');
                END;
                SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
    }
};
