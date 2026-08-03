<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Jwt;

use RuntimeException;

/**
 * Paire de clés RSA utilisée pour signer les access tokens.
 *
 * RS256 plutôt que HS256 : les consommateurs n'ont besoin que de la clé
 * publique, aucun produit ne détient de secret permettant de forger un token.
 *
 * @see docs/04-decisions/adr-0004-jwt-stateless-tokens.md
 */
final class SigningKeys
{
    private ?string $privateKey = null;

    private ?string $publicKey = null;

    /** Paire éphémère, régénérée à chaque exécution de la suite de tests. */
    private static ?array $ephemeral = null;

    public function __construct(
        private readonly ?string $configuredPrivateKey,
        private readonly ?string $configuredPublicKey,
        private readonly string $storagePath,
        private readonly bool $mayGenerate,
    ) {}

    public function privateKey(): string
    {
        $this->resolve();

        return $this->privateKey;
    }

    public function publicKey(): string
    {
        $this->resolve();

        return $this->publicKey;
    }

    /**
     * Identifiant de clé, dérivé de la clé publique : il change donc
     * automatiquement à chaque rotation.
     */
    public function keyId(): string
    {
        return substr(hash('sha256', $this->publicKey()), 0, 16);
    }

    /**
     * Représentation JWKS de la clé publique.
     *
     * @return array<string, mixed>
     */
    public function toJwk(): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_public($this->publicKey()));

        if ($details === false || ! isset($details['rsa'])) {
            throw new RuntimeException('Unable to read the public key details.');
        }

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $this->keyId(),
            'n' => self::base64Url($details['rsa']['n']),
            'e' => self::base64Url($details['rsa']['e']),
        ];
    }

    private function resolve(): void
    {
        if ($this->privateKey !== null) {
            return;
        }

        if ($this->configuredPrivateKey && $this->configuredPublicKey) {
            $this->privateKey = self::normalise($this->configuredPrivateKey);
            $this->publicKey = self::normalise($this->configuredPublicKey);

            return;
        }

        if (! $this->mayGenerate) {
            throw new RuntimeException(
                'No signing key configured. Set IDENTITY_JWT_PRIVATE_KEY and '
                .'IDENTITY_JWT_PUBLIC_KEY, or run `php artisan identity:generate-keys`.'
            );
        }

        [$this->privateKey, $this->publicKey] = $this->storagePath === ''
            ? self::ephemeralPair()
            : $this->pairFromStorage();
    }

    /**
     * En développement, la paire est persistée : sans cela, un token émis avant
     * un redémarrage ne serait plus vérifiable après.
     *
     * @return array{string, string}
     */
    private function pairFromStorage(): array
    {
        $privatePath = $this->storagePath.'/jwt-private.pem';
        $publicPath = $this->storagePath.'/jwt-public.pem';

        if (is_file($privatePath) && is_file($publicPath)) {
            return [
                (string) file_get_contents($privatePath),
                (string) file_get_contents($publicPath),
            ];
        }

        [$private, $public] = self::generate();

        if (! is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0700, true);
        }

        file_put_contents($privatePath, $private);
        file_put_contents($publicPath, $public);
        @chmod($privatePath, 0600);

        return [$private, $public];
    }

    /**
     * @return array{string, string}
     */
    private static function ephemeralPair(): array
    {
        return self::$ephemeral ??= self::generate();
    }

    /**
     * @return array{string, string}
     */
    public static function generate(): array
    {
        $args = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $resource = @openssl_pkey_new($args);

        // Sur certaines installations, OPENSSL_CONF pointe vers un fichier
        // absent et la génération échoue. On repasse alors une configuration
        // minimale, suffisante pour produire une paire de clés.
        if ($resource === false) {
            $args['config'] = self::minimalOpensslConfig();
            $resource = @openssl_pkey_new($args);
        }

        if ($resource === false) {
            throw new RuntimeException(
                'Unable to generate an RSA key pair: '.(openssl_error_string() ?: 'unknown error')
            );
        }

        openssl_pkey_export($resource, $private, null, $args);
        $details = openssl_pkey_get_details($resource);

        return [$private, $details['key']];
    }

    private static function minimalOpensslConfig(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sekuu-openssl.cnf';

        if (! is_file($path)) {
            file_put_contents($path, "[ req ]\ndistinguished_name = req_dn\n[ req_dn ]\n");
        }

        return $path;
    }

    /** Autorise une clé passée en une seule ligne dans une variable d'environnement. */
    private static function normalise(string $key): string
    {
        return str_replace('\n', "\n", trim($key));
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
