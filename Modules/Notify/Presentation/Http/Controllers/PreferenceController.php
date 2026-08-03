<?php

declare(strict_types=1);

namespace Modules\Notify\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Identity\Domain\AuthenticatedContext;
use Modules\Notify\Domain\Category;
use Modules\Notify\Domain\Channel;
use Modules\Notify\Domain\Models\NotificationPreference;
use Modules\Notify\Presentation\Http\Requests\UpdatePreferencesRequest;

/**
 * @see docs/04-decisions/adr-0006-transactional-vs-marketing.md
 */
final class PreferenceController
{
    public function index(AuthenticatedContext $context): JsonResponse
    {
        $stored = NotificationPreference::query()
            ->where('user_id', $context->user->id)
            ->whereNull('organization_id')
            ->get();

        $preferences = [];

        foreach (Category::all() as $category) {
            foreach ([Channel::EMAIL, Channel::SMS, Channel::WHATSAPP, Channel::PUSH] as $channel) {
                $match = $stored->first(
                    fn (NotificationPreference $p) => $p->category === $category && $p->channel === $channel
                );

                $preferences[] = [
                    'category' => $category,
                    'channel' => $channel,
                    'enabled' => $match?->enabled ?? Category::enabledByDefault($category),
                    // L'interface doit pouvoir griser ce qui n'est pas
                    // modifiable, plutôt que de laisser l'utilisateur découvrir
                    // le refus après coup.
                    'editable' => Category::isOptional($category),
                ];
            }
        }

        return ApiResponse::success($preferences);
    }

    public function update(UpdatePreferencesRequest $request, AuthenticatedContext $context): JsonResponse
    {
        foreach ($request->validated()['preferences'] as $preference) {
            if (! Category::isOptional($preference['category'])) {
                throw DomainException::unprocessable(
                    'TRANSACTIONAL_CANNOT_BE_DISABLED',
                    __('Transactional messages cannot be disabled.'),
                );
            }

            NotificationPreference::query()->updateOrCreate(
                [
                    'user_id' => $context->user->id,
                    'organization_id' => null,
                    'category' => $preference['category'],
                    'channel' => $preference['channel'],
                ],
                ['enabled' => $preference['enabled']],
            );
        }

        return $this->index($context);
    }
}
