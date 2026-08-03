<?php

declare(strict_types=1);

namespace Modules\Notify\Application\Templates;

use App\Platform\Exceptions\DomainException;
use Illuminate\Support\Collection;
use Modules\Notify\Domain\Models\NotificationTemplate;

/**
 * Résout la clé d'un message en templates concrets.
 *
 * Une clé peut exister sur plusieurs canaux — une alerte de sécurité part par
 * email **et** par SMS. La résolution renvoie donc toujours une collection :
 * choisir arbitrairement l'un des canaux serait un bug silencieux.
 *
 * @see docs/03-services/notify/02-data-model.md
 */
final class TemplateResolver
{
    /**
     * Templates actifs pour une clé, un par canal.
     *
     * @return Collection<string, NotificationTemplate> indexée par canal
     */
    public function resolveAll(string $key, ?string $organizationId = null): Collection
    {
        $templates = NotificationTemplate::query()
            ->where('key', $key)
            ->where('status', 'active')
            ->when(
                $organizationId !== null,
                fn ($q) => $q->where(
                    fn ($sub) => $sub->where('organization_id', $organizationId)->orWhereNull('organization_id')
                ),
                fn ($q) => $q->whereNull('organization_id'),
            )
            // La variante de l'organisation prime sur celle de la plateforme :
            // en la plaçant en tête, le regroupement par canal la retient.
            ->orderByRaw('organization_id IS NULL')
            ->with('contents')
            ->get();

        if ($templates->isEmpty()) {
            throw DomainException::notFound(
                'TEMPLATE_NOT_FOUND',
                __('notify::messages.template_not_found', ['key' => $key]),
            );
        }

        return $templates->groupBy('channel')->map(
            fn (Collection $perChannel) => $perChannel->first()
        );
    }

    /**
     * Ordre de repli des langues : demandée → organisation → défaut.
     *
     * @return list<string>
     */
    public function localeChain(?string $requested, ?string $organizationLocale = null): array
    {
        return array_values(array_unique(array_filter([
            $requested,
            $organizationLocale,
            config('app.fallback_locale'),
        ])));
    }
}
