<?php

declare(strict_types=1);

namespace Modules\Billing\Infrastructure\Webhooks;

use App\Platform\Exceptions\DomainException;
use Illuminate\Contracts\Container\Container;

final class WebhookRegistry
{
    /** @var array<string, class-string<PaymentWebhookHandler>> */
    private array $handlers = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<PaymentWebhookHandler>  $handler
     */
    public function register(string $provider, string $handler): void
    {
        $this->handlers[$provider] = $handler;
    }

    public function for(string $provider): PaymentWebhookHandler
    {
        $handler = $this->handlers[$provider] ?? null;

        if ($handler === null) {
            // 404 et non 400 : un agrégateur inconnu ne doit pas apprendre
            // quels autres endpoints existent.
            throw DomainException::notFound(
                'ENDPOINT_NOT_FOUND',
                __('billing::messages.webhook_provider_unknown'),
            );
        }

        return $this->container->make($handler);
    }
}
