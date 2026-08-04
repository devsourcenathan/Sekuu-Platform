<?php

declare(strict_types=1);

namespace Modules\Payments\Domain;

use App\Platform\Exceptions\DomainException;

/**
 * Numéro Mobile Money, normalisé en E.164, et réseau qui le porte.
 *
 * Le réseau est un **fait** déduit du préfixe, pas un choix : il détermine
 * quels agrégateurs peuvent servir, jamais lequel est essayé en premier.
 */
final readonly class Msisdn
{
    private function __construct(
        public string $value,
        public string $operator,
        public string $country,
    ) {}

    public static function parse(string $raw, ?string $country = null): self
    {
        $country = mb_strtoupper($country ?? (string) config('payments.default_country'));

        /** @var array{country_code: string, prefixes: array<string, list<string>>}|null $config */
        $config = config('payments.operators.'.$country);

        if ($config === null) {
            throw self::invalid();
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        $code = $config['country_code'];

        // Un numéro peut arriver en local (`650000000`), en E.164 (`+237…`) ou
        // avec un préfixe international (`00237…`). Les trois désignent la même
        // ligne ; les refuser sur la forme serait hostile.
        $national = match (true) {
            str_starts_with($digits, '00'.$code) => mb_substr($digits, mb_strlen($code) + 2),
            str_starts_with($digits, $code) => mb_substr($digits, mb_strlen($code)),
            default => $digits,
        };

        $operator = self::operatorFor($national, $config['prefixes']);

        if ($operator === null) {
            throw self::invalid();
        }

        return new self('+'.$code.$national, $operator, $country);
    }

    /**
     * Masquage pour l'affichage et les journaux : un numéro complet est une
     * donnée personnelle, sans usage pour qui lit un incident de paiement.
     */
    public function masked(): string
    {
        return mb_substr($this->value, 0, 8).' •• •• '.mb_substr($this->value, -2);
    }

    /**
     * @param  array<string, list<string>>  $prefixes
     */
    private static function operatorFor(string $national, array $prefixes): ?string
    {
        // Les préfixes les plus longs d'abord : `655` appartient à Orange alors
        // que `65` seul ne tranche pas.
        $ordered = [];

        foreach ($prefixes as $operator => $list) {
            foreach ($list as $prefix) {
                $ordered[$prefix] = $operator;
            }
        }

        uksort($ordered, static fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach ($ordered as $prefix => $operator) {
            if (str_starts_with($national, (string) $prefix)) {
                return $operator;
            }
        }

        return null;
    }

    private static function invalid(): DomainException
    {
        return DomainException::unprocessable(
            'INVALID_MSISDN',
            __('payments::messages.invalid_msisdn'),
        );
    }
}
