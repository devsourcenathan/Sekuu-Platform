<?php

declare(strict_types=1);

namespace Modules\Billing\Application\Invoicing;

use Illuminate\Support\Facades\DB;

/**
 * Numérotation séquentielle et sans trou.
 *
 * Une facture est un document légal : un trou dans la numérotation est une
 * question à laquelle il faudra répondre lors d'un contrôle.
 *
 * Un UUID ne convient donc pas, et `MAX(number) + 1` produit des doublons sous
 * concurrence. Le numéro vient d'une **séquence PostgreSQL** dédiée par année :
 * elle est transactionnellement sûre et ne recule jamais.
 *
 * Corollaire : une facture émise ne se supprime pas. On l'annule, et le numéro
 * reste consommé.
 */
final class InvoiceNumber
{
    private const PREFIX = 'SKU';

    public static function next(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $sequence = 'invoice_number_'.$year;

        // `IF NOT EXISTS` plutôt qu'une migration par an : personne ne pensera
        // à créer la séquence le 1er janvier.
        DB::statement('CREATE SEQUENCE IF NOT EXISTS '.$sequence.' START 1');

        $value = DB::selectOne('SELECT nextval(?) AS value', [$sequence])->value;

        return sprintf('%s-%d-%06d', self::PREFIX, $year, (int) $value);
    }
}
