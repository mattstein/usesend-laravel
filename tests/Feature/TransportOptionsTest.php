<?php

declare(strict_types=1);

use MattStein\UseSend\Exceptions\InvalidOptionException;
use MattStein\UseSend\Exceptions\MissingApiKeyException;
use MattStein\UseSend\Support\TransportOptions;

it('reads the package config', function () {
    config()->set('usesend', [
        'api_key' => 'us_config_key',
        'base_url' => 'https://mail.example.com',
        'timeout' => 15,
        'connect_timeout' => 5,
        'retries' => 2,
        'retry_sleep' => 500,
        'inline_attachments' => 'attach',
        'user_agent' => 'my-app',
    ]);

    $options = TransportOptions::resolve();

    expect($options->requireApiKey())->toBe('us_config_key')
        ->and($options->baseUrl)->toBe('https://mail.example.com')
        ->and($options->timeout)->toBe(15)
        ->and($options->connectTimeout)->toBe(5)
        ->and($options->retries)->toBe(2)
        ->and($options->retrySleepMilliseconds)->toBe(500)
        ->and($options->includeInlineAttachments)->toBeTrue()
        ->and($options->userAgent)->toBe('my-app');
});

it('falls back to defaults when nothing is configured', function () {
    config()->set('usesend', []);

    $options = TransportOptions::resolve(['api_key' => 'us_key']);

    expect($options->baseUrl)->toBe('https://app.usesend.com')
        ->and($options->timeout)->toBe(30)
        ->and($options->connectTimeout)->toBe(10)
        ->and($options->retries)->toBe(0)
        ->and($options->retrySleepMilliseconds)->toBe(200)
        ->and($options->includeInlineAttachments)->toBeFalse()
        ->and($options->userAgent)->toBe('mattstein-usesend-laravel');
});

it('prefers the mailer array over the package config', function () {
    $options = TransportOptions::resolve([
        'transport' => 'usesend',
        'api_key' => 'us_mailer_key',
        'base_url' => 'http://localhost:3000',
        'retries' => 1,
    ]);

    expect($options->requireApiKey())->toBe('us_mailer_key')
        ->and($options->baseUrl)->toBe('http://localhost:3000')
        ->and($options->retries)->toBe(1);
});

it('falls through a null mailer entry to the package config', function () {
    expect(TransportOptions::resolve(['api_key' => null])->requireApiKey())->toBe('us_test_key');
});

it('reads numbers from environment strings', function () {
    $options = TransportOptions::resolve(['timeout' => '12', 'retries' => '3', 'retry_sleep' => '50']);

    expect($options->timeout)->toBe(12)
        ->and($options->retries)->toBe(3)
        ->and($options->retrySleepMilliseconds)->toBe(50);
});

it('replaces negative and non-numeric numbers', function () {
    $options = TransportOptions::resolve(['timeout' => 'soon', 'retries' => -2, 'retry_sleep' => -1]);

    expect($options->timeout)->toBe(30)
        ->and($options->retries)->toBe(0)
        ->and($options->retrySleepMilliseconds)->toBe(0);
});

it('replaces blank strings with defaults', function () {
    $options = TransportOptions::resolve(['base_url' => '  ', 'user_agent' => '']);

    expect($options->baseUrl)->toBe('https://app.usesend.com')
        ->and($options->userAgent)->toBe('mattstein-usesend-laravel');
});

it('trims the api key', function () {
    expect(TransportOptions::resolve(['api_key' => "  us_key\n"])->requireApiKey())->toBe('us_key');
});

it('requires an api key', function (mixed $key) {
    config()->set('usesend.api_key', $key);

    TransportOptions::resolve()->requireApiKey();
})->with([null, '', '   ', 123, false])->throws(MissingApiKeyException::class, 'Set USESEND_API_KEY');

it('reads the inline attachment policy', function (mixed $value, bool $include) {
    expect(TransportOptions::resolve(['inline_attachments' => $value])->includeInlineAttachments)->toBe($include);
})->with([
    ['skip', false],
    ['attach', true],
    [' ATTACH ', true],
    ['', false],
    [true, true],
    [false, false],
]);

it('rejects an unknown inline attachment policy', function (mixed $value, string $shown) {
    TransportOptions::resolve(['inline_attachments' => $value]);
})->with([
    ['embed', "'embed'"],
    [1, '1'],
    [['attach'], 'array'],
])->throws(InvalidOptionException::class, 'must be one of [skip, attach]');
