<?php

declare(strict_types=1);

namespace Modules\Notify\Infrastructure\Webhooks;

use App\Platform\Exceptions\DomainException;
use Illuminate\Contracts\Container\Container;

final class WebhookRegistry
{
    /** @var array<string, class-string<WebhookHandler>> */
    private array $handlers = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<WebhookHandler>  $handler
     */
    public function register(string $provider, string $handler): void
    {
        $this->handlers[$provider] = $handler;
    }

    public function for(string $provider): WebhookHandler
    {
        if (! isset($this->handlers[$provider])) {
            throw DomainException::notFound(
                'ENDPOINT_NOT_FOUND',
                __('No webhook handler is registered for :provider.', ['provider' => $provider]),
            );
        }

        return $this->container->make($this->handlers[$provider]);
    }
}
