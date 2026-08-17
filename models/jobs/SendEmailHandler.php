<?php

declare(strict_types=1);

namespace app\models\jobs;

use app\models\contract\MailerInterface;
use app\models\contract\queue\JobHandlerInterface;
use app\models\contract\queue\JobInterface;
use InvalidArgumentException;

/**
 * Hands a {@see SendEmailJob} to whatever transport is bound.
 */
readonly class SendEmailHandler implements JobHandlerInterface
{
    public function __construct(
        private MailerInterface $mailer,
    ) {
    }

    public function handle(JobInterface $job): void
    {
        if (!$job instanceof SendEmailJob) {
            throw new InvalidArgumentException(
                'Expected ' . SendEmailJob::class . ', got ' . $job::class
            );
        }

        $this->mailer->send($job->to, $job->subject, $job->body);
    }
}
