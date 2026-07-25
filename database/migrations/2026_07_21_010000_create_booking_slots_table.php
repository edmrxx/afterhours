<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Durable record of every slot a booking ever held, independent of the
     * live `court_slots.held_booking_id` pointer.
     *
     * `held_booking_id` is a "currently held by" pointer: reject/cancel/expire
     * null it on every slot so the slot goes back on sale. That makes it
     * useless as a history — a rejected/cancelled/expired multi-slot booking
     * would otherwise lose its own schedule the moment its slots are
     * released. This table is written once at reserve time and never
     * modified or deleted afterward, so a booking's full slot list stays
     * readable for its entire lifetime, whatever its current status.
     */
    public function up(): void
    {
        Schema::create('booking_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            // Restricted, same as bookings.court_slot_id: a slot with booking
            // history must never vanish underneath this record.
            $table->foreignId('court_slot_id')->constrained('court_slots')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['booking_id', 'court_slot_id']);
            $table->index('court_slot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_slots');
    }
};
