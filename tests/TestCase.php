<?php

declare(strict_types=1);

namespace MattStein\UseSend\Tests;

use Illuminate\Foundation\Application;
use MattStein\UseSend\UseSendServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            UseSendServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('mail.mailers.usesend', ['transport' => 'usesend']);
        $app['config']->set('usesend.api_key', 'us_test_key');
        $app['config']->set('usesend.base_url', 'https://app.usesend.com');
    }
}
