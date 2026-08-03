<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Console;

use Illuminate\Console\Command;
use Modules\Identity\Infrastructure\Jwt\SigningKeys;

final class GenerateJwtKeysCommand extends Command
{
    protected $signature = 'identity:generate-keys {--show : Afficher les clés au lieu de les écrire}';

    protected $description = 'Génère une paire de clés RSA pour la signature des access tokens';

    public function handle(): int
    {
        [$private, $public] = SigningKeys::generate();

        if ($this->option('show')) {
            $this->line($private);
            $this->line($public);

            return self::SUCCESS;
        }

        $directory = storage_path('app/private/identity');

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $privatePath = $directory.'/jwt-private.pem';

        if (is_file($privatePath) && ! $this->confirm('Une paire existe déjà. La remplacer invalidera tous les tokens en circulation. Continuer ?', false)) {
            return self::FAILURE;
        }

        file_put_contents($privatePath, $private);
        file_put_contents($directory.'/jwt-public.pem', $public);
        @chmod($privatePath, 0600);

        $this->info('Paire de clés écrite dans '.$directory);
        $this->line('');
        $this->comment('En production, ne versionnez jamais ces fichiers : passez les clés par');
        $this->comment('IDENTITY_JWT_PRIVATE_KEY et IDENTITY_JWT_PUBLIC_KEY.');

        return self::SUCCESS;
    }
}
