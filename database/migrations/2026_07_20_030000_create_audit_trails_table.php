<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_trails', function (Blueprint $table) {
            $table->id();

            // Nullable so the log survives the actor being deleted.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Snapshots: the log must still read correctly after the user row
            // and its role assignment are gone.
            $table->string('user_name')->nullable();
            $table->string('role_name', 100)->nullable();

            $table->string('module', 60);
            $table->string('action', 30);
            // TEXT, not a bounded string: the Auditable trait composes a diff
            // sentence whose length depends on how many columns moved.
            $table->text('description')->nullable();

            // Optional polymorphic pointer at the record that changed.
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('browser', 100)->nullable();
            $table->string('platform', 100)->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('method', 10)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            // (user_id) is already indexed by the foreign key above.
            $table->index(['module', 'action']);
            $table->index(['auditable_type', 'auditable_id'], 'audit_trails_auditable_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_trails');
    }
};
