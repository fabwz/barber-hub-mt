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
#[SchemaName('CuentaOperador')]
final class PlatformAccountResource extends JsonResource
{
    /** Wrap the account of the platform operator who asks. */
    public function __construct(private readonly User $user)
    {
        parent::__construct($user);
    }

    /**
     * Expose the identity of the operator and nothing else: no phone, no password and no barbershop, because the operator has none.
     *
     * @return array{id: string, nombre: string, correo: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->user->id->value,
            'nombre' => $this->user->name->value,
            'correo' => $this->user->email->value,
        ];
    }
}
