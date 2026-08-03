<?php

declare(strict_types=1);

namespace Modules\Notify\Infrastructure\Providers;

use Illuminate\Support\Facades\Http;
use Modules\Notify\Domain\Channel;
use Modules\Notify\Domain\Models\Notification;
use Throwable;

/**
 * Fournisseur SMS international, utilisé en bascule lorsque la passerelle
 * locale est injoignable.
 */
final class TwilioSmsProvider implements MessageProvider
{
    public function name(): string
    {
        return 'twilio';
    }

    public function channel(): string
    {
        return Channel::SMS;
    }

    public function send(Notification $notification): ProviderResult
    {
        $config = (array) config('notify.sms.twilio');

        if (empty($config['account_sid']) || empty($config['token'])) {
            return ProviderResult::failed('CHANNEL_NOT_CONFIGURED', 'Twilio is not configured.');
        }

        $endpoint = sprintf(
            'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json',
            $config['account_sid'],
        );

        try {
            $response = Http::withBasicAuth($config['account_sid'], $config['token'])
                ->timeout((int) ($config['timeout'] ?? 10))
                ->asForm()
                ->post($endpoint, [
                    'To' => $notification->recipient,
                    'From' => $config['from'] ?? '',
                    'Body' => $notification->rendered_body,
                ]);
        } catch (Throwable $e) {
            return ProviderResult::failed('PROVIDER_ERROR', $e->getMessage());
        }

        if ($response->successful()) {
            return ProviderResult::accepted(
                messageId: (string) $response->json('sid'),
                cost: $response->json('price') === null ? null : abs((float) $response->json('price')),
                currency: $response->json('price_unit'),
            );
        }

        if ($response->clientError()) {
            return ProviderResult::rejected('PROVIDER_ERROR', (string) $response->json('message'));
        }

        return ProviderResult::failed('PROVIDER_ERROR', 'Twilio returned '.$response->status());
    }
}
