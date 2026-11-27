<?php

namespace App\Services\Mail;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Laravel Mailer 的最后一步：不连 SMTP，把组好的 MIME 原文写进投递服务的通道。
 * 一封信多个收件人时每个收件人一条（id 加序号），与投递服务「一条一个 RCPT」的约定一致。
 */
final class RelayTransport extends AbstractTransport
{
    protected function doSend(SentMessage $message): void
    {
        $ctx = MailRelay::$context;
        if ($ctx === null) {
            throw new \LogicException('RelayTransport used outside MailRelay::send');
        }
        $raw = $message->toString();
        $envelope = $message->getEnvelope();
        $from = $envelope->getSender()->getAddress();
        $recipients = $envelope->getRecipients();
        foreach ($recipients as $i => $rcpt) {
            $id = count($recipients) > 1 ? $ctx['id'] . '-' . $i : $ctx['id'];
            MailRelay::enqueue($ctx['lane'], $id, $from, $rcpt->getAddress(), $raw, (int) $ctx['not_after'], $ctx['meta']);
        }
    }

    public function __toString(): string
    {
        return 'relay://redis';
    }
}
