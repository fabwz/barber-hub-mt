<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\UseCases;

use Src\Payments\Payments\Application\Contracts\PaymentGateway;
use Src\Payments\Payments\Application\DTOs\PaymentChannel;
use Src\Payments\Payments\Application\PaymentGatewayResolver;
use Src\Payments\Payments\Domain\Contracts\PaymentRepository;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\ReservationId;

final readonly class QuotePaymentChannels
{
    /** Receive the repository of payments and the resolver that chooses the gateway. */
    public function __construct(private PaymentRepository $payments, private PaymentGatewayResolver $gateways) {}

    /**
     * Tell, for each reservation, the provider it is paid through and the methods that provider declares (RF-20, PA-02).
     * A reservation with a payment pending or approved keeps the provider of that payment, even if the barbershop has changed
     * its own since; any other uses the one the barbershop has now. The methods are what the gateway says it supports.
     *
     * @param  list<string>  $reservationIds
     * @return array<string, PaymentChannel> By reservation id.
     */
    public function execute(array $reservationIds): array
    {
        if ($reservationIds === []) {
            return [];
        }

        $active = $this->payments->findActiveOfReservations(array_map(fn (string $id): ReservationId => new ReservationId($id), $reservationIds));
        $current = null;
        $channels = [];
        foreach ($reservationIds as $id) {
            $gateway = isset($active[$id]) ? $this->gateways->forPayment($active[$id]) : ($current ??= $this->gateways->forCurrentBarbershop());
            $channels[$id] = new PaymentChannel($gateway->provider(), self::methodsOf($gateway));
        }

        return $channels;
    }

    /**
     * The methods a gateway admits.
     *
     * @return list<PaymentMethod>
     */
    private static function methodsOf(PaymentGateway $gateway): array
    {
        return array_values(array_filter(PaymentMethod::cases(), fn (PaymentMethod $method): bool => $gateway->supports($method)));
    }
}
