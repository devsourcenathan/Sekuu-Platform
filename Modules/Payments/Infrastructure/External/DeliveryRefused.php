<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\External;

use RuntimeException;

/**
 * Le produit a répondu autre chose qu'un `2xx`.
 *
 * Une exception distincte, et non un `return` silencieux : c'est ce qui fait
 * réessayer la file. Une livraison qui échoue sans le dire serait un
 * encaissement que le produit n'apprend jamais.
 */
final class DeliveryRefused extends RuntimeException {}
