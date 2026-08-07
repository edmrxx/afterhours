<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a booking came from, and who keyed it in.
 *
 * Until now there was exactly one way to create a booking — a guest walking
 * the public checkout — so "how did this get here?" had a single answer and
 * nothing needed recording. The desk can now key a booking in directly for a
 * customer who arranged it over chat, which splits that answer in two, and
 * every screen that totals money or audits a decision needs to be able to
 * tell the two apart.
 *
 * `source` defaults to 'online' and is deliberately NOT backfilled to
 * anything else: every row that exists when this migration runs really did
 * come through the public site, so the default is the truth for all of them,
 * not a guess. New desk-made bookings write 'manual' explicitly.
 *
 * A plain string rather than an enum, for the same reason `payment_method`
 * is one: `Booking::SOURCES` and the request validation already constrain
 * what can be written, and an enum would turn any future third origin (an
 * imported schedule, a partner API) into a schema migration on a live table.
 *
 * `created_by` is nullable and stays null for public bookings — there is no
 * staff member behind a guest checkout, and stamping one would be a lie. It
 * nulls rather than cascades on user deletion: a booking must outlive the
 * account of whoever keyed it in, exactly as `confirmed_by` already does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('source', 20)->default('online')->after('status');

            $table->foreignId('created_by')->nullable()->after('confirmed_by')
                ->constrained('users')->nullOnDelete();

            // Low cardinality, but the query that matters asks for the
            // MINORITY value — "show me the manual ones" — which is exactly
            // the shape an index on a lopsided column does help.
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn('source');
        });
    }
};
