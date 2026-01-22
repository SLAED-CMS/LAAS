<?php

declare(strict_types=1);

namespace Laas\Support\Mail;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class PhpMailer implements MailerInterface
{
    private string $defaultFrom;
    private LoggerInterface $logger;

    public function __construct(
        ?string $defaultFrom = null,
        ?LoggerInterface $logger = null
    ) {
        $this->defaultFrom = $defaultFrom ?? 'noreply@laas-cms.org';
        $this->logger = $logger ?? new NullLogger();
    }

    public function send(string $to, string $subject, string $body, ?string $from = null): bool
    {
        $from = $from ?? $this->defaultFrom;

        $headers = [
            'From: ' . $from,
            'Reply-To: ' . $from,
            'X-Mailer: LAAS CMS',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
        ];

        $success = @mail($to, $subject, $body, implode("\r\n", $headers));

        if ($success) {
            $this->logger->info('Email sent successfully', [
                'to' => $to,
                'subject' => $subject,
            ]);
        } else {
            $this->logger->error('Failed to send email', [
                'to' => $to,
                'subject' => $subject,
            ]);
        }

        return $success;
    }
}
