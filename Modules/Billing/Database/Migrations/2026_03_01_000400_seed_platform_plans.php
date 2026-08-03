<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Catalogue de plans.
 *
 * Versionné avec le code, comme les templates de plateforme de Notify : un
 * tarif ne se change pas depuis un formulaire. Modifier un prix consiste à
 * archiver l'ancien tarif et à en créer un nouveau, dans une migration.
 *
 * Montants hors taxes, en XAF — devise **sans centime** : 45 000 XAF s'écrit
 * 45000, jamais 4500000.
 *
 * @see docs/03-services/billing/02-data-model.md
 */
return new class extends Migration
{
    private const PLANS = [
        'starter' => [
            'name' => 'Starter',
            'description' => 'Pour démarrer, un seul produit et une petite équipe',
            'trial_days' => 14,
            'sort_order' => 10,
            'products' => ['stock'],
            'limits' => [
                'members' => 3,
                'workspaces' => 1,
                'storage_gb' => 5,
                'sms_monthly' => 50,
            ],
            'prices' => [
                ['interval' => 'month', 'amount' => 9000],
                ['interval' => 'year', 'amount' => 90000],
            ],
        ],
        'clinic-pro' => [
            'name' => 'Clinic Pro',
            'description' => 'Pour les cabinets et cliniques de 5 à 25 praticiens',
            'trial_days' => 14,
            'sort_order' => 20,
            'products' => ['clinicflow', 'stock'],
            'limits' => [
                'members' => 25,
                'workspaces' => 5,
                'storage_gb' => 50,
                'sms_monthly' => 500,
            ],
            'prices' => [
                ['interval' => 'month', 'amount' => 45000],
                // Deux mois offerts : sur un modèle prépayé, l'annuel est le
                // principal levier de rétention — moins d'échéances, donc moins
                // d'occasions d'abandonner.
                ['interval' => 'year', 'amount' => 450000],
            ],
        ],
        'business' => [
            'name' => 'Business',
            'description' => 'Tous les produits, sans limite de membres',
            'trial_days' => 0,
            'sort_order' => 30,
            'products' => ['clinicflow', 'dealeros', 'stock', 'tontines'],
            'limits' => [
                // `null` = illimité. L'absence de la clé signifierait « non
                // couvert par ce plan » : la distinction compte.
                'members' => null,
                'workspaces' => 25,
                'storage_gb' => 500,
                'sms_monthly' => 5000,
            ],
            'prices' => [
                ['interval' => 'month', 'amount' => 150000],
                ['interval' => 'year', 'amount' => 1500000],
            ],
        ],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::PLANS as $key => $plan) {
            if (DB::table('plans')->where('key', $key)->exists()) {
                continue;
            }

            $planId = (string) Str::uuid();

            DB::table('plans')->insert([
                'id' => $planId,
                'key' => $key,
                'name' => $plan['name'],
                'description' => $plan['description'],
                'status' => 'active',
                'is_public' => true,
                'trial_days' => $plan['trial_days'],
                'sort_order' => $plan['sort_order'],
                'limits' => json_encode($plan['limits']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($plan['prices'] as $price) {
                DB::table('plan_prices')->insert([
                    'id' => (string) Str::uuid(),
                    'plan_id' => $planId,
                    'currency' => 'XAF',
                    'amount' => $price['amount'],
                    'interval' => $price['interval'],
                    'interval_count' => 1,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach ($plan['products'] as $slug) {
                $productId = DB::table('products')->where('slug', $slug)->value('id');

                // Un produit absent n'interrompt pas la migration : le plan
                // existe, il ouvre moins de produits. Échouer ici bloquerait le
                // déploiement pour une donnée de catalogue.
                if ($productId === null) {
                    continue;
                }

                DB::table('plan_products')->insert([
                    'id' => (string) Str::uuid(),
                    'plan_id' => $planId,
                    'product_id' => $productId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('plans')->whereIn('key', array_keys(self::PLANS))->delete();
    }
};
