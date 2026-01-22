<?php

declare(strict_types=1);

namespace Laas\Support\Mail;

interface MailerInterface
{
    public function send(string $to, string $subject, string $body, ?string $from = null): bool;
}
