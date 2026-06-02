<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support\Notifier;

use PHPUnit\Framework\Assert;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Asserts the "no real outbound" rule for the channels this backend actually uses.
 *
 * IMPORTANT — current code first: this repository does NOT use Symfony Notifier.
 * The real outbound channels are:
 *   - Email  -> Symfony Mailer (MailerInterface). In test, MAILER_DSN=null://null,
 *               so nothing leaves the process; messages are still captured by the
 *               framework message logger and surfaced via
 *               WebTestCase::getMailerMessages().
 *   - Push   -> Firebase HTTP (curl) in JobSchedulerService. In test there is no
 *               Firebase config, so push jobs fail closed without any network call.
 *   - Realtime -> Mercure HubInterface, aliased to MercureTestRecorder in test.
 *
 * This helper wraps the captured mailer messages so a golden test can assert WHAT
 * the system tried to send (content) while proving nothing was really delivered.
 * It is named "RecordingNotifier" to match the cross-repo testing vocabulary even
 * though the backend's notification surface is Mailer + Mercure rather than the
 * Symfony Notifier component.
 */
final class RecordingNotifier
{
    /**
     * @param list<RawMessage> $mailerMessages typically WebTestCase::getMailerMessages()
     */
    public function __construct(
        private readonly array $mailerMessages,
    ) {
    }

    /**
     * @param list<RawMessage> $mailerMessages
     */
    public static function fromMailerMessages(array $mailerMessages): self
    {
        return new self($mailerMessages);
    }

    public function emailCount(): int
    {
        return count($this->mailerMessages);
    }

    public function assertNoEmails(string $message = ''): void
    {
        Assert::assertCount(
            0,
            $this->mailerMessages,
            $message !== '' ? $message : 'Expected no emails to be produced.'
        );
    }

    public function assertEmailSentTo(string $email, ?string $containingSubject = null): void
    {
        foreach ($this->mailerMessages as $message) {
            if (!$message instanceof Email) {
                continue;
            }

            foreach ($message->getTo() as $address) {
                if (strcasecmp($address->getAddress(), $email) !== 0) {
                    continue;
                }

                if ($containingSubject === null || str_contains((string) $message->getSubject(), $containingSubject)) {
                    Assert::assertTrue(true);

                    return;
                }
            }
        }

        Assert::fail(sprintf(
            'Expected a captured email to "%s"%s, but none matched. Captured: %s',
            $email,
            $containingSubject !== null ? sprintf(' containing subject "%s"', $containingSubject) : '',
            $this->describe()
        ));
    }

    /**
     * Structural proof that no email could have really left the test: the mailer
     * DSN must be a null transport. Pass $_SERVER['MAILER_DSN'].
     */
    public static function assertMailerIsNullTransport(?string $dsn): void
    {
        Assert::assertNotNull($dsn, 'MAILER_DSN must be set to a null transport in the test env.');
        Assert::assertStringStartsWith(
            'null://',
            (string) $dsn,
            'Tests must use a null mailer transport so no real email is sent. Got: ' . $dsn
        );
    }

    private function describe(): string
    {
        $lines = [];
        foreach ($this->mailerMessages as $message) {
            if ($message instanceof Email) {
                $to = array_map(static fn ($a) => $a->getAddress(), $message->getTo());
                $lines[] = sprintf('[to=%s subject="%s"]', implode(',', $to), (string) $message->getSubject());
            } else {
                $lines[] = '[non-email message]';
            }
        }

        return $lines === [] ? '(none)' : implode(' ', $lines);
    }
}
