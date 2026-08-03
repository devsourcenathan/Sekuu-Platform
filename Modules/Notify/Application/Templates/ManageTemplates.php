<?php

declare(strict_types=1);

namespace Modules\Notify\Application\Templates;

use App\Platform\Exceptions\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Notify\Domain\Category;
use Modules\Notify\Domain\Models\NotificationTemplate;

/**
 * Gestion des variantes de template d'une organisation.
 *
 * Les templates de plateforme sont versionnés avec le code, comme les
 * migrations : ils ne sont jamais modifiables par l'API. Une organisation en
 * crée une variante portant la même clé, qui prend alors le pas.
 *
 * @see docs/03-services/notify/02-data-model.md
 */
final class ManageTemplates
{
    /**
     * @param  array{key: string, channel: string, category?: string, variables?: array, contents: array}  $attributes
     */
    public function create(string $organizationId, array $attributes): NotificationTemplate
    {
        $category = $this->categoryFor($attributes['key'], $attributes['channel'], $attributes['category'] ?? null);

        return DB::transaction(function () use ($organizationId, $attributes, $category): NotificationTemplate {
            try {
                $template = NotificationTemplate::create([
                    'organization_id' => $organizationId,
                    'key' => $attributes['key'],
                    'channel' => $attributes['channel'],
                    'category' => $category,
                    'variables' => $attributes['variables'] ?? [],
                    'status' => 'active',
                ]);
            } catch (QueryException $e) {
                if ($e->getCode() === '23505' || str_contains(strtolower($e->getMessage()), 'unique')) {
                    throw DomainException::conflict(
                        'DUPLICATE_RESOURCE',
                        __('notify::messages.template_variant_exists'),
                    );
                }

                throw $e;
            }

            $this->replaceContents($template, $attributes['contents']);

            return $template->load('contents');
        });
    }

    /**
     * @param  array{variables?: array, contents?: array, status?: string}  $attributes
     */
    public function update(NotificationTemplate $template, array $attributes): NotificationTemplate
    {
        $this->assertEditable($template);

        return DB::transaction(function () use ($template, $attributes): NotificationTemplate {
            $template->fill(array_intersect_key($attributes, array_flip(['variables', 'status'])));

            // Le numéro de version rend visible qu'un template a changé, sans
            // quoi un message rendu avec l'ancienne version serait
            // indistinguable.
            $template->version = $template->version + 1;
            $template->save();

            if (isset($attributes['contents'])) {
                $this->replaceContents($template, $attributes['contents']);
            }

            return $template->fresh(['contents']);
        });
    }

    public function delete(NotificationTemplate $template): void
    {
        $this->assertEditable($template);

        // Archivage plutôt que suppression : `notifications.template_id`
        // référence ce template, et des messages déjà envoyés y renvoient.
        $template->forceFill(['status' => 'archived'])->save();
    }

    /**
     * La catégorie d'une variante ne peut pas différer de celle du template de
     * plateforme qu'elle remplace.
     *
     * Sans cette règle, une organisation requalifierait ses invitations en
     * `transactional` et contournerait le désabonnement — exactement ce que
     * l'ADR-0006 interdit à un émetteur.
     */
    private function categoryFor(string $key, string $channel, ?string $requested): string
    {
        $platform = NotificationTemplate::query()
            ->whereNull('organization_id')
            ->where('key', $key)
            ->where('channel', $channel)
            ->first();

        if ($platform !== null) {
            return $platform->category;
        }

        // Clé inédite : le transactionnel reste réservé à la plateforme. Une
        // organisation ne décide pas seule qu'un message ne peut plus être
        // refusé par son destinataire.
        if ($requested === null || ! Category::isOptional($requested)) {
            throw DomainException::unprocessable(
                'VALIDATION_ERROR',
                __('notify::messages.template_category_reserved'),
            );
        }

        return $requested;
    }

    private function assertEditable(NotificationTemplate $template): void
    {
        if ($template->isPlatformTemplate()) {
            throw DomainException::forbidden(
                'TEMPLATE_READ_ONLY',
                __('notify::messages.template_read_only'),
            );
        }
    }

    /**
     * @param  array<int, array{locale: string, subject?: string, body: string}>  $contents
     */
    private function replaceContents(NotificationTemplate $template, array $contents): void
    {
        $template->contents()->delete();

        foreach ($contents as $content) {
            $template->contents()->create([
                'locale' => $content['locale'],
                'subject' => $content['subject'] ?? null,
                'body' => $content['body'],
            ]);
        }
    }
}
