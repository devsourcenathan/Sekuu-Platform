<?php

declare(strict_types=1);

namespace Modules\Notify\Application\Templates;

use App\Platform\Exceptions\DomainException;
use Modules\Notify\Domain\Models\NotificationTemplate;

/**
 * Résout la clé d'un message en template concret.
 *
 * @see docs/03-services/notify/02-data-model.md
 */
final class TemplateResolver
{
    public function resolve(string $key, ?string $organizationId = null): NotificationTemplate
    {
        // Le template de l'organisation prime sur celui de la plateforme :
        // c'est ce qui permet à une entreprise d'habiller ses messages sans
        // dupliquer le catalogue.
        $template = NotificationTemplate::query()
            ->where('key', $key)
            ->where('status', 'active')
            ->when(
                $organizationId !== null,
                fn ($q) => $q->where(
                    fn ($sub) => $sub->where('organization_id', $organizationId)->orWhereNull('organization_id')
                ),
                fn ($q) => $q->whereNull('organization_id'),
            )
            ->orderByRaw('organization_id IS NULL')
            ->with('contents')
            ->first();

        if ($template === null) {
            throw DomainException::notFound(
                'TEMPLATE_NOT_FOUND',
                __('No template is registered for :key.', ['key' => $key]),
            );
        }

        return $template;
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
