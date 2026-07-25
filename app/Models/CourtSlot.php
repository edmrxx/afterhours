<?php

namespace App\Models;

use App\Traits\Auditable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CourtSlot extends Model
{
    /** @use HasFactory<\Database\Factories\CourtSlotFactory> */
    use Auditable, HasFactory;

    protected static string $auditModule = 'Slots';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_HELD = 'held';

    public const STATUS_BOOKED = 'booked';

    public const STATUS_BLOCKED = 'blocked';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_AVAILABLE,
        self::STATUS_HELD,
        self::STATUS_BOOKED,
        self::STATUS_BLOCKED,
    ];

    /** @var list<string> */
    protected $fillable = [
        'court_id',
        'slot_date',
        'start_time',
        'end_time',
        'price',
        'status',
        'held_booking_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'slot_date' => 'date',
            'price' => 'decimal:2',
            'court_id' => 'integer',
            'held_booking_id' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Relationships                                                       */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Court, $this> */
    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    /**
     * The booking currently sitting on this slot, if any.
     *
     * @return BelongsTo<Booking, $this>
     */
    public function heldBooking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'held_booking_id');
    }

    /**
     * Full booking history for the slot, including released attempts.
     *
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'court_slot_id');
    }

    /* ------------------------------------------------------------------ */
    /* Scopes                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_AVAILABLE);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForDate(Builder $query, CarbonInterface|string $date): Builder
    {
        return $query->whereDate('slot_date', Carbon::parse((string) $date)->toDateString());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForCourt(Builder $query, Court|int $court): Builder
    {
        return $query->where('court_id', $court instanceof Court ? $court->getKey() : $court);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeBetweenDates(Builder $query, CarbonInterface|string $from, CarbonInterface|string $to): Builder
    {
        return $query->whereBetween('slot_date', [
            Carbon::parse((string) $from)->toDateString(),
            Carbon::parse((string) $to)->toDateString(),
        ]);
    }

    /**
     * Slots that have not started yet: any future date, plus today's slots
     * whose start time is still ahead of the clock.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        $now = Carbon::now();
        $today = $now->toDateString();
        $time = $now->format('H:i:s');

        return $query->where(function (Builder $q) use ($today, $time): void {
            $q->whereDate('slot_date', '>', $today)
                ->orWhere(function (Builder $inner) use ($today, $time): void {
                    $inner->whereDate('slot_date', '=', $today)
                        ->where('start_time', '>', $time);
                });
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePast(Builder $query): Builder
    {
        $now = Carbon::now();
        $today = $now->toDateString();
        $time = $now->format('H:i:s');

        return $query->where(function (Builder $q) use ($today, $time): void {
            $q->whereDate('slot_date', '<', $today)
                ->orWhere(function (Builder $inner) use ($today, $time): void {
                    $inner->whereDate('slot_date', '=', $today)
                        ->where('start_time', '<=', $time);
                });
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('slot_date')->orderBy('start_time');
    }

    /**
     * Slots no booking has ever touched — the only ones the database will
     * allow a hard DELETE on. Both `bookings.court_slot_id` and the
     * `booking_slots` pivot are declared `restrictOnDelete`: a slot that ever
     * carried a reservation (even one since cancelled or expired) is pinned
     * forever as that booking's history, and deleting it throws a foreign key
     * violation. Every bulk or manual delete path must filter through this.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithoutBookingHistory(Builder $query): Builder
    {
        return $query
            ->whereNotExists(
                fn ($sub) => $sub->select(DB::raw(1))
                    ->from('bookings')
                    ->whereColumn('bookings.court_slot_id', 'court_slots.id')
            )
            ->whereNotExists(
                fn ($sub) => $sub->select(DB::raw(1))
                    ->from('booking_slots')
                    ->whereColumn('booking_slots.court_slot_id', 'court_slots.id')
            );
    }

    /**
     * The complement of scopeWithoutBookingHistory(): slots a booking (live or
     * released) references and the database therefore refuses to delete. Used to
     * park exactly the pinned rows as `blocked`, so a concurrently-inserted
     * history-free row between a delete and the block update can never be swept
     * up by mistake.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithBookingHistory(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereExists(
                fn ($sub) => $sub->select(DB::raw(1))
                    ->from('bookings')
                    ->whereColumn('bookings.court_slot_id', 'court_slots.id')
            )->orWhereExists(
                fn ($sub) => $sub->select(DB::raw(1))
                    ->from('booking_slots')
                    ->whereColumn('booking_slots.court_slot_id', 'court_slots.id')
            );
        });
    }

    /**
     * Does any booking — live or long since released — reference this slot,
     * whether as the primary `bookings.court_slot_id` or through the durable
     * `booking_slots` pivot (a multi-slot booking's secondary slots live only in
     * the pivot)? The single-row mirror of scopeWithoutBookingHistory().
     */
    public function hasBookingHistory(): bool
    {
        return $this->bookings()->exists()
            || DB::table('booking_slots')->where('court_slot_id', $this->getKey())->exists();
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Can a customer claim this slot right now? Availability alone is not
     * enough — a slot that has already started is dead stock.
     */
    public function isBookable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE && ! $this->hasStarted();
    }

    public function hasStarted(): bool
    {
        return $this->startsAt()->isPast();
    }

    /**
     * Absolute start instant, combining slot_date and start_time.
     */
    public function startsAt(): Carbon
    {
        return Carbon::parse($this->rawDateString().' '.$this->rawTimeString('start_time'));
    }

    public function endsAt(): Carbon
    {
        $start = $this->startsAt();
        $end = Carbon::parse($this->rawDateString().' '.$this->rawTimeString('end_time'));

        // An end time earlier than the start means the slot runs past midnight.
        return $end->lessThanOrEqualTo($start) ? $end->addDay() : $end;
    }

    /**
     * @return Attribute<int, never>
     */
    protected function durationMinutes(): Attribute
    {
        return Attribute::make(
            get: fn (): int => (int) round($this->startsAt()->diffInMinutes($this->endsAt(), true))
        )->shouldCache();
    }

    /**
     * @return Attribute<string, never>
     */
    protected function timeRange(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->startsAt()->format('g:i A').' – '.$this->endsAt()->format('g:i A')
        )->shouldCache();
    }

    protected function rawDateString(): string
    {
        $value = $this->attributes['slot_date'] ?? null;

        return $value === null
            ? Carbon::now()->toDateString()
            : Carbon::parse((string) $value)->toDateString();
    }

    protected function rawTimeString(string $key): string
    {
        $value = (string) ($this->attributes[$key] ?? '00:00:00');

        // MySQL hands back H:i:s; a full datetime can arrive from raw selects.
        if (str_contains($value, ' ')) {
            $value = (string) substr(strrchr($value, ' ') ?: '', 1);
        }

        return $value === '' ? '00:00:00' : $value;
    }
}
