<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Catalogue des produits de l'écosystème.
 *
 * La table existait et restait vide : `organization_products` ne pouvait donc
 * référencer aucun produit, et les plans de Billing n'avaient rien à ouvrir.
 *
 * Les produits sont versionnés avec le code, comme les rôles globaux : ce sont
 * des constantes de la plateforme, pas des données saisies.
 *
 * @see docs/03-services/identity/02-data-model.md
 */
return new class extends Migration
{
    private const PRODUCTS = [
        'clinicflow' => ['name' => 'ClinicFlow', 'description' => 'Gestion de cabinets et de cliniques'],
        'dealeros' => ['name' => 'DealerOS', 'description' => 'Gestion de concessions et de ventes de véhicules'],
        'stock' => ['name' => 'Sekuu Stock', 'description' => 'Gestion de stocks et d\'inventaire'],
        'tontines' => ['name' => 'Sekuu Tontines', 'description' => 'Gestion de tontines et d\'épargne collective'],
        'sekuu-learn' => ['name' => 'Sekuu Learn', 'description' => 'Formation et suivi pédagogique'],
        'immigraflow' => ['name' => 'ImmigraFlow', 'description' => 'Suivi de dossiers d\'immigration'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::PRODUCTS as $slug => $product) {
            // Idempotent : la migration peut tourner sur une base où un produit
            // aurait déjà été créé à la main.
            if (DB::table('products')->where('slug', $slug)->exists()) {
                continue;
            }

            DB::table('products')->insert([
                'id' => (string) Str::uuid(),
                'slug' => $slug,
                'name' => $product['name'],
                'description' => $product['description'],
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Suppression logique refusée si une organisation y est rattachée :
        // la clé étrangère est en `restrictOnDelete`, et c'est voulu.
        DB::table('products')->whereIn('slug', array_keys(self::PRODUCTS))->delete();
    }
};
