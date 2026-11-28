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
     * Expose the id of the own account (the web client subscribes to its private channel with it), the personal
     * data, whether the password is still temporary and whether the person is a platform operator (RF-55), never the password or its hash.
     *
     * @return array{id: string, nombre: string, telefono: string|null, correo: string, contrasena_temporal: bool, operador_de_plataforma: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->user->id->value,
            'nombre' => $this->user->name->value,
            'telefono' => $this->user->phone?->value,
            'correo' => $this->user->email->value,
            'contrasena_temporal' => $this->user->mustChangePassword,
            'operador_de_plataforma' => $this->user->platformOperator,
        ];
    }
}
