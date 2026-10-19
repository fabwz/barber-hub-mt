<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Http\Resources;

use DateTimeInterface;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Identity\Users\Domain\Entities\User;

/**
 * @property-read User $resource
 */
#[SchemaName('Cuenta')]
final class AccountResource extends JsonResource
{
    /** Wrap a registered account. */
    public function __construct(private readonly User $user)
    {
        parent::__construct($user);
    }

    /**
     * Expose the account without its password or its hash.
     *
     * @return array{id: string, nombre: string, correo: string, telefono: string|null, acepta_terminos: string|null, acepta_privacidad: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->user->id->value,
            'nombre' => $this->user->name->value,
            'correo' => $this->user->email->value,
            'telefono' => $this->user->phone?->value,
            'acepta_terminos' => $this->user->termsAcceptedAt?->format(DateTimeInterface::ATOM),
            'acepta_privacidad' => $this->user->privacyAcceptedAt?->format(DateTimeInterface::ATOM),
        ];
    }
}
