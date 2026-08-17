<?php

declare(strict_types=1);

namespace app\models\jobs;

use app\models\contract\queue\JobInterface;

/**
 * Delivers one message.
 *
 * Queued rather than sent inline because an SMTP conversation is a network call
 * to a third party inside a request the user is waiting on — and because a
 * transient mail failure should be retried, not turned into a 500 for an
 * operation (a password reset request) that has already succeeded.
 *
 * Carries only strings, so it serializes cleanly; the transport is injected into
 * {@see SendEmailHandler}.
 */
readonly class SendEmailJob implements JobInterface
{
    public function __construct(
        public string $to,
        public string $subject,
        public string $body,
    ) {
    }

    public function handlerClass(): string
    {
        return SendEmailHandler::class;
    }
}
