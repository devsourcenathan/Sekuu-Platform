<?php

declare(strict_types=1);

namespace App\Platform\Contracts;

use App\Platform\Support\Money;

/**
 * Un objet payable dont le propriétaire accepte de rendre l'argent.
 *
 * ## Pourquoi une interface distincte de `PayableSource`
 *
 * Parce que **ne pas la porter est une réponse**, et la bonne pour la plupart
 * des produits.
 *
 * Billing ne rembourse pas : un trop-perçu devient un **crédit** imputé au
 * prochain paiement ([ADR-0007](../../../docs/04-decisions/adr-0007-mobile-money-prepaid-subscriptions.md)).
 * Ce n'est pas une lacune, c'est une décision — un remboursement Mobile Money
 * est lent, coûteux et souvent manuel, alors que le client d'un abonnement
 * repassera à la caisse le mois suivant.
 *
 * L'ajouter à `PayableSource` aurait forcé chaque produit à écrire une méthode
 * pour dire non, et la première implémentation copiée-collée aurait dit oui par
 * distraction. Ici, le défaut est le refus, et il est structurel :
 * `REFUND_NOT_SUPPORTED` échoue durement.
 *
 * ## L'inversion est la même que pour le prix
 *
 * La couche de paiement ne peut pas savoir si un remboursement est justifié —
 * la formation a-t-elle été suivie, la prestation rendue ? Elle demande donc au
 * propriétaire, exactement comme elle lui demande le montant.
 *
 * Ce qu'elle garde pour elle, en revanche, c'est le plafond : on ne rembourse
 * jamais plus que ce qui a été **réellement encaissé**, et cette vérification
 * ne dépend d'aucun produit.
 *
 * @see docs/03-services/payments/08-refunds.md
 */
interface RefundableSource extends PayableSource
{
    /**
     * Ce propriétaire accepte-t-il de rendre cette somme sur cet objet ?
     *
     * Appelée **avant** toute écriture, et sans effet de bord : elle peut être
     * appelée plusieurs fois pour le même objet.
     */
    public function refundable(PayableRef $ref, Money $requested): RefundDecision;

    /**
     * Le décaissement est constaté.
     *
     * Appelée **dans la transaction** qui écrit la ligne de registre, pour la
     * même raison que `settled()` : confier ce moment à une file créerait une
     * fenêtre où l'argent est rendu et l'accès toujours ouvert.
     *
     * Doit être **idempotente**. Un décaissement peut être constaté deux fois —
     * par un opérateur puis par un rapprochement.
     */
    public function refunded(RefundSettlement $settlement): void;
}
