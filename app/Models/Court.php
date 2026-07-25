<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Court extends Model
{
    /** @use HasFactory<\Database\Factories\CourtFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected static string $auditModule = 'Courts';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'code',
        'description',
        'photo_path',
        'is_active',
        'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Time-of-day grouping                                                */
    /* ------------------------------------------------------------------ */

    /**
     * The part of the day an hour belongs to — morning before noon, afternoon
     * until 5pm, evening after that.
     *
     * This is purely presentational: the public booking grid chunks its rows
     * into these three bands so a long day reads more easily. It has nothing to
     * do with pricing — that is two tiers (peak/non-peak) and lives in
     * App\Services\PricingService.
     *
     * @return 'morning'|'afternoon'|'evening'
     */
    public static function periodForHour(int $hour): string
    {
        return match (true) {
            $hour < 12 => 'morning',
            $hour < 17 => 'afternoon',
            default => 'evening',
        };
    }

    /**
     * The public site addresses courts by slug, never by id.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected static function booted(): void
    {
        static::creating(function (self $court): void {
            if (blank($court->slug)) {
                $court->slug = static::uniqueSlug((string) $court->name);
            }
        });
    }

    /**
     * Build a slug that is guaranteed free, including against soft-deleted
     * rows — the unique index does not care that a court is in the bin.
     */
    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'court';
        }

        $slug = $base;
        $suffix = 1;

        while (static::slugTaken($slug, $ignoreId)) {
            $suffix++;
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }

    protected static function slugTaken(string $slug, ?int $ignoreId): bool
    {
        return static::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }

    /* ------------------------------------------------------------------ */
    /* Relationships                                                       */
    /* ------------------------------------------------------------------ */

    /** @return HasMany<CourtSlot, $this> */
    public function slots(): HasMany
    {
        return $this->hasMany(CourtSlot::class);
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /* ------------------------------------------------------------------ */
    /* Scopes                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
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
            $q->where('name', 'like', $like)
                ->orWhere('code', 'like', $like)
                ->orWhere('slug', 'like', $like);
        });
    }

    /* ------------------------------------------------------------------ */
    /* Accessors                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Bookable slots still ahead of us. Prefers a `withCount` alias when the
     * list query already loaded it, so this never fires a query per row.
     *
     * @return Attribute<int, never>
     */
    protected function availableSlotsCount(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value, array $attributes): int {
                foreach (['available_slots_count', 'upcoming_available_slots_count', 'slots_count'] as $alias) {
                    if (array_key_exists($alias, $attributes) && $attributes[$alias] !== null) {
                        return (int) $attributes[$alias];
                    }
                }

                if ($this->relationLoaded('slots')) {
                    return $this->slots
                        ->filter(fn (CourtSlot $slot): bool => $slot->isBookable())
                        ->count();
                }

                return $this->slots()->available()->upcoming()->count();
            }
        )->shouldCache();
    }
}
