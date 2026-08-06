<?php

declare(strict_types=1);

namespace Modules\AI\Infrastructure\External;

use RuntimeException;

/**
 * Le produit a répondu autre chose qu'un `2xx`.
 *
 * Une exception distincte, et non un `return` silencieux : c'est ce qui fait
 * réessayer la file. Une livraison qui échoue sans le dire serait une sortie
 * que le produit n'apprend jamais — et qu'il a payée.
 */
final class DeliveryRefused extends RuntimeException {}
