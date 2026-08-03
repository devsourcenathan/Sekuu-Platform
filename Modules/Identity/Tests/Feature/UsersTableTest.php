<?php

declare(strict_types=1);

namespace Modules\Identity\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Identity\Domain\Models\User;
use Tests\TestCase;

/**
 * @see docs/03-services/identity/02-data-model.md
 */
final class UsersTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_module_migration_creates_the_users_table(): void
    {
        $this->assertTrue(Schema::hasTable('users'));

        $this->assertTrue(Schema::hasColumns('users', [
            'id', 'first_name', 'last_name', 'email', 'phone', 'password_hash',
            'avatar_url', 'email_verified_at', 'phone_verified_at', 'language',
            'timezone', 'status', 'last_login_at', 'deleted_at',
        ]));
    }

    public function test_columns_follow_the_snake_case_convention(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'firstname'));
        $this->assertFalse(Schema::hasColumn('users', 'lastname'));
    }

    public function test_identifiers_are_uuids(): void
    {
        $user = $this->makeUser('nathan@sekuu.com');

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $user->id,
        );
    }

    public function test_two_live_accounts_cannot_share_an_email(): void
    {
        $this->makeUser('nathan@sekuu.com');

        $this->expectExceptionMessageMatches('/unique/i');

        $this->makeUser('nathan@sekuu.com');
    }

    public function test_a_deleted_account_frees_its_email(): void
    {
        $this->makeUser('nathan@sekuu.com')->delete();

        $reused = $this->makeUser('nathan@sekuu.com');

        $this->assertSame('nathan@sekuu.com', $reused->email);
        $this->assertSame(1, User::query()->count());
        $this->assertSame(2, User::withTrashed()->count());
    }

    public function test_the_password_hash_is_never_serialised(): void
    {
        $user = $this->makeUser('nathan@sekuu.com', 'un-mot-de-passe');

        $this->assertArrayNotHasKey('password_hash', $user->toArray());
        $this->assertNotSame('un-mot-de-passe', $user->getAuthPassword());
    }

    private function makeUser(string $email, ?string $password = null): User
    {
        $user = new User([
            'first_name' => 'Nathan',
            'last_name' => 'Tchinda',
            'email' => $email,
        ]);

        if ($password !== null) {
            $user->password_hash = $password;
        }

        $user->save();

        return $user;
    }
}
