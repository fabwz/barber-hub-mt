<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Persistence\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Payments\Payments\Domain\Contracts\PaymentRepository;
use Src\Payments\Payments\Domain\Entities\Payment;
use Src\Payments\Payments\Domain\Exceptions\ActivePaymentExistsException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentId;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Payments\Payments\Domain\ValueObjects\PaymentStatus;
use Src\Payments\Payments\Domain\ValueObjects\ProviderAmount;
use Src\Payments\Payments\Domain\ValueObjects\ReservationId;
use Src\Payments\Payments\Infrastructure\Persistence\Models\PaymentModel;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\Money;

final readonly class EloquentPaymentRepository implements PaymentRepository
{
    private const string UNIQUE_VIOLATION = '23505';

    private const string ACTIVE_PAYMENT_INDEX = 'payments_one_active_per_reservation_idx';

    /** Receive the port that tells which barbershop the operation belongs to. */
    public function __construct(private BarbershopContext $context) {}

    /** Generate a time-ordered UUID for a new payment. */
    public function nextId(): PaymentId
    {
        return new PaymentId((string) Str::uuid7());
    }

    /**
     * Insert the payment once; afterwards the charge in the provider, the status and the reason of a failure change. A
     * second payment pending or approved for the same reservation is rejected by the unique index of the table and
     * translated here, so a repeated press of the pay button never creates two.
     */
    public function save(Payment $payment): void
    {
        $values = [
            'status' => $payment->status->value,
            'provider_currency' => $payment->providerAmount?->currency,
            'provider_amount' => $payment->providerAmount?->minorUnits,
            'provider_payment_id' => $payment->providerPaymentId,
            'failure_reason' => $payment->failureReason,
            'refund_pending' => $payment->refundPending,
            'updated_at' => $payment->updatedAt,
        ];

        try {
            // A savepoint inside the transaction of the request, so the rejected write does not abort the caller's transaction.
            DB::transaction(function () use ($payment, $values): void {
                $model = $this->ofBarbershop()->find($payment->id->value);

                if ($model === null) {
                    PaymentModel::query()->create([
                        'id' => $payment->id->value,
                        'barbershop_id' => $this->context->current()->value,
                        'reservation_id' => $payment->reservationId->value,
                        'provider' => $payment->provider->value,
                        'method' => $payment->method->value,
                        'amount' => $payment->amount->colones,
                        'created_at' => $payment->createdAt,
                        ...$values,
                    ]);
                } else {
                    $model->update($values);
                }
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === self::UNIQUE_VIOLATION && str_contains($exception->getMessage(), self::ACTIVE_PAYMENT_INDEX)) {
                throw ActivePaymentExistsException::create();
            }

            throw $exception;
        }
    }

    /** Find the payment among those of the current barbershop. */
    public function find(PaymentId $id): ?Payment
    {
        $model = $this->ofBarbershop()->find($id->value);

        return $model === null ? null : $this->toDomain($model);
    }

    /** Find the pending or approved payment of the reservation among those of the current barbershop. */
    public function findActiveOfReservation(ReservationId $reservationId): ?Payment
    {
        $model = $this->ofBarbershop()
            ->where('reservation_id', $reservationId->value)
            ->whereIn('status', array_map(fn (PaymentStatus $status): string => $status->value, array_filter(PaymentStatus::cases(), fn (PaymentStatus $status): bool => $status->isActive())))
            ->first();

        return $model === null ? null : $this->toDomain($model);
    }

    /**
     * Scope the query to the current barbershop; the row-level policy is the second barrier.
     *
     * @return Builder<PaymentModel>
     */
    private function ofBarbershop(): Builder
    {
        return PaymentModel::query()->where('barbershop_id', $this->context->current()->value);
    }

    /** Rebuild the aggregate from its stored state. */
    private function toDomain(PaymentModel $model): Payment
    {
        return Payment::reconstitute(
            new PaymentId($model->id),
            new BarbershopId($model->barbershop_id),
            new ReservationId($model->reservation_id),
            PaymentProvider::from($model->provider),
            PaymentMethod::from($model->method),
            Money::of($model->amount),
            $model->provider_payment_id,
            $model->provider_currency === null || $model->provider_amount === null ? null : ProviderAmount::of($model->provider_currency, $model->provider_amount),
            PaymentStatus::from($model->status),
            $model->failure_reason,
            $model->created_at,
            $model->updated_at,
            $model->refund_pending,
        );
    }
}
