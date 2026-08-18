<?php

declare(strict_types=1);

namespace app\models\jobs;

use app\models\contract\MailerInterface;
use app\models\contract\queue\JobInterface;
use app\models\jobs\basic\BaseJobHandler;

/**
 * Hands a {@see SendEmailJob} to whatever transport is bound.
 *
 * @extends BaseJobHandler<SendEmailJob>
 */
readonly class SendEmailHandler extends BaseJobHandler
{
    public function __construct(
        private MailerInterface $mailer,
    ) {
    }

    protected function jobClass(): string
    {
        return SendEmailJob::class;
    }

    protected function run(JobInterface $job): void
    {
        $this->mailer->send($job->to, $job->subject, $job->body);
    }
}
