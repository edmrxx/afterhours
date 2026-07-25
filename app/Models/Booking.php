<?php

namespace App\Models;

use App\Services\SettingsService;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use RuntimeException;

class Booking extends Model
{
    /** @use HasFactory<\Database\Factories\BookingFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected static string $auditModule = 'Bookings';

    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';

    public const STATUS_PENDING_VERIFICATION = 'pending_verification';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_AWAITING_PAYMENT,
        self::STATUS_PENDING_VERIFICATION,
        self::STATUS_CONFIRMED,
        self::STATUS_COMPLETED,
        self::STATUS_REJECTED,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Statuses that still occupy their slot and therefore expire.
     *
     * @var list<string>
     */
    public const HOLDING_STATUSES = [
        self::STATUS_AWAITING_PAYMENT,
        self::STATUS_PENDING_VERIFICATION,
    ];

    public const PAYMENT_METHOD_GCASH = 'gcash';

    public const PAYMENT_METHOD_GOTYME = 'gotyme';

    /**
     * The wallets checkout may offer. GCash is first because it is the method
     * the site has always had and the one every existing booking used.
     *
     * @var list<string>
     */
    public const PAYMENT_METHODS = [
        self::PAYMENT_METHOD_GCASH,
        self::PAYMENT_METHOD_GOTYME,
    ];

    /**
     * Characters that cannot be misread over the phone: no O/0, no I/1.
     */
    private const CODE_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const CODE_LENGTH = 8;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'court_id',
        'court_slot_id',
        'customer_name',
        'customer_phone',
        'customer_email',
        'notes',
        'amount',
        'status',
        'payment_reference',
        'payment_method',
        'payment_proof_path',
        'payment_submitted_at',
        'hold_expires_at',
        'confirmed_at',
        'confirmed_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
        'cancelled_at',
        'ip_address',
        'user_agent',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_submitted_at' => 'datetime',
            'hold_expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'court_id' => 'integer',
            'court_slot_id' => 'integer',
            'confirmed_by' => 'integer',
            'rejected_by' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /* ------------------------------------------------------------------ */
    /* Code generation                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * The prefix new codes are generated with — an admin-set row in the
     * `settings` table (Admin > Settings > Booking) overrides the
     * `config/booking.php` / `.env` default without needing a redeploy.
     *
     * Changing this never rewrites codes already issued: each booking's code
     * is generated once at creation and stored, so old bookings keep
     * whichever prefix was active the day they were made.
     */
    public static function codePrefix(): string
    {
        $stored = app(SettingsService::class)->get(Setting::GROUP_SYSTEM, 'booking_code_prefix');

        return mb_strtoupper((string) ($stored ?: config('booking.code_prefix', 'AH')));
    }

    /**
     * Public booking reference, e.g. AH-K7M4XQ9B. Retries on the (vanishingly
     * unlikely) collision rather than trusting randomness to be unique.
     *
     * @throws RuntimeException when the generator cannot find a free code
     */
    public static function generateCode(): string
    {
        $prefix = self::codePrefix();
        $max = strlen(self::CODE_ALPHABET) - 1;

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $body = '';

            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $body .= self::CODE_ALPHABET[random_int(0, $max)];
            }

            $code = $prefix.'-'.$body;

            // withTrashed: the unique index still holds soft-deleted codes.
            if (! static::withTrashed()->where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException('Unable to generate a unique booking code after 20 attempts.');
    }

