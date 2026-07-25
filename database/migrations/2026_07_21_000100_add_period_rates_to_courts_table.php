<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-court time-of-day rates.
 *
 * Until now the bulk generator asked the operator to type a price on every run,
 * and "mornings are cheaper" only happened because somebody remembered to run
 * the generator three times with three different numbers. These three columns
 * make the rate a property of the court instead of a property of the operator's
 * memory.
 *
 * Every column is NULLABLE on purpose: a null period rate means "use this
 * court's base_price". That is what keeps this migration a pure schema change —
 * no backfill, no data touched, and every existing court prices exactly as it
 * did the moment before it ran, until an admin actually sets a rate.
 *
 * Note what is deliberately absent: any UPDATE against court_slots. Rates are
 * forward-only — they decide the price of rows the generator creates from now
 * on and never reprice a slot that already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courts', function (Blueprint $table) {
            // Same precision as base_price — money is decimal, never float.
            $table->decimal('morning_rate', 10, 2)->nullable()->after('base_price');
            $table->decimal('afternoon_rate', 10, 2)->nullable()->after('morning_rate');
            $table->decimal('evening_rate', 10, 2)->nullable()->after('afternoon_rate');
        });
    }

    public function down(): void
    {
        Schema::table('courts', function (Blueprint $table) {
            $table->dropColumn(['morning_rate', 'afternoon_rate', 'evening_rate']);
        });
    }
};
