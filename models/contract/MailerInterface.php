<?php

declare(strict_types=1);

namespace app\models\contract;

/**
 * Sending a message to a human.
 *
 * Deliberately the smallest surface that the password-reset flow needs, and no
 * larger: an address, a subject, a body. Templates, attachments and multipart
 * bodies are not here because nothing asks for them, and an interface that
 * anticipates them would be a guess about the transport that eventually
 * implements it.
 *
 * The transport is not the application's business — which is the point of the
 * seam. Implementations must not throw for an undeliverable address; delivery
 * is asynchronous everywhere it matters (see {@see \app\models\jobs\SendEmailJob}),
 * and a bounce is not something the caller can act on.
 */
interface MailerInterface
{
    public function send(string $to, string $subject, string $body): void;
}
