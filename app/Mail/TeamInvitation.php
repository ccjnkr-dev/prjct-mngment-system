<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class TeamInvitation extends Mailable
{
    public function __construct(public string $link) {}

    public function build(): static
    {
        $name = config('app.name');

        return $this->subject('Join '.$name)->html('<h1>You’re invited to '.e($name).'</h1><p>Plan projects and build together.</p><p><a href="'.e($this->link).'">Accept invitation</a></p><p>This link expires in 3 days.</p>');
    }
}
