<?php

declare(strict_types=1);

namespace MattStein\UseSend;

use Illuminate\Mail\MailManager;
use Illuminate\Support\ServiceProvider;
use MattStein\UseSend\Console\InstallCommand;
use MattStein\UseSend\Support\TransportOptions;

class UseSendServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(self::configPath(), 'usesend');

        // Extending the manager when it is first resolved, rather than in
        // boot(), keeps the transport available to providers that send mail
        // while the application is still booting.
        $this->callAfterResolving('mail.manager', static function (MailManager $manager): void {
            $manager->extend(
                TransportOptions::MAILER,
                /** @param array<string, mixed> $config */
                static fn (array $config = []): UseSendTransport => new UseSendTransport($config),
            );
        });
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            self::configPath() => $this->app->configPath('usesend.php'),
        ], 'usesend-config');

        $this->commands([InstallCommand::class]);
    }

    public static function configPath(): string
    {
        return dirname(__DIR__).'/config/usesend.php';
    }
}
