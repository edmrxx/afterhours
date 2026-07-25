<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            // Public identifier handed to the customer, format AH-XXXXXXXX.
            $table->string('code', 32)->unique();

            // Restricted: a court or slot with booking history must never
            // vanish underneath the audit/reporting trail.
            $table->foreignId('court_id')->constrained('courts')->restrictOnDelete();
            $table->foreignId('court_slot_id')->constrained('court_slots')->restrictOnDelete();

            $table->string('customer_name', 150);
            $table->string('customer_phone', 32);
            $table->string('customer_email')->nullable();
            $table->text('notes')->nullable();

            $table->decimal('amount', 10, 2)->default(0);

            $table->enum('status', [
                'awaiting_payment',
                'pending_verification',
                'confirmed',
                'completed',
                'rejected',
                'expired',
                'cancelled',
            ])->default('awaiting_payment');

            $table->string('payment_reference', 100)->nullable();
            $table->string('payment_proof_path')->nullable();
            $table->timestamp('payment_submitted_at')->nullable();

            $table->timestamp('hold_expires_at')->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejection_reason', 500)->nullable();

            $table->timestamp('cancelled_at')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The release job scans exactly this pair.
            $table->index(['status', 'hold_expires_at']);
            $table->index(['court_id', 'status']);
            // (court_slot_id) is already indexed by its foreign key.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
