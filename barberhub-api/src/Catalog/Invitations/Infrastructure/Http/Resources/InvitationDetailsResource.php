<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Catalog\Invitations\Application\DTOs\InvitationDetails;

/**
 * @property-read InvitationDetails $resource
 */
#[SchemaName('DetalleDeInvitacion')]
final class InvitationDetailsResource extends JsonResource
{
    /** Wrap what the person sees before accepting. */
    public function __construct(private readonly InvitationDetails $details)
    {
        parent::__construct($details);
    }

    /**
     * Expose only what the screen needs to ask for what is missing.
     *
     * @return array{barberia: string, correo: string, cuenta_existente: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            /** Nombre de la barbería que invita. */
            'barberia' => $this->details->barbershopName,
            'correo' => $this->details->email,
            /** `true` si el correo ya tiene cuenta: solo hay que aceptar, sin nombre ni contraseña nuevos, y su contraseña no cambia. */
            'cuenta_existente' => $this->details->accountExists,
        ];
    }
}
