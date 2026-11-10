<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Notices;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Src\Payments\Payments\Application\Contracts\GatewayNoticeVerifier;
use Src\Payments\Payments\Application\DTOs\GatewayNotice;
use Src\Payments\Payments\Application\Exceptions\NoticeNotVerifiedException;
use Src\Payments\Payments\Application\Exceptions\PaymentServiceUnavailableException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Infrastructure\Http\Middleware\AssignRequestId;

/**
 * Verifies the notices of ONVO Pay (RF-22, PA-04). ONVO signs each delivery: the header `X-Onvo-Signature` carries
 * `t=<unix seconds>,v1=<hex>` (two `v1` while its secret rotates), and `v1` is the HMAC-SHA256 of `"<t>.<raw body>"` with the signing
 * secret of the endpoint (`ONVO_WEBHOOK_SECRET`, the whole `whsec_...` text). A delivery more than five minutes away from now is refused,
 * and every comparison takes constant time. Without the secret configured nothing is ever accepted.
 *
 * Only the payment intents that finished are used (`payment_intent.succeeded`, `.failed` and `.canceled`), and only in test mode (PA-09).
 * The notice is a trigger: the state is asked to the API of ONVO afterwards.
 */
final readonly class OnvoNoticeVerifier implements GatewayNoticeVerifier
{
    public const string SIGNATURE_HEADER = 'x-onvo-signature';

    /** Seconds a delivery may be away from the current instant. */
    public const int TOLERANCE_SECONDS = 300;

    /** Event types that make the system ask ONVO for the state of the payment intent. */
    private const array PAYMENT_EVENTS = ['payment_intent.succeeded', 'payment_intent.failed', 'payment_intent.canceled'];

    /** Receive the signing secret and the clock. */
    public function __construct(private string $secret, private Clock $clock) {}

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Onvo;
    }

    /** Verify the signature, then read the event. A refusal says nothing about the reason to the sender; the log does, without the secret or the header. */
    public function verify(array $headers, string $rawBody): GatewayNotice
    {
        if ($this->secret === '') {
            Log::error('The webhook secret of ONVO Pay is not configured: every notice is refused.', ['gateway' => 'onvo', 'request_id' => Context::get(AssignRequestId::CONTEXT_KEY)]);

            throw PaymentServiceUnavailableException::create();
        }

        $reason = $this->refusalOf($headers[self::SIGNATURE_HEADER] ?? '', $rawBody);

        if ($reason !== null) {
            Log::warning('A notice of ONVO Pay was refused.', ['gateway' => 'onvo', 'reason' => $reason, 'request_id' => Context::get(AssignRequestId::CONTEXT_KEY)]);

            throw NoticeNotVerifiedException::create();
        }

        return $this->read($rawBody);
    }

    /** Why the signature is not valid, or null when it is. */
    private function refusalOf(string $header, string $rawBody): ?string
    {
        $timestamp = null;
        $candidates = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1' && preg_match('/^[0-9a-f]{64}$/i', $value) === 1) {
                $candidates[] = strtolower($value);
            }
        }

        if ($timestamp === null || preg_match('/^\d{1,12}$/', $timestamp) !== 1 || $candidates === []) {
            return 'malformed_signature';
        }

        if (abs($this->clock->now()->getTimestamp() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return 'timestamp_out_of_range';
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->secret);
        $matches = false;

        foreach ($candidates as $candidate) {
            $matches = hash_equals($expected, $candidate) || $matches;
        }

        return $matches ? null : 'signature_mismatch';
    }

    /**
     * The event of a verified delivery. One that is not a finished payment intent of the test mode, or that does not carry the
     * identifiers, has no reference and changes nothing.
     */
    private function read(string $rawBody): GatewayNotice
    {
        $event = json_decode($rawBody, true);
        $event = is_array($event) ? $event : [];
        $eventId = is_string($event['id'] ?? null) ? $event['id'] : '';
        $type = is_string($event['type'] ?? null) ? $event['type'] : '';
        $intentId = $event['data']['object']['id'] ?? null;
        $isPayment = in_array($type, self::PAYMENT_EVENTS, true) && ($event['mode'] ?? null) === 'test';

        return new GatewayNotice(
            PaymentProvider::Onvo,
            $eventId,
            $type,
            $isPayment && $eventId !== '' && is_string($intentId) && $intentId !== '' ? $intentId : null,
        );
    }
}
