<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Gateways;

/**
 * Turn what a gateway says about a refused payment into the sentence the customer reads (Spanish, «usted»). The tables hold only
 * the codes the provider documents and the ones the adapters already handle; anything else, or nothing, is the generic sentence.
 * The text of the provider is never returned: the adapter logs it for diagnosis (`HttpPaymentGateway::logDecline`).
 */
final readonly class DeclineReasons
{
    public const string GENERIC = 'El banco rechazó el pago. Pruebe con otra tarjeta o con otro método.';

    /** A payment the provider did not accept to create, and a refund it refused. */
    public const string REQUEST_REJECTED = 'No se pudo procesar el pago. Intente de nuevo en unos minutos.';

    public const string REFUND_REFUSED = 'La pasarela no aceptó la devolución. Intente de nuevo más tarde.';

    private const string CANCELED = 'El pago fue cancelado. Intente de nuevo.';

    /** `reason` of `details.card` that ONVO Pay documents for a declined card. */
    private const array ONVO_CARD_REASONS = [
        'issuer_declined' => 'El banco emisor rechazó el pago. Pruebe con otra tarjeta o con otro método.',
        'gateway_declined' => 'No se pudo procesar la tarjeta. Pruebe de nuevo, con otra tarjeta o con SINPE Móvil.',
        'onvo_declined' => 'No se pudo procesar la tarjeta. Pruebe de nuevo, con otra tarjeta o con SINPE Móvil.',
        'processor_error' => 'No se pudo procesar la tarjeta. Pruebe de nuevo, con otra tarjeta o con SINPE Móvil.',
    ];

    /** Error codes of ONVO Pay: the documented one of a card, and the state the adapter reads as a failure. */
    private const array ONVO_CODES = [
        'cards.invalid_card_info' => 'Los datos de la tarjeta no son válidos. Revíselos o pruebe con otra tarjeta.',
        'canceled' => self::CANCELED,
    ];

    /** Messages of ONVO Pay seen with its test card (4000 0000 0000 0002), which its documentation lists only as «Declined payment». */
    private const array ONVO_MESSAGES = [
        'Transaction declined due to test payment method used.' => 'La tarjeta de prueba fue rechazada. Pruebe con otra tarjeta o pague con SINPE Móvil.',
    ];

    /** `details.0.issue` of a refused capture of PayPal (the ones the adapter reads as a decision of PayPal), and the voided order. */
    private const array PAYPAL_ISSUES = [
        'INSTRUMENT_DECLINED' => 'PayPal rechazó el medio de pago. Pruebe con otra tarjeta o con otro método.',
        'TRANSACTION_REFUSED' => 'PayPal rechazó la transacción. Pruebe con otro método de pago.',
        'PAYER_CANNOT_PAY' => 'No es posible pagar con esta cuenta de PayPal. Pruebe con otro método de pago.',
        'PAYER_ACCOUNT_RESTRICTED' => 'La cuenta de PayPal tiene restricciones. Pruebe con otro método de pago.',
        'PAYER_ACCOUNT_LOCKED_OR_CLOSED' => 'La cuenta de PayPal está bloqueada o cerrada. Pruebe con otro método de pago.',
        'voided' => self::CANCELED,
    ];

    /** The sentence for a refusal of ONVO Pay: by the reason of the card, then by the code, then by the known message. */
    public function onvo(?string $code, ?string $message, ?string $cardReason = null): string
    {
        return self::ONVO_CARD_REASONS[$cardReason ?? '']
            ?? self::ONVO_CODES[$code ?? '']
            ?? self::ONVO_MESSAGES[$message ?? '']
            ?? self::GENERIC;
    }

    /** The sentence for a refusal of PayPal, by the issue it names. */
    public function paypal(?string $issue): string
    {
        return self::PAYPAL_ISSUES[$issue ?? ''] ?? self::GENERIC;
    }
}
