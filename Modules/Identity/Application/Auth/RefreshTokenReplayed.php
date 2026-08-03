<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Auth;

use RuntimeException;

/**
 * Signal interne : un refresh token déjà révoqué a été présenté.
 *
 * Il n'est jamais rendu au client. Il sert à sortir de la transaction avant de
 * révoquer la session, car une révocation faite à l'intérieur serait annulée
 * par le rollback qu'entraîne l'exception.
 */
final class RefreshTokenReplayed extends RuntimeException
{
    public function __construct(public readonly ?string $sessionId)
    {
        parent::__construct('Refresh token replay detected.');
    }
}
