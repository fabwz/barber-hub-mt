<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application;

use Src\Payments\Payments\Application\Contracts\PayerAccounts;
use Src\Payments\Payments\Application\DTOs\PaymentDetails;
use Src\Payments\Payments\Domain\Exceptions\PaymentNotFoundException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;

/** Builds what a method needs besides the amount; paying a reservation and buying a voucher ask the customer for the same things. */
final readonly class PaymentDetailsFactory
{
    /** Receive the port that finds the account of the person who pays. */
    public function __construct(private PayerAccounts $accounts) {}

    /**
     * What the method needs: the token of the card, or for the SINPE Móvil of the provider the phone, the identification and the
     * name and email of the account.
     *
     * @throws PaymentNotFoundException when the account of the person does not exist
     */
    public function make(string $userId, PaymentMethod $method, ?string $cardToken, ?string $phone, ?string $identification, ?string $identificationType): PaymentDetails
    {
        if ($method === PaymentMethod::Card) {
            return PaymentDetails::card($cardToken);
        }

        $account = $this->accounts->find($userId) ?? throw PaymentNotFoundException::create();

        return PaymentDetails::sinpeMovil((string) $phone, (string) $identification, (string) $identificationType, $account->name, $account->email);
    }
}
