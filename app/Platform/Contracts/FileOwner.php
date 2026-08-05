<?php

declare(strict_types=1);

namespace App\Platform\Contracts;

/**
 * Le propriétaire d'un objet auquel des fichiers se rattachent.
 *
 * Implémenté par le module qui possède l'objet — Billing pour une facture,
 * Learn pour une leçon — et résolu par `owner_type`. La couche de stockage ne
 * connaît aucune implémentation : elle les résout par configuration, dans
 * l'esprit du registre d'objets payables.
 *
 * L'invariant que ce contrat porte est celui de {@see PayableSource},
 * transposé : « seul le propriétaire de l'objet nomme son prix » devient **seul
 * le propriétaire de l'objet dit qui peut le lire**. La couche de stockage ne
 * peut pas trancher cette question, elle ne sait rien des rôles ; y recopier
 * les règles d'accès de chaque module produirait des copies qui divergeraient.
 *
 * @see docs/03-services/storage/05-integration.md
 */
interface FileOwner
{
    /**
     * Cet acteur peut-il attacher un fichier à cet objet, et sous quelles
     * bornes ?
     *
     * **Sans effet de bord et idempotente** : appelée à chaque déclaration, y
     * compris sur un objet qui porte déjà des fichiers.
     */
    public function policy(FileRef $ref, FileActor $actor): FilePolicy;

    /**
     * Cet acteur peut-il lire ce fichier ?
     *
     * Un refus rend `FILE_NOT_FOUND`, jamais `403` : distinguer « inexistant »
     * de « pas à vous » transformerait la route en oracle, et permettrait
     * d'énumérer les identifiants pour apprendre ce qui existe chez autrui.
     */
    public function mayRead(FileRef $ref, FileActor $actor): bool;

    /**
     * Les octets sont constatés.
     *
     * Appelée **dans la transaction** de confirmation, et non par un
     * événement : confier ce moment à une file créerait une fenêtre où le
     * fichier est prêt et l'objet l'ignore, qu'un consommateur en échec
     * définitif rendrait permanente.
     *
     * Doit être **idempotente** : une confirmation peut arriver deux fois.
     */
    public function attached(AttachedFile $file): void;

    /**
     * Le fichier a été supprimé.
     *
     * Existe pour que le propriétaire puisse retirer sa référence dans ses
     * propres termes. Sans elle, un objet garderait le souvenir d'un fichier
     * qui n'existe plus, et rien ne le signalerait.
     */
    public function detached(AttachedFile $file): void;
}
