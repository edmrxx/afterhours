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

    /* ------------------------------------------------------------------ */
    /* Categories                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * What a court costs is decided by its category, not by the court itself:
     * the full-size courts all charge one rate pair and the Skinny Court
     * another, so the rate table in settings is keyed by these values (see
     * App\Services\PricingService). Adding a third full-size court is therefore
     * a court record, not a pricing change.
     */
    public const CATEGORY_NORMAL = 'normal';

    public const CATEGORY_SKINNY = 'skinny';

    /**
     * Every category, in display order, mapped to the label the admin and the
     * public site show. The single source of truth: the migration default, the
     * form dropdown, the settings rate table and the request validation all
     * read this rather than repeating the strings.
     *
     * @var array<string, string>
     */
    public const CATEGORIES = [
        self::CATEGORY_NORMAL => 'Normal Court',
        self::CATEGORY_SKINNY => 'Skinny Court',
    ];

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'code',
        'category',
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

    /**
     * The court's category, guaranteed to be one this build knows about.
     *
     * Never trusts the column blindly: a row written before the category
     * existed, or one left behind by a rolled-back deploy, would otherwise
     * resolve to no rate at all. Anything unrecognised reads as a normal
     * court — the safe default, since that is the more expensive tier and an
     * operator noticing "this is priced too high" is a far better failure than
     * silently selling a full-size court at Skinny Court money.
     *
     * @return 'normal'|'skinny'
     */
    public function categoryKey(): string
    {
        $value = (string) $this->getAttribute('category');

        return array_key_exists($value, self::CATEGORIES) ? $value : self::CATEGORY_NORMAL;
    }

    /** The human label for this court's category, e.g. "Skinny Court". */
    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->categoryKey()];
    }

    /** The label for any category key, falling back to the normal one. */
    public static function labelForCategory(?string $category): string
    {
        return self::CATEGORIES[$category] ?? self::CATEGORIES[self::CATEGORY_NORMAL];
    }

    /** @return list<string> */
    public static function categoryKeys(): array
    {
        return array_keys(self::CATEGORIES);
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
