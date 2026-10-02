<?php

declare(strict_types=1);

namespace MattStein\UseSend\Tests\Fixtures;

use Illuminate\Mail\Mailable;
use MattStein\UseSend\Concerns\HasUseSendIdempotencyKey;

class IdempotentMailable extends Mailable
{
    use HasUseSendIdempotencyKey;

    public function __construct(private readonly string $key) {}

    public function build(): self
    {
        return $this->from('events@example.com')
            ->to('jane@example.com')
            ->subject('Your order')
            ->html('<p>Thanks for your order.</p>')
            ->useSendIdempotencyKey($this->key);
    }
}
