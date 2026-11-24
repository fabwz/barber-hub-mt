<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use Exception;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Src\Notifications\Notices\Application\DTOs\NoticeType;
use Src\Notifications\Notices\Infrastructure\Jobs\SendNoticeJob;
use Src\Notifications\Notices\Infrastructure\Jobs\SendReminderJob;
use Src\Notifications\Notices\Infrastructure\Mail\IdempotentResendTransport;
use Src\Notifications\Notices\Infrastructure\Mail\NoticeMail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use Tests\Support\Notifications\FakeResendClient;
use Tests\Support\Notifications\RecordingResendEmails;
use Tests\TestCase;

/**
 * The mail provider without the provider: a double of the Resend client that keeps what it is asked to send and can answer a 429. It proves that the
 * idempotency key reaches the SDK (and not the message), that a refusal of the provider is an exception the queue retries without leaking the address,
 * and that the sending keeps to the rate limit. No request leaves the process (RF-34, RNF-09).
 */
final class ResendTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function message(?string $key): Email
    {
        $email = (new Email)->from('BarberHub <onboarding@resend.dev>')->to('ana@barberhub.test')->subject('Asunto')->html('<p>Hola</p>')->text('Hola');

        if ($key !== null) {
            $email->getHeaders()->addTextHeader(NoticeMail::KEY_HEADER, $key);
        }

        return $email;
    }

    /** The key travels as an option of the SDK, which sends it as the Idempotency-Key of the request, and never as a header of the email itself. */
    public function test_the_idempotency_key_goes_to_the_sdk_and_not_into_the_message(): void
    {
        $client = new FakeResendClient;
        (new IdempotentResendTransport($client))->send($this->message('reminder-r1-24h-1'));

        [$parameters, $options] = $client->emails->calls[0];
        $this->assertSame(['idempotency_key' => 'reminder-r1-24h-1'], $options);
        $this->assertSame([], $parameters['headers']);
        $this->assertSame(['ana@barberhub.test'], $parameters['to']);
        $this->assertSame('Asunto', $parameters['subject']);
        $this->assertSame('<p>Hola</p>', $parameters['html']);
    }

    /** A message with no key is sent with no option. */
    public function test_a_message_without_a_key_is_sent_without_options(): void
    {
        $client = new FakeResendClient;
        (new IdempotentResendTransport($client))->send($this->message(null));

        $this->assertSame([], $client->emails->calls[0][1]);
    }

    /** A 429 or any refusal of the provider is a TransportException (so the task retries) and the message does not repeat the address of the customer. */
    public function test_a_refusal_of_the_provider_is_an_exception_that_does_not_leak_the_address(): void
    {
        $client = new FakeResendClient(new RecordingResendEmails(new Exception('Too many requests for ana@barberhub.test', 429)));

        try {
            (new IdempotentResendTransport($client))->send($this->message('k'));
            $this->fail('The 429 must reach the queue as an exception.');
        } catch (TransportException $exception) {
            $this->assertStringNotContainsString('ana@barberhub.test', $exception->getMessage());
            $this->assertSame(429, $exception->getCode());
        }
    }

    /** With `MAIL_MAILER=resend` the mailer is the one with the key, not the transport of the framework. */
    public function test_the_resend_mailer_is_the_one_with_the_idempotency_key(): void
    {
        config(['mail.mailers.resend' => ['transport' => 'resend', 'key' => 're_test_placeholder']]);
        Mail::purge('resend');

        $transport = (new \ReflectionMethod(Mail::mailer('resend'), 'getSymfonyTransport'))->invoke(Mail::mailer('resend'));

        $this->assertInstanceOf(IdempotentResendTransport::class, $transport);
    }

    /** The sending keeps to a limit per second, 2 unless the environment says otherwise, as a plan of Resend allows. */
    public function test_the_sending_keeps_to_the_rate_limit_of_the_provider(): void
    {
        $resolver = RateLimiter::limiter(SendNoticeJob::LIMITER);
        $this->assertNotNull($resolver);
        $limit = $resolver();

        $this->assertInstanceOf(Limit::class, $limit);
        $this->assertSame(2, $limit->maxAttempts);
        $this->assertSame(1, $limit->decaySeconds);

        foreach ([new SendReminderJob('r', 'b', '24h', new \DateTimeImmutable), $this->noticeJob()] as $job) {
            $this->assertInstanceOf(RateLimited::class, $job->middleware()[0]);
        }
    }

    /** A task is retried up to 3 times with a growing wait, and waiting for the limiter does not use an attempt (RNF-09). */
    public function test_the_retry_policy_is_three_failures_with_a_growing_wait(): void
    {
        foreach ([new SendReminderJob('r', 'b', '24h', new \DateTimeImmutable), $this->noticeJob()] as $job) {
            $this->assertSame(3, $job->maxExceptions);
            $this->assertSame(0, $job->tries);
            $this->assertSame([10, 60], $job->backoff);
            $this->assertGreaterThan($job->backoff[0], $job->backoff[1]);
            $this->assertGreaterThan(now()->addMinutes(59)->getTimestamp(), $job->retryUntil()->getTimestamp());
        }
    }

    private function noticeJob(): SendNoticeJob
    {
        return new SendNoticeJob('b', NoticeType::Confirmation, 'm', 's', 'c', new \DateTimeImmutable, new \DateTimeImmutable, null, null, 'k');
    }
}
