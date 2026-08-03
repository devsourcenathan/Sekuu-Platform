<?php

declare(strict_types=1);

namespace Modules\Notify\Domain\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @see docs/04-decisions/adr-0006-transactional-vs-marketing.md
 */
final class Suppression extends Model
{
    use HasUuids;

    public const HARD_BOUNCE = 'hard_bounce';

    public const COMPLAINT = 'complaint';

    public const UNSUBSCRIBE = 'unsubscribe';

    public const MANUAL = 'manual';

    public const INVALID = 'invalid';

    protected $fillable = [
        'channel',
        'destination',
        'reason',
        'source',
        'notification_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(
            fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())
        );
    }

    /**
     * La normalisation est indispensable : sans elle, `Nathan@Sekuu.com`
     * échapperait à une suppression enregistrée sur `nathan@sekuu.com`.
     */
    public static function normalise(string $destination): string
    {
        return mb_strtolower(trim($destination));
    }
}
