<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\VoucherMovement;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\VoucherMovementType;

/**
 * @property-read VoucherMovement $resource
 */
#[SchemaName('MovimientoDeBono')]
final class VoucherMovementResource extends JsonResource
{
    /** Wrap an entry of the record of a voucher. */
    public function __construct(private readonly VoucherMovement $movement)
    {
        parent::__construct($movement);
    }

    /**
     * Expose the entry: what kind of change it is, the sessions it adds or takes, the reservation it is tied to and when it was written.
     *
     * @return array{tipo: 'compra'|'apartado'|'consumo'|'reintegro', sesiones: int, reserva: string|null, fecha: string}
     */
    public function toArray(Request $request): array
    {
        return [
            /** `compra`, `apartado`, `consumo` o `reintegro`. */
            'tipo' => self::typeName($this->movement->type),
            /** Sesiones que suma o resta al saldo: la compra suma las del bono, el apartado resta 1, el reintegro suma 1 y el consumo no cambia nada (0). */
            'sesiones' => $this->movement->sessionsDelta,
            /** Identificador de la reserva ligada; nulo en la compra. */
            'reserva' => $this->movement->reservationId?->value,
            /** Cuándo se escribió el movimiento, ISO 8601 con el desfase de Costa Rica (-06:00). Ejemplo: 2030-03-01T08:00:00-06:00. */
            'fecha' => PurchasedVoucherResource::instant($this->movement->at) ?? '',
        ];
    }

    /**
     * The name of the kind of entry in the vocabulary of the API (BO-09).
     *
     * @return 'compra'|'apartado'|'consumo'|'reintegro'
     */
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
