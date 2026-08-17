<?php

declare(strict_types=1);

namespace tests\unit;

use app\models\contract\MailerInterface;
use app\models\contract\queue\JobInterface;
use app\models\jobs\SendEmailHandler;
use app\models\jobs\SendEmailJob;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\Exception;

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

    /**
     * @throws Exception
     */
    public function testRejectsAJobItDoesNotOwn(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $foreign = new class () implements JobInterface {
            public function handlerClass(): string
            {
                return SendEmailHandler::class;
            }
        };

        $this->expectException(InvalidArgumentException::class);

        (new SendEmailHandler($mailer))->handle($foreign);
    }

    public function testJobNamesItsHandler(): void
    {
        $this->assertSame(
            SendEmailHandler::class,
            (new SendEmailJob('a@example.com', 's', 'b'))->handlerClass()
        );
    }
}
