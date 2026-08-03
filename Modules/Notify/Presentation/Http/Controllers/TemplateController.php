<?php

declare(strict_types=1);

namespace Modules\Notify\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Infrastructure\Auth\ApiKeyResolver;
use Modules\Notify\Application\Templates\ManageTemplates;
use Modules\Notify\Application\Templates\TemplateRenderer;
use Modules\Notify\Application\Templates\TemplateResolver;
use Modules\Notify\Domain\Models\NotificationTemplate;
use Modules\Notify\Domain\Models\NotificationTemplateContent;
use Modules\Notify\Presentation\Http\Requests\CreateTemplateRequest;
use Modules\Notify\Presentation\Http\Requests\PreviewTemplateRequest;
use Modules\Notify\Presentation\Http\Requests\UpdateTemplateRequest;

/**
 * @see docs/03-services/notify/03-api.md
 */
final class TemplateController
{
    public function __construct(private readonly ApiKeyResolver $keys) {}

    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->keys->require($request, 'notifications.read')->organizationId();

        $templates = NotificationTemplate::query()
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId))
            ->orderBy('key')
            ->orderBy('channel')
            ->with('contents')
            ->get()
            ->map(fn (NotificationTemplate $t) => $this->present($t, $organizationId))
            ->all();

        return ApiResponse::success($templates);
    }

    public function show(Request $request, string $templateId): JsonResponse
    {
        $organizationId = $this->keys->require($request, 'notifications.read')->organizationId();

        $template = $this->find($templateId, $organizationId);

        return ApiResponse::success(
            $this->present($template, $organizationId) + [
                'contents' => $template->contents->map(fn (NotificationTemplateContent $c) => [
                    'locale' => $c->locale,
                    'subject' => $c->subject,
                    'body' => $c->body,
                ])->all(),
            ]
        );
    }

    public function store(CreateTemplateRequest $request, ManageTemplates $templates): JsonResponse
    {
        $organizationId = $this->keys->require($request, 'notifications.manage')->organizationId();

        $template = $templates->create($organizationId, $request->validated());

        return ApiResponse::created($this->present($template, $organizationId));
    }

    public function update(
        UpdateTemplateRequest $request,
        ManageTemplates $templates,
        string $templateId,
    ): JsonResponse {
        $organizationId = $this->keys->require($request, 'notifications.manage')->organizationId();

        $template = $templates->update($this->find($templateId, $organizationId), $request->validated());

        return ApiResponse::success($this->present($template, $organizationId));
    }

    public function destroy(Request $request, ManageTemplates $templates, string $templateId): JsonResponse
    {
        $organizationId = $this->keys->require($request, 'notifications.manage')->organizationId();

        $templates->delete($this->find($templateId, $organizationId));

        return ApiResponse::noContent();
    }

    /**
     * Rendu à blanc : rien n'est envoyé, rien n'est enregistré.
     *
     * C'est le seul moyen honnête de vérifier un template avant de l'exposer à
     * de vrais destinataires.
     */
    public function preview(
        PreviewTemplateRequest $request,
        TemplateResolver $resolver,
        TemplateRenderer $renderer,
        string $templateId,
    ): JsonResponse {
        $organizationId = $this->keys->require($request, 'notifications.manage')->organizationId();

        $template = $this->find($templateId, $organizationId);

        $rendered = $renderer->render(
            $template,
            $resolver->localeChain($request->input('locale')),
            (array) $request->input('variables', []),
        );

        return ApiResponse::success([
            'locale' => $rendered->locale,
            'subject' => $rendered->subject,
            'body' => $rendered->body,
        ]);
    }

    /**
     * Un template d'une autre organisation est indiscernable d'un template
     * inexistant.
     */
    private function find(string $templateId, string $organizationId): NotificationTemplate
    {
        $template = NotificationTemplate::query()
            ->whereKey($templateId)
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId))
            ->with('contents')
            ->first();

        if ($template === null) {
            throw DomainException::notFound(
                'TEMPLATE_NOT_FOUND',
                __('notify::messages.template_not_found', ['key' => $templateId]),
            );
        }

        return $template;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(NotificationTemplate $template, string $organizationId): array
    {
        return [
            'id' => $template->id,
            'key' => $template->key,
            'channel' => $template->channel,
            'category' => $template->category,
            'status' => $template->status,
            'version' => $template->version,
            'variables' => $template->variables ?? [],
            'locales' => $template->contents->pluck('locale')->values()->all(),
            // Distingue le catalogue de plateforme des variantes propres à
            // l'organisation, que seules celles-ci peuvent modifier.
            'scope' => $template->isPlatformTemplate() ? 'platform' : 'organization',
            'editable' => ! $template->isPlatformTemplate(),
        ];
    }
}
