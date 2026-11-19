<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Infrastructure\Http\Resources;

use DateTimeImmutable;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Vouchers\PurchasedVouchers\Application\DTOs\PurchasedVoucherView;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\VoucherMovement;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\VoucherMovementType;

/**
 * @property-read PurchasedVoucherView $resource
 */
#[SchemaName('BonoComprado')]
final class PurchasedVoucherResource extends JsonResource
{
    /** Wrap a paid voucher read at an instant. */
    public function __construct(private readonly PurchasedVoucherView $view)
    {
        parent::__construct($view);
    }

    /**
     * Expose the voucher with its balance, its validity and the record that ties each change of the balance to its reservation.
     *
     * @return array{id: string, nombre: string, sesiones_totales: int, servicios: AnonymousResourceCollection, saldo: int, estado: string, activado_en: string|null, vence_en: string|null, movimientos: list<array{tipo: string, sesiones: int, reserva: string|null, fecha: string}>}
     */
    public function toArray(Request $request): array
    {
        $voucher = $this->view->voucher;

        return [
            'id' => $voucher->id->value,
            'nombre' => $voucher->name->value,
            /** Sesiones que trae el bono al comprarlo. Ejemplo: 5. */
            'sesiones_totales' => $voucher->sessionsCount->value,
            /** Servicios que cubre el bono, ordenados por nombre, tal como los define hoy la barbería: si el bono ya no está a la venta, igual se muestran. Con ellos el cliente sabe con qué servicios puede reservar. */
            'servicios' => CoveredServiceResource::collection($this->view->services ?? []),
            /** Sesiones que le quedan, igual a la suma de sus `movimientos`; 0 si ya venció. Ejemplo: 3. */
            'saldo' => $this->view->balance,
            /** `activo` mientras está vigente o `vencido` desde su fecha de vencimiento; se calcula al consultar, sin proceso de vencimiento. */
            'estado' => $this->view->expired ? 'vencido' : 'activo',
            /** Cuándo se confirmó el pago, ISO 8601 con el desfase de Costa Rica (-06:00). Ejemplo: 2030-03-01T08:00:00-06:00. */
            'activado_en' => self::instant($voucher->activatedAt),
            /** Cuándo vence, ISO 8601 con el desfase de Costa Rica (-06:00); es el instante en que deja de valer. Ejemplo: 2030-05-30T08:00:00-06:00. */
            'vence_en' => self::instant($voucher->expiresAt),
            /** Cada cambio del saldo, del más antiguo al más reciente, con la reserva a la que se liga (nula en la compra). */
            'movimientos' => array_map(fn (VoucherMovement $movement): array => [
                /** `compra`, `apartado`, `consumo` o `reintegro`. */
                'tipo' => self::typeName($movement->type),
                /** Sesiones que suma o resta al saldo: la compra suma las del bono, el apartado resta 1, el reintegro suma 1 y el consumo no cambia nada (0). */
                'sesiones' => $movement->sessionsDelta,
                /** Identificador de la reserva ligada; nulo en la compra. */
                'reserva' => $movement->reservationId?->value,
                'fecha' => self::instant($movement->at) ?? '',
            ], $this->view->movements),
        ];
    }

    /** An instant in the time zone of the application, as the rest of the API writes it. */
    private static function instant(?DateTimeImmutable $at): ?string
    {
        return $at?->setTimezone(new DateTimeZone((string) config('app.timezone')))->format(DATE_ATOM);
    }

    /** The name of the kind of entry in the vocabulary of the API (BO-09). */
    private static function typeName(VoucherMovementType $type): string
    {
        return match ($type) {
            VoucherMovementType::Purchase => 'compra',
            VoucherMovementType::Hold => 'apartado',
            VoucherMovementType::Consume => 'consumo',
            VoucherMovementType::Release => 'reintegro',
        };
    }
}
