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

    public const string CANCEL = 'cancel';

    /** Where the cancellation of a row is served, after the row. */
    public const string CANCEL_SUFFIX = '/cancelacion';

    /**
     * @param  string  $table  Table of the resource; it has a barbershop_id column.
     * @param  Closure(string): string  $createRow  Insert a row for the barbershop (it runs with that barbershop as context) and return its identifier.
     * @param  string  $path  Route of the collection as the router writes it, with {barberia}, for example "api/v1/barberias/{barberia}/servicios". A row is served at "<path>/<id>".
     * @param  list<string>  $operations  What the routes offer: LIST (GET path), SHOW (GET path/id), UPDATE (PATCH path/id), DELETE (DELETE path/id) and CANCEL (POST path/id/cancelacion).
     * @param  array<string, mixed>  $updatePayload  A valid body for UPDATE.
     * @param  list<string>  $otherPaths  Routes of the resource outside its collection path, such as "my reservations"; the coverage check counts them as its own.
     */
    public function __construct(
        public string $table,
        public Closure $createRow,
        public string $path,
        public array $operations,
        public array $updatePayload = [],
        public array $otherPaths = [],
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
        foreach ([$this->path, ...$this->otherPaths] as $path) {
            if ($routeUri === $path || str_starts_with($routeUri, $path.'/')) {
                return true;
            }
        }

        return false;
    }
}
