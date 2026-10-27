<?php

declare(strict_types=1);

namespace Tests\Support\Payments;

use Src\Payments\Payments\Application\Contracts\PaymentGateway;
use Src\Payments\Payments\Application\DTOs\ChargeRequest;
use Src\Payments\Payments\Application\DTOs\ChargeResult;
use Src\Payments\Payments\Application\DTOs\GatewayStatus;
use Src\Payments\Payments\Application\DTOs\NextAction;
use Src\Payments\Payments\Application\DTOs\RefundResult;
use Src\Payments\Payments\Application\Exceptions\GatewayUnavailableException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Payments\Payments\Domain\ValueObjects\PaymentStatus;
use Src\Payments\Payments\Domain\ValueObjects\ProviderAmount;
use Src\Shared\Domain\ValueObjects\Money;

/**
 * The only gateway the tests use (PA-09): no money moves and no network is touched. It approves, rejects or fails by
 * communication as the test configures, converts colones to dollar cents the way a real adapter would, and remembers what it was asked.
 */
final class SimulatedPaymentGateway implements PaymentGateway
{
    /** Colones per dollar of the simulated conversion. */
    public const int COLONES_PER_DOLLAR = 500;

    /** @var list<ChargeRequest> */
    public array $charges = [];

    /** @var list<string> */
    public array $statusChecks = [];

    /** @var list<array{string, Money}> */
    public array $refunds = [];

    private PaymentStatus $outcome = PaymentStatus::Approved;

    private ?string $reason = null;

    private bool $unavailable = false;

    private bool $failsAfterCharge = false;

    private ?string $refundRefusal = null;

    /**
     * Build a gateway for a provider that admits some methods.
     *
     * @param  list<PaymentMethod>  $methods
     */
    public function __construct(private readonly PaymentProvider $provider, private readonly array $methods = [PaymentMethod::Card]) {}

    /** The next charges are approved at once. */
    public function approves(): self
    {
        $this->outcome = PaymentStatus::Approved;
        $this->reason = null;
        $this->unavailable = false;

        return $this;
    }

    /** The next charges stay pending, waiting for the customer. */
    public function leavesPending(): self
    {
        $this->outcome = PaymentStatus::Pending;
        $this->unavailable = false;

        return $this;
    }

    /** The next charges are refused. */
    public function rejects(string $reason = 'Card declined'): self
    {
        $this->outcome = PaymentStatus::Rejected;
        $this->reason = $reason;
        $this->unavailable = false;

        return $this;
    }

    /** Every call fails as if the provider could not be reached. */
    public function isUnavailable(): self
    {
        $this->unavailable = true;

        return $this;
    }

    /** The next charge is created in the provider but the answer to its confirmation never arrives; the status can still be asked. */
    public function failsAfterCreatingTheCharge(): self
    {
        $this->failsAfterCharge = true;

        return $this;
    }

    /** The provider refuses every refund with the reason. */
    public function refusesRefunds(string $reason = 'Refund not allowed'): self
    {
        $this->refundRefusal = $reason;

        return $this;
    }

    public function provider(): PaymentProvider
    {
        return $this->provider;
    }

    public function supports(PaymentMethod $method): bool
    {
        return in_array($method, $this->methods, true);
    }

    public function charge(ChargeRequest $request): ChargeResult
    {
        $this->failIfUnavailable();
        $this->charges[] = $request;

        $result = new ChargeResult(
            'sim_'.$this->provider->value.'_'.count($this->charges),
            ProviderAmount::of('USD', intdiv($request->amount->colones * 100 + self::COLONES_PER_DOLLAR - 1, self::COLONES_PER_DOLLAR)),
            $this->outcome,
            $this->reason,
            $this->outcome === PaymentStatus::Pending ? NextAction::redirectTo('https://pay.test/'.$request->paymentId->value) : null,
        );

        if ($this->failsAfterCharge) {
            throw GatewayUnavailableException::afterCharge(new ChargeResult($result->providerPaymentId, $result->providerAmount, PaymentStatus::Pending), 'simulated outage after the charge');
        }

        return $result;
    }

    public function status(string $providerPaymentId): GatewayStatus
    {
        $this->failIfUnavailable();
        $this->statusChecks[] = $providerPaymentId;

        return new GatewayStatus($this->outcome, $this->reason);
    }

    public function refund(string $providerPaymentId, Money $amount): RefundResult
    {
        $this->failIfUnavailable();
        $this->refunds[] = [$providerPaymentId, $amount];

        if ($this->refundRefusal !== null) {
            return RefundResult::refused($this->refundRefusal);
        }

        return RefundResult::accepted('sim_refund_'.count($this->refunds));
    }

    private function failIfUnavailable(): void
    {
        if ($this->unavailable) {
            throw GatewayUnavailableException::because('simulated outage');
        }
    }
}
