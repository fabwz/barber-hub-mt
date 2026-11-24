<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Infrastructure\Mail;

use Exception;
use Illuminate\Mail\Transport\ResendTransport;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

/**
 * The Resend transport of the framework, plus the idempotency key of the send. The framework transport calls `emails->send($payload)` with
 * no options, so it cannot pass the key; this one reads the key that `NoticeMail` leaves in a header and gives it to the SDK, which sends it
 * as the `Idempotency-Key` header of the request. Resend then answers the same message once for a repeated key, so a task that dies after the
 * mail left and before its claim was saved does not send it twice when the queue retries. The notices carry no attachments.
 */
final class IdempotentResendTransport extends ResendTransport
{
    /** Send the message with its idempotency key; any failure of the provider (a 429, a refused key, the network) is a TransportException, so the task retries. */
    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();

        if (! $original instanceof Message) {
            throw new TransportException('The message to send is not a MIME message.');
        }

        $email = MessageConverter::toEmail($original);
        $headers = $email->getHeaders();
        $key = $headers->has(NoticeMail::KEY_HEADER) ? $headers->get(NoticeMail::KEY_HEADER)?->getBodyAsString() : null;
        $headers->remove(NoticeMail::KEY_HEADER);

        $custom = [];

        foreach ($headers->all() as $name => $header) {
            if (! in_array($name, ['from', 'to', 'cc', 'bcc', 'reply-to', 'sender', 'subject', 'content-type'], true)) {
                $custom[$header->getName()] = $header->getBodyAsString();
            }
        }

        try {
            // The `Client` contract of the SDK is empty; the real client exposes `emails` (the transport of the framework reads it the same way).
            $sent = $this->resend->emails->send( // @phpstan-ignore property.notFound
                [
                    'from' => $message->getEnvelope()->getSender()->toString(),
                    'to' => $this->stringifyAddresses($this->getRecipients($email, $message->getEnvelope())),
                    'cc' => $this->stringifyAddresses($email->getCc()),
                    'bcc' => $this->stringifyAddresses($email->getBcc()),
                    'reply_to' => $this->stringifyAddresses($email->getReplyTo()),
                    'headers' => $custom,
                    'subject' => $email->getSubject(),
                    'html' => $email->getHtmlBody(),
                    'text' => $email->getTextBody(),
                ],
                $key === null ? [] : ['idempotency_key' => $key],
            );
        } catch (Exception $exception) {
            // Only the class and the code: the message of the provider may repeat the address of the customer.
            throw new TransportException(sprintf('Request to Resend API failed (%s).', $exception::class), is_int($exception->getCode()) ? $exception->getCode() : 0, $exception);
        }

        $message->setMessageId($sent->id);
    }
}
