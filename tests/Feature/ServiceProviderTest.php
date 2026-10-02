<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Assert;

use function Pest\Laravel\artisan;

afterEach(function () {
    File::delete(config_path('usesend.php'));
});

it('registers the install command', function () {
    expect(array_keys(Artisan::all()))->toContain('usesend:install');
});

it('publishes the config file through the install command', function () {
    File::delete(config_path('usesend.php'));

    expect(Artisan::call('usesend:install', ['--no-interaction' => true]))->toBe(0);

    expect(File::exists(config_path('usesend.php')))->toBeTrue();
});

it('leaves an existing config file alone unless forced', function () {
    File::put(config_path('usesend.php'), '<?php return [];');

    Artisan::call('usesend:install', ['--no-interaction' => true]);
    expect(File::get(config_path('usesend.php')))->toBe('<?php return [];');

    Artisan::call('usesend:install', ['--force' => true, '--no-interaction' => true]);
    expect(File::get(config_path('usesend.php')))->toContain('USESEND_API_KEY');
});

it('explains the next steps without asking anything', function () {
    File::delete(config_path('usesend.php'));

    $command = artisan('usesend:install');

    if (! $command instanceof PendingCommand) {
        Assert::fail('Expected a pending command.');
    }

    // A question nobody expected fails the test, so this also proves the
    // command never prompts (no "star the repo" prompt).
    $command
        ->expectsOutputToContain('USESEND_API_KEY')
        ->doesntExpectOutputToContain('star')
        ->assertSuccessful();
});

it('publishes the config file under its tag', function () {
    File::delete(config_path('usesend.php'));

    expect(Artisan::call('vendor:publish', ['--tag' => 'usesend-config', '--no-interaction' => true]))->toBe(0);

    expect(File::exists(config_path('usesend.php')))->toBeTrue();
});

it('merges its defaults without publishing', function () {
    expect(config('usesend.api_key'))->toBe('us_test_key')
        ->and(config('usesend.timeout'))->toBe(30)
        ->and(config('usesend.connect_timeout'))->toBe(10)
        ->and(config('usesend.retry_sleep'))->toBe(200)
        ->and(config('usesend.inline_attachments'))->toBe('skip');
});
