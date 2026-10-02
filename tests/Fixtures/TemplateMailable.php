<?php

declare(strict_types=1);

namespace MattStein\UseSend\Tests\Fixtures;

use Illuminate\Mail\Mailable;
use MattStein\UseSend\Concerns\HasUseSendTemplate;

class TemplateMailable extends Mailable
{
    use HasUseSendTemplate;

    public function build(): self
    {
        return $this->from('events@example.com')
            ->to('jane@example.com')
            ->subject('Subject the template replaces')
            ->html('<p>Body the template replaces</p>')
            ->useSendTemplate('welcome_template', [
                'name' => 'Jane',
                'attempts' => 3,
                'vip' => true,
                'nothing' => null,
            ]);
    }
}
