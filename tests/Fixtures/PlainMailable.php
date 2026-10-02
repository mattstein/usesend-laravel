<?php

declare(strict_types=1);

namespace MattStein\UseSend\Tests\Fixtures;

use Illuminate\Mail\Mailable;

class PlainMailable extends Mailable
{
    public function build(): self
    {
        return $this->from('events@example.com')
            ->to('jane@example.com')
            ->subject('Your meetup')
            ->html('<p>See you there.</p>');
    }
}
