<?php

declare(strict_types=1);

namespace MattStein\UseSend\Console;

use Illuminate\Console\Command;

/**
 * Publishes the config file and says what to put in .env next.
 */
final class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'usesend:install {--force : Overwrite an existing config/usesend.php}';

    /** @var string */
    protected $description = 'Publish the useSend config file';

    public function handle(): int
    {
        $this->callSilently('vendor:publish', [
            '--tag' => 'usesend-config',
            '--force' => (bool) $this->option('force'),
        ]);

        $this->components->info('Published config/usesend.php.');

        $this->components->bulletList([
            'Set USESEND_API_KEY in your .env file.',
            'Set USESEND_BASE_URL if you self-host useSend.',
            'Set MAIL_MAILER=usesend, or send with Mail::mailer(\'usesend\').',
        ]);

        return self::SUCCESS;
    }
}
