<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which app the guest actually paid with.
 *
 * Checkout used to offer GCash and nothing else, so a reference number could
 * only ever mean one thing and there was nothing to record. Now that a second
 * QR (GoTyme) sits beside it, a bare reference no longer tells the verifying
 * staff member which app to open — this column carries that answer alongside
 * `payment_reference`, which is why it sits directly after it.
 *
 * NULLABLE on purpose, and deliberately NOT backfilled. Every booking taken
 * before this migration was submitted when no choice existed, so the honest
 * value for those rows is "unknown" rather than a guess. Defaulting them to
 * 'gcash' would read as a recorded customer choice in the audit trail when in
 * fact nobody ever recorded anything, so the UI renders null as an em dash.
 *
 * A plain string, not an enum: the set of wallets a client uses is business
 * config that changes without warning, and `Booking::PAYMENT_METHODS` plus the
 * request validation already constrain what can be written. An enum would turn
 * "the client also wants Maya" into a schema migration on a live table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('payment_method', 20)->nullable()->after('payment_reference');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