    protected static function booted(): void
    {
        static::creating(function (self $booking): void {
            if (blank($booking->code)) {
                $booking->code = static::generateCode();
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /* Relationships                                                       */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Court, $this> */
    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    /** @return BelongsTo<CourtSlot, $this> */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(CourtSlot::class, 'court_slot_id');
    }

    /** @return BelongsTo<CourtSlot, $this> */
    public function courtSlot(): BelongsTo
    {
        return $this->slot();
    }

    /**
     * The authoritative full list of every slot this booking ever held, via
     * the durable `booking_slots` pivot, in chronological order. A booking
     * may span one or several slots — possibly across different courts,
     * possibly non-contiguous.
     *
     * Deliberately NOT keyed on `court_slots.held_booking_id` — that column
     * is a live "currently held by" pointer that reject()/cancel()/expire()
     * null out when a slot goes back on sale, which would make a released
     * booking's own schedule unreadable the moment it stopped holding its
     * slots. `booking_slots` rows are written once at reserve time and never
     * modified or deleted afterward, so this relation stays correct for the
     * booking's entire lifetime regardless of its current status.
     *
     * `slot()` / `courtSlot()` above are unchanged and remain the single
     * "primary" (earliest) slot, kept for backward-compatible singular
     * displays — every existing caller of those relations keeps working
     * unchanged. Use `slots()` whenever the FULL set matters (pricing,
     * confirmation, cancellation, anything that must not silently drop slots
     * 2..n of a multi-slot booking).
     *
     * @return BelongsToMany<CourtSlot, $this>
     */
    public function slots(): BelongsToMany
    {
        return $this->belongsToMany(CourtSlot::class, 'booking_slots')
            ->withTimestamps()
            ->orderBy('slot_date')
            ->orderBy('start_time');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /* ------------------------------------------------------------------ */
    /* Status scopes                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeStatus(Builder $query, string|array|null $status): Builder
    {
        if (blank($status)) {
            return $query;
        }

        return is_array($status)
            ? $query->whereIn('status', $status)
            : $query->where('status', $status);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingPayment(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_AWAITING_PAYMENT);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePendingVerification(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING_VERIFICATION);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CONFIRMED);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_EXPIRED);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    /**
     * Bookings still sitting on their slot, whatever the hold clock says.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeHolding(Builder $query): Builder
    {
        return $query->whereIn('status', self::HOLDING_STATUSES);
    }

    /**
     * Exactly what ReleaseExpiredBookingHolds scans: still holding a slot,
     * hold clock already blown. Matches index(status, hold_expires_at).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExpiredHolds(Builder $query): Builder
    {
        return $query->whereIn('status', self::HOLDING_STATUSES)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<', Carbon::now());
    }

    /**
     * Revenue-bearing bookings — what reports and the dashboard total up.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRevenueBearing(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_CONFIRMED, self::STATUS_COMPLETED]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $term)).'%';

        return $query->where(function (Builder $q) use ($like): void {
            $q->where('code', 'like', $like)
                ->orWhere('customer_name', 'like', $like)
                ->orWhere('customer_phone', 'like', $like)
                ->orWhere('customer_email', 'like', $like)
                ->orWhere('payment_reference', 'like', $like);
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCreatedBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when(filled($from), fn (Builder $q) => $q->whereDate('created_at', '>=', Carbon::parse((string) $from)->toDateString()))
            ->when(filled($to), fn (Builder $q) => $q->whereDate('created_at', '<=', Carbon::parse((string) $to)->toDateString()));
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    public function isHolding(): bool
    {
        return in_array($this->status, self::HOLDING_STATUSES, true);
    }

    public function isHoldExpired(): bool
    {
        return $this->hold_expires_at !== null && $this->hold_expires_at->isPast();
    }

    /**
     * Whether the customer may still walk this booking back themselves.
     */
    public function isCancellable(): bool
    {
        return $this->isHolding();
    }

    /**
     * Display name of the app the guest paid with, e.g. 'GCash'.
     *
     * Null for bookings submitted before checkout offered a choice — and for
     * any value we no longer recognise — because inventing a wallet name for a
     * payment nobody recorded would send staff hunting through the wrong app's
     * history. Callers render null as an em dash, never as a default method.
     */
    public function paymentMethodLabel(): ?string
    {
        return match ($this->payment_method) {
            self::PAYMENT_METHOD_GCASH => 'GCash',
            self::PAYMENT_METHOD_GOTYME => 'GoTyme',
            default => null,
        };
    }

    /**
     * @return Attribute<int|null, never>
     */
    protected function holdSecondsRemaining(): Attribute
    {
        return Attribute::make(
            get: function (): ?int {
                if (! $this->isHolding() || $this->hold_expires_at === null) {
                    return null;
                }

                return max(0, (int) Carbon::now()->diffInSeconds($this->hold_expires_at, false));
            }
        );
    }
}
