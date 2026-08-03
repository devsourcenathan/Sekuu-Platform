<?php

declare(strict_types=1);

namespace Modules\Notify\Domain;

final class Channel
{
    public const EMAIL = 'email';

    public const SMS = 'sms';

    public const WHATSAPP = 'whatsapp';

    public const PUSH = 'push';

    public const IN_APP = 'in_app';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::EMAIL, self::SMS, self::WHATSAPP, self::PUSH, self::IN_APP];
    }
}
