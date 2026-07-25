<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('court_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('court_id')->constrained('courts')->cascadeOnDelete();
            $table->date('slot_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->decimal('price', 10, 2)->default(0);
            $table->enum('status', ['available', 'held', 'booked', 'blocked'])->default('available');

            // Points at the booking currently holding this slot. Deliberately
            // unconstrained: bookings soft-delete and the release job nulls
            // this field itself, so a hard FK would only add contention.
            $table->unsignedBigInteger('held_booking_id')->nullable();

            $table->timestamps();

            // The guard that makes the bulk generator safe to re-run.
            $table->unique(['court_id', 'slot_date', 'start_time'], 'court_slots_unique_slot');
            // Drives the public availability query.
            $table->index(['court_id', 'slot_date', 'status'], 'court_slots_availability_index');
            $table->index(['status', 'slot_date']);
            $table->index('held_booking_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('court_slots');
    }
};
