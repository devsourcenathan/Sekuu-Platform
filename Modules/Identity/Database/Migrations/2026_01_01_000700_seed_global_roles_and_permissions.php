<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Les rôles et permissions globaux sont des données de système, pas des
 * données d'application : ils sont créés par migration afin d'exister dans
 * tous les environnements, y compris en test.
 *
 * @see docs/03-services/identity/02-data-model.md
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'organization.manage' => 'Modifier les informations de l\'organisation',
        'organization.delete' => 'Supprimer l\'organisation',
        'subscription.manage' => 'Gérer l\'abonnement et la facturation',
        'workspace.create' => 'Créer un espace de travail',
        'workspace.manage' => 'Gérer les espaces de travail et leurs membres',
        'users.invite' => 'Inviter des membres',
        'users.remove' => 'Retirer des membres',
        'roles.assign' => 'Attribuer des rôles globaux',
        'products.install' => 'Activer des produits',
        'audit.read' => 'Consulter le journal d\'audit',
    ];

    private const ROLES = [
        'owner' => [
            'name' => 'Owner',
            'description' => 'Contrôle total, y compris la suppression de l\'organisation',
            'permissions' => '*',
        ],
        'admin' => [
            'name' => 'Admin',
            'description' => 'Gestion des membres, des workspaces et des produits',
            'permissions' => [
                'organization.manage', 'workspace.create', 'workspace.manage',
                'users.invite', 'users.remove', 'roles.assign',
                'products.install', 'audit.read',
            ],
        ],
        'billing_manager' => [
            'name' => 'Billing Manager',
            'description' => 'Gestion de l\'abonnement et de la facturation',
            'permissions' => ['subscription.manage'],
        ],
        'member' => [
            'name' => 'Member',
            'description' => 'Accès simple, sans droit d\'administration',
            'permissions' => [],
        ],
    ];

    public function up(): void
    {
        $now = now();
        $permissionIds = [];

        foreach (self::PERMISSIONS as $code => $description) {
            $permissionIds[$code] = (string) Str::uuid();

            DB::table('global_permissions')->insert([
                'id' => $permissionIds[$code],
                'code' => $code,
                'description' => $description,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (self::ROLES as $slug => $role) {
            $roleId = (string) Str::uuid();

            DB::table('global_roles')->insert([
                'id' => $roleId,
                'name' => $role['name'],
                'slug' => $slug,
                'description' => $role['description'],
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $codes = $role['permissions'] === '*'
                ? array_keys(self::PERMISSIONS)
                : $role['permissions'];

            foreach ($codes as $code) {
                DB::table('role_permissions')->insert([
                    'global_role_id' => $roleId,
                    'global_permission_id' => $permissionIds[$code],
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->delete();
        DB::table('global_roles')->whereIn('slug', array_keys(self::ROLES))->delete();
        DB::table('global_permissions')->whereIn('code', array_keys(self::PERMISSIONS))->delete();
    }
};
