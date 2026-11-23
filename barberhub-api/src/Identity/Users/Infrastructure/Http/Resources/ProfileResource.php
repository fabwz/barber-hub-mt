<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Identity\Users\Domain\Entities\User;

/**
 * @property-read User $resource
 */
#[SchemaName('MiCuenta')]
final class ProfileResource extends JsonResource
{
    /** Wrap the account of the person who asks. */
    public function __construct(private readonly User $user)
    {
        parent::__construct($user);
    }

    /**
     * Expose the personal data and whether the password is still temporary, never the password or its hash.
     *
     * @return array{nombre: string, telefono: string|null, correo: string, contrasena_temporal: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'nombre' => $this->user->name->value,
            'telefono' => $this->user->phone?->value,
            'correo' => $this->user->email->value,
            'contrasena_temporal' => $this->user->mustChangePassword,
        ];
    }
}
