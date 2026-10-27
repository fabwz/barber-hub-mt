<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Http;

use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\PaymentStatus;

/** The names the API gives to the statuses and the methods of a payment, in Spanish as the glossary lists them (RD-07). */
final class PaymentNames
{
    /** @var array<string, PaymentStatus> */
    private const array STATUSES = [
        'pendiente' => PaymentStatus::Pending,
        'aprobado' => PaymentStatus::Approved,
        'rechazado' => PaymentStatus::Rejected,
        'fallido' => PaymentStatus::Failed,
        'reembolsado' => PaymentStatus::Refunded,
    ];

    /** @var array<string, PaymentMethod> */
    private const array METHODS = [
        'tarjeta' => PaymentMethod::Card,
        'sinpe_movil' => PaymentMethod::SinpeMovil,
    ];

    /** The name of the status in the API. */
    public static function status(PaymentStatus $status): string
    {
        return (string) array_search($status, self::STATUSES, true);
    }

    /** The name of the method in the API. */
    public static function method(PaymentMethod $method): string
    {
        return (string) array_search($method, self::METHODS, true);
    }

    /** The method an API name stands for; the name must be one of `methodNames()`. */
    public static function methodOf(string $name): PaymentMethod
    {
        return self::METHODS[$name];
    }

    /**
     * Every name of a method in the API.
     *
     * @return list<string>
     */
    public static function methodNames(): array
    {
        return array_keys(self::METHODS);
    }
}
