<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Catalog\Invitations\Domain\Entities\Invitation;

/**
 * @property-read Invitation $resource
 */
#[SchemaName('Invitacion')]
final class InvitationResource extends JsonResource
{
    /** Wrap a pending invitation. */
    public function __construct(private readonly Invitation $invitation)
    {
        parent::__construct($invitation);
    }

    /**
     * Expose the invitation with the vocabulary of the business. The token and its hash are never part of it.
     *
     * @return array{id: string, correo: string, servicios: list<string>, enviada: string, vence: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->invitation->id->value,
            'correo' => $this->invitation->email,
            'servicios' => $this->invitation->serviceIds,
            /** Instante en que se envió la invitación, en ISO 8601. */
            'enviada' => $this->invitation->createdAt->format(DATE_ATOM),
            /** Instante en que el enlace deja de funcionar, en ISO 8601: siete días después de enviada. */
            'vence' => $this->invitation->expiresAt->format(DATE_ATOM),
        ];
    }
}
