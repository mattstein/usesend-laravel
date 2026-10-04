<?php

declare(strict_types=1);

namespace MattStein\UseSend\Tests\Fixtures;

use Closure;
use Illuminate\Mail\Mailable;
use MattStein\UseSend\Concerns\HasUseSendIdempotencyKey;
use MattStein\UseSend\Concerns\HasUseSendSchedule;
use MattStein\UseSend\Concerns\HasUseSendTemplate;

/**
 * A mailable with every useSend concern. The build callback runs first, and
 * anything it leaves unset gets a valid default.
 */
class ConfigurableMailable extends Mailable
{
    use HasUseSendIdempotencyKey;
    use HasUseSendSchedule;
    use HasUseSendTemplate;

    /**
     * @param  Closure(self): mixed  $build
     */
    public function __construct(private readonly Closure $build) {}

    public function build(): self
    {
        ($this->build)($this);

        if ($this->from === []) {
            $this->from('events@example.com');
        }

        if ($this->to === []) {
            $this->to('jane@example.com');
        }

        $this->subject = $this->subject ?: 'Your meetup';

        if ($this->html === null && $this->markdown === null && $this->view === null) {
            $this->html('<p>See you there.</p>');
        }

        return $this;
    }
}
