<?php

declare(strict_types=1);

namespace tests\unit;

use app\models\contract\MailerInterface;
use app\models\jobs\SendEmailHandler;
use app\models\jobs\SendEmailJob;
use PHPUnit\Framework\MockObject\Exception;

/**
 * Refusing a foreign job is {@see BaseJobHandler}'s behaviour, pinned once in
 * {@see BaseJobHandlerTest}; what is specific here is the transport it reaches.
 */
class SendEmailHandlerTest extends BaseUnitTest
{
    /**
     * @throws Exception
     */
    public function testSendsTheMessageThroughTheBoundTransport(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())
            ->method('send')
            ->with('a@example.com', 'Subject', 'Body');

        (new SendEmailHandler($mailer))->handle(new SendEmailJob('a@example.com', 'Subject', 'Body'));
    }

    public function testJobNamesItsHandler(): void
    {
        $this->assertSame(
            SendEmailHandler::class,
            (new SendEmailJob('a@example.com', 's', 'b'))->handlerClass()
        );
    }
}
