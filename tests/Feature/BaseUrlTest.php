<?php

declare(strict_types=1);

use MattStein\UseSend\Exceptions\InvalidBaseUrlException;
use MattStein\UseSend\Support\BaseUrl;

it('documents the cloud host as the default', function () {
    expect(BaseUrl::DEFAULT_BASE_URL)->toBe('https://app.usesend.com');
});

it('refuses an unset base url rather than guessing an instance', function () {
    expect(fn () => BaseUrl::make(null))->toThrow(InvalidBaseUrlException::class);
});

it('accepts a bare host and assumes https', function () {
    expect(BaseUrl::make('app.usesend.com')->emailsEndpoint())->toBe('https://app.usesend.com/api/v1/emails');
});

it('trims surrounding whitespace and trailing slashes', function () {
    expect(BaseUrl::make('  https://app.usesend.com///  ')->emailsEndpoint())
        ->toBe('https://app.usesend.com/api/v1/emails');
});

it('keeps a non-default port', function () {
    expect(BaseUrl::make('http://localhost:3000')->emailsEndpoint())
        ->toBe('http://localhost:3000/api/v1/emails')
        ->and((string) BaseUrl::make('http://localhost:3000/usesend/'))
        ->toBe('http://localhost:3000/usesend');
});

it('keeps a reverse proxy path prefix', function () {
    expect(BaseUrl::make('https://example.com/usesend')->emailsEndpoint())
        ->toBe('https://example.com/usesend/api/v1/emails');
});

it('tolerates a base url copied from the docs that already ends in api', function () {
    expect(BaseUrl::make('https://app.usesend.com/api')->emailsEndpoint())
        ->toBe('https://app.usesend.com/api/v1/emails');

    expect(BaseUrl::make('https://example.com/usesend/api/')->emailsEndpoint())
        ->toBe('https://example.com/usesend/api/v1/emails');
});

it('lower-cases the host but not the prefix', function () {
    expect((string) BaseUrl::make('https://App.UseSend.COM/UseSend'))->toBe('https://app.usesend.com/UseSend');
});

it('rejects an empty base url', function () {
    BaseUrl::make('   ');
})->throws(InvalidBaseUrlException::class, 'base URL is empty');

it('rejects null', function () {
    BaseUrl::make(null);
})->throws(InvalidBaseUrlException::class, 'base URL is empty');

it('rejects an unsupported scheme', function () {
    BaseUrl::make('ftp://example.com');
})->throws(InvalidBaseUrlException::class, 'unsupported scheme');

it('rejects a url without a host', function () {
    BaseUrl::make('https://');
})->throws(InvalidBaseUrlException::class);
