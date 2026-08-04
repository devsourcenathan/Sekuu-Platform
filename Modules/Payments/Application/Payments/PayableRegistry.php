<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Payments;

use App\Platform\Contracts\PayableSource;
use App\Platform\Exceptions\DomainException;
use Illuminate\Contracts\Container\Container;

/**
 * Résolution `subject_type` → propriétaire de l'objet.
 *
 * Par configuration, jamais par appel croisé : la couche de paiement n'importe
 * aucune implémentation, et un produit s'enregistre sans qu'elle change.
 *
 * Un type inconnu échoue **durement**. Un repli silencieux ferait aboutir un
 * paiement que personne ne saurait rattacher — de l'argent encaissé sans
 * service rendu, la défaillance que ce module existe pour empêcher.
 */
final class PayableRegistry
{
    /** @var array<string, class-string<PayableSource>> */
    private array $sources = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<PayableSource>  $source
     */
    public function register(string $subjectType, string $source): void
    {
        $this->sources[$subjectType] = $source;
    }

    public function for(string $subjectType): PayableSource
    {
        $source = $this->sources[$subjectType] ?? null;

        if ($source === null) {
            throw DomainException::unprocessable(
                'PAYABLE_TYPE_UNKNOWN',
                __('payments::messages.payable_type_unknown', ['type' => $subjectType]),
            );
        }

        return $this->container->make($source);
    }

    public function knows(string $subjectType): bool
    {
        return isset($this->sources[$subjectType]);
    }
}
