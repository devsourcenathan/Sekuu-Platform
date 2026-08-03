<?php

declare(strict_types=1);

namespace Modules\Notify\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class NotificationTemplate extends Model
{
    use HasUuids;

    protected $fillable = [
        'key',
        'channel',
        'category',
        'organization_id',
        'provider_ref',
        'variables',
        'status',
        'version',
    ];

    protected function casts(): array
    {
        return ['variables' => 'array'];
    }

    public function contents(): HasMany
    {
        return $this->hasMany(NotificationTemplateContent::class, 'template_id');
    }

    public function isPlatformTemplate(): bool
    {
        return $this->organization_id === null;
    }

    /**
     * Variables déclarées obligatoires. C'est ce qui permet de rejeter un envoi
     * incomplet avant la mise en file, plutôt que de produire un message
     * amputé.
     *
     * @return list<string>
     */
    public function requiredVariables(): array
    {
        return array_values(array_map(
            static fn (array $v) => $v['name'],
            array_filter($this->variables ?? [], static fn (array $v) => (bool) ($v['required'] ?? false)),
        ));
    }

    /**
     * Contenu dans la première langue disponible, par ordre de préférence.
     *
     * @param  list<string>  $locales
     */
    public function contentFor(array $locales): ?NotificationTemplateContent
    {
        $available = $this->contents->keyBy('locale');

        foreach ($locales as $locale) {
            if ($locale !== null && $available->has($locale)) {
                return $available->get($locale);
            }
        }

        return null;
    }
}
