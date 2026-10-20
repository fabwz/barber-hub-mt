<?php

declare(strict_types=1);

namespace Tests\Support\Tenancy;

use Closure;

/** A resource that belongs to a barbershop: its table, how to give a barbershop a row of it and the routes that serve it. */
final readonly class TenantResource
{
    public const string LIST = 'list';

    public const string SHOW = 'show';

    public const string UPDATE = 'update';

    public const string DELETE = 'delete';

    /**
     * @param  string  $table  Table of the resource; it has a barbershop_id column.
     * @param  Closure(string): string  $createRow  Insert a row for the barbershop (it runs with that barbershop as context) and return its identifier.
     * @param  string  $path  Route of the collection as the router writes it, with {barberia}, for example "api/v1/barberias/{barberia}/servicios". A row is served at "<path>/<id>".
     * @param  list<string>  $operations  What the routes offer: LIST (GET path), SHOW (GET path/id), UPDATE (PATCH path/id) and DELETE (DELETE path/id).
     * @param  array<string, mixed>  $updatePayload  A valid body for UPDATE.
     */
    public function __construct(
        public string $table,
        public Closure $createRow,
        public string $path,
        public array $operations,
        public array $updatePayload = [],
    ) {}

    /** Whether the routes of the resource offer the operation. */
    public function supports(string $operation): bool
    {
        return in_array($operation, $this->operations, true);
    }

    /** Address of the collection, or of one row, in a barbershop. */
    public function uri(string $barbershopId, ?string $rowId = null): string
    {
        $uri = '/'.str_replace('{barberia}', $barbershopId, $this->path);

        return $rowId === null ? $uri : "{$uri}/{$rowId}";
    }

    /** Whether a route (as the router writes it) is one of this resource. */
    public function owns(string $routeUri): bool
    {
        return $routeUri === $this->path || str_starts_with($routeUri, $this->path.'/');
    }
}
