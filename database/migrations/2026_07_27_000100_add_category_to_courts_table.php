<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Court categories.
 *
 * Until now every court charged the same: pricing was a single club-wide rate
 * table and the court row carried no rate of its own. After Hours Cebu prices
 * two different kinds of court — the full-size Courts 1 and 2, and the much
 * cheaper Skinny Court — so "which rate does this court charge" becomes a
 * property of the court.
 *
 * The category is a plain string rather than a foreign key to a categories
 * table: there are exactly two, they are named in App\Models\Court::CATEGORIES,
 * and each one's rates live in settings alongside the peak window they share.
 * A lookup table would buy nothing today and cost a join on every price read.
 *
 * `normal` is the default on purpose — every court that already exists is a
 * full-size court, so this migration needs no backfill and no court changes
 * price the moment it runs. An operator marks the Skinny Court by hand
 * afterwards, which is the one thing the database cannot infer.
 *
 * Also drops three dead columns. `morning_rate`, `afternoon_rate` and
 * `evening_rate` were added in 2026_07_21_000100 for per-court time-of-day
 * pricing that the club-wide rate table replaced before it ever shipped: they
 * are NULL on every row and referenced by nothing outside their own migration.
 * Leaving three unused money columns next to a live pricing change is how the
 * next person ends up editing the wrong one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courts', function (Blueprint $table): void {
            // Indexed: the public grid and the bulk reprice both group courts by
            // category, and two distinct values still beat a full scan here.
            $table->string('category', 20)->default('normal')->after('code')->index();
        });

        // Separate statement from the add: MySQL is happy either way, but a
        // dropColumn sharing a Blueprint with other changes is a known source of
        // driver-specific surprises, and this migration is not worth the risk.
        Schema::table('courts', function (Blueprint $table): void {
            $table->dropColumn(['morning_rate', 'afternoon_rate', 'evening_rate']);
        });
    }

    public function down(): void
    {
        Schema::table('courts', function (Blueprint $table): void {
            $table->decimal('morning_rate', 10, 2)->nullable()->after('base_price');
            $table->decimal('afternoon_rate', 10, 2)->nullable()->after('morning_rate');
            $table->decimal('evening_rate', 10, 2)->nullable()->after('afternoon_rate');
        });

        Schema::table('courts', function (Blueprint $table): void {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }
};
