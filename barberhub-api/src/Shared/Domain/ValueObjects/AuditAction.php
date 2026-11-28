<?php

declare(strict_types=1);

namespace Src\Shared\Domain\ValueObjects;

/**
 * The sensitive actions the technical record accepts (SE-04), each with the fields it may keep. The lists are allow lists: a
 * field that is not named here never reaches the record, whatever the caller passes. A field in `maskedFields` is recorded by
 * name only (that it changed), never with its values, because its values are secrets.
 */
enum AuditAction: string
{
    case PaymentPolicyUpdated = 'barbershop.payment_policy.updated';
    case PaymentGatewayUpdated = 'barbershop.payment_gateway.updated';
    case FeaturesUpdated = 'barbershop.features.updated';
    case DirectoryListingUpdated = 'barbershop.directory_listing.updated';
    case ReservationCancelledByBarbershop = 'reservation.cancelled_by_barbershop';
    case PaymentRefunded = 'payment.refunded';
    case BarbershopBlocked = 'barbershop.blocked';
    case BarbershopUnblocked = 'barbershop.unblocked';
    case BarbershopRegistered = 'barbershop.registered';

    /**
     * The fields whose previous and new values are kept.
     *
     * @return list<string>
     */
    public function recordedFields(): array
    {
        return match ($this) {
            self::PaymentPolicyUpdated => ['mode', 'deposit_percentage', 'cancellation_deadline_hours'],
            self::PaymentGatewayUpdated => ['gateway'],
            self::FeaturesUpdated => ['vouchers_enabled', 'waitlist_enabled'],
            self::DirectoryListingUpdated => ['listed_in_directory'],
            self::ReservationCancelledByBarbershop => ['status'],
            self::PaymentRefunded => ['status', 'refunded_amount'],
            self::BarbershopBlocked, self::BarbershopUnblocked => ['blocked_at'],
            self::BarbershopRegistered => ['public_link', 'owner_user_id', 'owner_account_created'],
        };
    }

    /**
     * The fields recorded by name only: that they changed, without the previous or the new value.
     *
     * @return list<string>
     */
    public function maskedFields(): array
    {
        return match ($this) {
            self::PaymentGatewayUpdated => ['credentials'],
            default => [],
        };
    }
}
