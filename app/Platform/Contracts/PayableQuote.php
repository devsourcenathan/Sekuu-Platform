<?php

declare(strict_types=1);

namespace App\Platform\Contracts;

use App\Platform\Support\Money;

/**
 * Ce que le propriétaire d'un objet répond quand on lui demande combien il faut
 * encaisser.
 *
 * ## Pourquoi la couche de paiement demande le montant au lieu de le recevoir
 *
 * La protection « le montant ne vient jamais de l'appelant » était jusqu'ici
 * **structurelle** : aucune signature ne l'acceptait, le contrôleur chargeait la
 * facture en base, et le montant en était tiré. Aucun nombre ne traversait HTTP.
 *
 * Une méthode `encaisser(int $montant, …)` déplacerait ce contrôle d'un
 * invariant vers une convention — et le premier appelant écrirait
 * `$request->integer('amount')`. La faille exacte, d'une couche plus bas.
 *
 * Un objet de valeurs construit par l'appelant serait pire encore : plus
 * falsifiable qu'un modèle chargé côté serveur, en donnant l'illusion d'avoir
 * typé la sécurité.
 *
 * D'où l'inversion. **Le montant est indicible** : il n'existe dans aucune
 * signature accessible à qui déclenche un paiement. Il ne peut être produit que
 * par le propriétaire de l'objet, qui seul sait ce qu'il vaut.
 *
 * ## Et l'autorisation avec
 *
 * `quote()` reçoit le payeur. Le propriétaire refuse un objet que ce payeur
 * n'a pas le droit de régler — la couche de paiement ne peut pas trancher cette
 * question, elle ne sait rien des rôles. Sans cela, connaître un UUID de
 * facture suffirait à déclencher une invite sur le téléphone de quelqu'un.
 */
final readonly class PayableQuote
{
    private function __construct(
        public ?Money $amount,
        public ?string $description,
        public ?string $payeeOrganizationId,
        public ?string $refusalCode,
        public ?string $refusalMessage,
    ) {}

    /**
     * @param  string|null  $payeeOrganizationId  `null` = la plateforme encaisse pour elle-même
     */
    public static function due(
        Money $amount,
        string $description,
        ?string $payeeOrganizationId = null,
    ): self {
        return new self($amount, $description, $payeeOrganizationId, null, null);
    }

    /**
     * Rien à payer : objet déjà réglé, ou gratuit. Ce n'est pas une erreur.
     */
    public static function nothingDue(): self
    {
        return new self(null, null, null, null, null);
    }

    /**
     * Le propriétaire refuse — objet annulé, payeur non habilité, état
     * incompatible. Le code remonte tel quel à l'appelant.
     */
    public static function refused(string $code, string $message): self
    {
        return new self(null, null, null, $code, $message);
    }

    public function isRefused(): bool
    {
        return $this->refusalCode !== null;
    }

    public function isPayable(): bool
    {
        return ! $this->isRefused() && $this->amount !== null && $this->amount->isPositive();
    }
}
