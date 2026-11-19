<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Vouchers\PurchasedVouchers\Application\DTOs\PurchasedVoucherView;

/**
 * @property-read PurchasedVoucherView $resource
 */
#[SchemaName('BonoVendido')]
final class SoldVoucherResource extends JsonResource
{
    /** Wrap a voucher sold in the barbershop, read at an instant. */
    public function __construct(private readonly PurchasedVoucherView $view)
    {
        parent::__construct($view);
    }

    /**
     * Expose the voucher as the owner sees it: what the customer sees, except the covered services that only the customer reads, plus
     * the name of the customer, never their email or phone.
     *
     * @return array{id: string, nombre: string, sesiones_totales: int, saldo: int, estado: string, activado_en: string|null, vence_en: string|null, movimientos: AnonymousResourceCollection, cliente_nombre: string}
     */
    public function toArray(Request $request): array
    {
        return [
            ...new PurchasedVoucherResource($this->view)->record(),
            /** Nombre del cliente que lo compró. */
            'cliente_nombre' => $this->view->customerName ?? '',
        ];
    }
}
