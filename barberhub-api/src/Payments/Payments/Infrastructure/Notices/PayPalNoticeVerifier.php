<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Notices;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Src\Payments\Payments\Application\Contracts\GatewayNoticeVerifier;
use Src\Payments\Payments\Application\DTOs\GatewayNotice;
use Src\Payments\Payments\Application\Exceptions\GatewayUnavailableException;
use Src\Payments\Payments\Application\Exceptions\NoticeNotVerifiedException;
use Src\Payments\Payments\Application\Exceptions\PaymentServiceUnavailableException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Payments\Payments\Infrastructure\Gateways\PayPalGateway;
use Src\Shared\Infrastructure\Http\Middleware\AssignRequestId;

/**
 * Verifies the notices of PayPal (RF-22, PA-04) with its own service `verify-webhook-signature`, through the adapter that already holds
 * the credentials and the access token: the transmission headers, `PAYPAL_WEBHOOK_ID` and the event as received go to PayPal and only
 * `SUCCESS` accepts. The certificate is not checked here. Without the webhook identifier configured nothing is accepted; if PayPal cannot
 * be asked, the answer is 503 so that it sends the notice again.
 *
 * The events that make the system ask PayPal for the state of the order are `CHECKOUT.ORDER.APPROVED` (the order is the resource) and
 * `PAYMENT.CAPTURE.COMPLETED`, `.DECLINED` and `.DENIED` (the order is in `supplementary_data.related_ids.order_id`). The notice is a
 * trigger: asking PayPal captures an approved order and reads the result.
 */
final readonly class PayPalNoticeVerifier implements GatewayNoticeVerifier
{
    /** Headers of the transmission that PayPal checks, by the field of the verification call they fill. */
    private const array TRANSMISSION_HEADERS = [
        'auth_algo' => 'paypal-auth-algo',
        'cert_url' => 'paypal-cert-url',
        'transmission_id' => 'paypal-transmission-id',
        'transmission_sig' => 'paypal-transmission-sig',
        'transmission_time' => 'paypal-transmission-time',
    ];

    /** Events whose resource is the capture of an order. */
    private const array CAPTURE_EVENTS = ['PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.DECLINED', 'PAYMENT.CAPTURE.DENIED'];

    /** Receive the adapter of PayPal and the identifier of the webhook registered in its dashboard. */
    public function __construct(private PayPalGateway $gateway, private string $webhookId) {}

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Paypal;
    }

    /** Ask PayPal to verify the notice, then read the event; a refusal says nothing about the reason to the sender. */
    public function verify(array $headers, string $rawBody): GatewayNotice
    {
        $context = ['gateway' => 'paypal', 'request_id' => Context::get(AssignRequestId::CONTEXT_KEY)];

        if ($this->webhookId === '') {
            Log::error('The webhook identifier of PayPal is not configured: every notice is refused.', $context);

            throw PaymentServiceUnavailableException::create();
        }

        $transmission = [];

        foreach (self::TRANSMISSION_HEADERS as $field => $header) {
            $value = $headers[$header] ?? '';

            if ($value === '') {
                Log::warning('A notice of PayPal was refused.', [...$context, 'reason' => 'missing_transmission_header']);

                throw NoticeNotVerifiedException::create();
            }

            $transmission[$field] = $value;
        }

        $event = json_decode($rawBody, true);

        if (! is_array($event) || array_is_list($event)) {
            Log::warning('A notice of PayPal was refused.', [...$context, 'reason' => 'body_is_not_an_event']);

            throw NoticeNotVerifiedException::create();
        }

        try {
            $genuine = $this->gateway->verifyNotice($transmission, $this->webhookId, $rawBody);
        } catch (GatewayUnavailableException) {
            throw PaymentServiceUnavailableException::create();
        }

        if (! $genuine) {
            Log::warning('A notice of PayPal was refused.', [...$context, 'reason' => 'verification_failed']);

            throw NoticeNotVerifiedException::create();
        }

        return $this->read($event);
    }

    /**
     * The event of a verified notice; one about something else, or without the identifiers, has no reference and changes nothing.
     *
     * @param  array<mixed>  $event
     */
    private function read(array $event): GatewayNotice
    {
        $eventId = is_string($event['id'] ?? null) ? $event['id'] : '';
        $type = is_string($event['event_type'] ?? null) ? $event['event_type'] : '';
        $resource = is_array($event['resource'] ?? null) ? $event['resource'] : [];
        $order = match (true) {
            $type === 'CHECKOUT.ORDER.APPROVED' => $resource['id'] ?? null,
            in_array($type, self::CAPTURE_EVENTS, true) => $resource['supplementary_data']['related_ids']['order_id'] ?? null,
            default => null,
        };

        return new GatewayNotice(PaymentProvider::Paypal, $eventId, $type, $eventId !== '' && is_string($order) && $order !== '' ? $order : null);
    }
}
