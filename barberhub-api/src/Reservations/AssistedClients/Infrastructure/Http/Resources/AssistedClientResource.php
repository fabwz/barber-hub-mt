<?php

declare(strict_types=1);

namespace Src\Reservations\AssistedClients\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Reservations\AssistedClients\Domain\Entities\AssistedClient;

/**
 * @property-read AssistedClient $resource
 */
#[SchemaName('ClienteAsistido')]
final class AssistedClientResource extends JsonResource
{
    /** Wrap an assisted client entity. */
    public function __construct(private readonly AssistedClient $client)
    {
        parent::__construct($client);
    }

    /**
     * Expose the assisted client with the vocabulary of the business; only the owner reads it.
     *
     * @return array{id: string, nombre: string, telefono: string, correo: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->client->id->value,
            'nombre' => $this->client->name->value,
            'telefono' => $this->client->phone->value,
            /** Nulo si no tiene correo: sin él no recibe avisos ni recordatorios. */
            'correo' => $this->client->email?->value,
        ];
    }
}
