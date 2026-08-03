<?php

declare(strict_types=1);

namespace Modules\Notify\Application\Templates;

use App\Platform\Exceptions\DomainException;
use Modules\Notify\Domain\Models\NotificationTemplate;

/**
 * Rend un template avec ses variables.
 *
 * Volontairement limité à une substitution `{{ variable }}` : les templates
 * peuvent être édités par des utilisateurs via l'API, et un moteur exécutant du
 * code — Blade, Twig — transformerait cette édition en exécution de code
 * arbitraire côté serveur.
 */
final class TemplateRenderer
{
    /**
     * @param  list<string>  $locales
     * @param  array<string, mixed>  $variables
     */
    public function render(NotificationTemplate $template, array $locales, array $variables): RenderedMessage
    {
        $this->assertVariablesAreComplete($template, $variables);

        $content = $template->contentFor($locales);

        if ($content === null) {
            throw DomainException::notFound(
                'TEMPLATE_NOT_FOUND',
                __('notify::messages.template_no_content', ['key' => $template->key]),
            );
        }

        return new RenderedMessage(
            locale: $content->locale,
            subject: $content->subject === null ? null : $this->substitute($content->subject, $variables),
            body: $this->substitute($content->body, $variables),
        );
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function assertVariablesAreComplete(NotificationTemplate $template, array $variables): void
    {
        $missing = array_values(array_filter(
            $template->requiredVariables(),
            static fn (string $name) => ! isset($variables[$name]) || $variables[$name] === '',
        ));

        if ($missing !== []) {
            throw DomainException::unprocessable(
                'TEMPLATE_VARIABLE_MISSING',
                __('notify::messages.template_variables_missing', ['names' => implode(', ', $missing)]),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function substitute(string $template, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            static function (array $match) use ($variables): string {
                $value = data_get($variables, $match[1]);

                // Une variable non fournie et non obligatoire disparaît, plutôt
                // que de laisser une accolade dans le message.
                return $value === null ? '' : (string) $value;
            },
            $template,
        );
    }
}
