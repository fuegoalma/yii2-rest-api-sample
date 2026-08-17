<?php

declare(strict_types=1);

namespace app\components\mail;

use app\models\contract\MailerInterface;
use Yii;

/**
 * Writes the message to the structured log instead of sending it.
 *
 * This sample provisions no mail server, and a `MailerInterface` bound to
 * nothing would make the password-reset flow untestable end to end while
 * looking implemented. Logging is the honest default: the flow really runs, the
 * message really goes somewhere a developer can read
 * (`docker compose logs web`), and swapping in `yii\symfonymailer\Mailer` is a
 * change to one binding in `config/di.php`.
 *
 * The body is logged too, which is exactly what must **not** happen with a real
 * transport in front of real users — a reset link in a log file is a reset link
 * anyone with log access can use. That is stated here rather than assumed
 * because the class is otherwise an inviting thing to leave in place.
 */
final readonly class LogMailer implements MailerInterface
{
    public function send(string $to, string $subject, string $body): void
    {
        Yii::info(
            sprintf("Mail to %s\nSubject: %s\n\n%s", $to, $subject, $body),
            __METHOD__
        );
    }
}
