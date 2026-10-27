<?php

declare(strict_types=1);

namespace Tests\Support\Tenancy;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Shared\BarbershopIsolation;

/**
 * The checks behind the tenancy tests (RNF-06, MT-01 to MT-06). Each one returns the list of violations instead of
 * asserting, so a test can also feed it a deliberate leak and see that it reports it.
 */
final class TenancyAudit
{
    /**
     * Whether every table and route of a barbershop is in the registry: a table with barbershop_id (except the documented
     * exceptions) must be the table of a resource, and a route with {barberia} must pass through the barbershop
     * middleware and belong to a resource or be listed as public.
     *
     * @param  list<TenantResource>  $resources
     * @param  list<string>  $publicRoutes  "METHOD uri", as in TenantResources::PUBLIC_ROUTES.
     * @param  list<string>  $customerRoutes  "METHOD uri" of the operations that create the first membership, as in TenantResources::CUSTOMER_ROUTES (RD-18).
     * @param  list<string>  $barbershopRoutes  "METHOD uri" of the routes that act on the barbershop itself and belong to no resource, as in TenantResources::BARBERSHOP_ROUTES.
     * @return list<string>
     */
    public static function coverageViolations(array $resources, ConnectionInterface $db, RouteCollectionInterface $routes, array $publicRoutes, array $customerRoutes = [], array $barbershopRoutes = []): array
    {
        $violations = [];
        $registered = array_map(fn (TenantResource $resource): string => $resource->table, $resources);
        $tables = BarbershopIsolation::tablesWithBarbershopId($db);

        foreach (array_diff($tables, BarbershopIsolation::EXCEPTIONS, $registered) as $table) {
            $violations[] = "{$table}: has barbershop_id but is not in TenantResources";
        }

        foreach (array_diff($registered, $tables) as $table) {
            $violations[] = "{$table}: is in TenantResources but is not a table with barbershop_id";
        }

        foreach ($routes->getRoutes() as $route) {
            if (! str_contains($route->uri(), '{barberia}')) {
                continue;
            }

            $name = implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri();
            $public = in_array($name, $publicRoutes, true);
            $customer = in_array($name, $customerRoutes, true);
            $expected = match (true) {
                $public => 'barbershop:public',
                $customer => 'barbershop:customer',
                default => 'barbershop',
            };

            if (! in_array($expected, $route->gatherMiddleware(), true)) {
                $violations[] = "{$name}: is without the {$expected} middleware";
            }

            if (! $public && ! in_array($name, $barbershopRoutes, true) && array_filter($resources, fn (TenantResource $resource): bool => $resource->owns($route->uri())) === []) {
                $violations[] = "{$name}: belongs to no resource of TenantResources and is not a listed public route";
            }
        }

        return $violations;
    }

    /**
     * Try every resource against a second barbershop as a person of the first one. The requests are sent as that person
     * and the rows are created and read through the application role, scoped to each barbershop in turn.
     *
     * Per resource: with the URL of the other barbershop every operation answers the 404 of a barbershop that does not
     * exist; with the own barbershop and a row of the other every operation answers the 404 of a row that does not exist;
     * the listing has no row of the other; the row of the other stays as it was; and a query without a barbershop filter
     * returns no row of the other. The own rows are tried too, so a 404 caused by a missing route does not pass for isolation.
     *
     * @param  list<TenantResource>  $resources
     * @param  Closure(string, string, array<string, mixed>): TestResponse<Response>  $send  Send a request as the person of $own: method, URI and body.
     * @return list<string>
     */
    public static function isolationViolations(array $resources, string $own, string $other, Closure $send): array
    {
        $violations = [];
        $transaction = new BarbershopTransaction;

        foreach ($resources as $resource) {
            $table = $resource->table;
            $create = fn (string $barbershopId): string => $transaction->run($barbershopId, fn (): string => ($resource->createRow)($barbershopId));
            $ownRow = $create($own);
            $otherRow = $create($other);
            $before = self::snapshot($transaction, $resource, $other, $otherRow);
            $unknown = (string) Str::uuid7();
            $missing = (string) Str::uuid7();

            // The own rows work, so the 404 below is not just a missing route.
            if ($resource->supports(TenantResource::LIST)) {
                $content = (string) $send('GET', $resource->uri($own), [])->getContent();

                if (! str_contains($content, $ownRow)) {
                    $violations[] = "{$table}: the listing of the own barbershop does not show the own row";
                }

                if (str_contains($content, $otherRow)) {
                    $violations[] = "{$table}: the listing of the own barbershop shows a row of the other barbershop";
                }
            }

            if ($resource->supports(TenantResource::SHOW) && ! self::succeeds($send('GET', $resource->uri($own, $ownRow), []))) {
                $violations[] = "{$table}: show of an own row does not succeed";
            }

            if ($resource->supports(TenantResource::UPDATE) && ! self::succeeds($send('PATCH', $resource->uri($own, $ownRow), $resource->updatePayload))) {
                $violations[] = "{$table}: update of an own row does not succeed";
            }

            if ($resource->supports(TenantResource::DELETE) && ! self::succeeds($send('DELETE', $resource->uri($own, $create($own)), []))) {
                $violations[] = "{$table}: delete of an own row does not succeed";
            }

            if ($resource->supports(TenantResource::CANCEL) && ! self::succeeds($send('POST', $resource->uri($own, $create($own)).TenantResource::CANCEL_SUFFIX, []))) {
                $violations[] = "{$table}: cancel of an own row does not succeed";
            }

            foreach (self::requests($resource) as [$operation, $method, $payload, $withRow]) {
                $suffix = match ($operation) {
                    TenantResource::CANCEL => TenantResource::CANCEL_SUFFIX,
                    TenantResource::REFUND => TenantResource::REFUND_SUFFIX,
                    default => '',
                };
                // The URL of the other barbershop answers like a barbershop that does not exist.
                $swapped = $send($method, $resource->uri($other, $withRow ? $otherRow : null).$suffix, $payload);
                $nobody = $send($method, $resource->uri($unknown, $withRow ? $otherRow : null).$suffix, $payload);
                $violations = [...$violations, ...self::notFoundViolations("{$table}: {$operation} with the URL of the other barbershop", $swapped, $nobody)];

                if ($withRow) {
                    // A row of the other barbershop under the own one answers like a row that does not exist.
                    $foreign = $send($method, $resource->uri($own, $otherRow).$suffix, $payload);
                    $absent = $send($method, $resource->uri($own, $missing).$suffix, $payload);
                    $violations = [...$violations, ...self::notFoundViolations("{$table}: {$operation} of a row of the other barbershop", $foreign, $absent)];
                }
            }

            if (self::snapshot($transaction, $resource, $other, $otherRow) !== $before) {
                $violations[] = "{$table}: the row of the other barbershop changed or disappeared";
            }

            foreach ([[$own, $otherRow], [$other, $ownRow]] as [$context, $foreignRow]) {
                $transaction->scope($context);

                if (DB::table($table)->get()->contains(fn (object $row): bool => $row->barbershop_id !== $context) || DB::table($table)->where('id', $foreignRow)->exists()) {
                    $violations[] = "{$table}: a query without a barbershop filter returned a row of another barbershop";
                }
            }

            $transaction->scope(null);
        }

        return $violations;
    }

    /**
     * The attempts a resource offers, as operation, method, body and whether the address carries a row.
     *
     * @return list<array{string, string, array<string, mixed>, bool}>
     */
    private static function requests(TenantResource $resource): array
    {
        $requests = [];

        foreach ([
            [TenantResource::LIST, 'GET', [], false],
            [TenantResource::SHOW, 'GET', [], true],
            [TenantResource::UPDATE, 'PATCH', $resource->updatePayload, true],
            [TenantResource::DELETE, 'DELETE', [], true],
            [TenantResource::CANCEL, 'POST', [], true],
            [TenantResource::REFUND, 'POST', [], true],
        ] as $request) {
            if ($resource->supports($request[0])) {
                $requests[] = $request;
            }
        }

        return $requests;
    }

    /**
     * Whether the attempt answered 404 with the same body as the one that targets nothing.
     *
     * @param  TestResponse<Response>  $attempt
     * @param  TestResponse<Response>  $nothing
     * @return list<string>
     */
    private static function notFoundViolations(string $what, TestResponse $attempt, TestResponse $nothing): array
    {
        if ($attempt->getStatusCode() !== 404) {
            return ["{$what} answered {$attempt->getStatusCode()} instead of 404"];
        }

        if (Arr::except((array) $attempt->json(), 'instance') !== Arr::except((array) $nothing->json(), 'instance')) {
            return ["{$what} answered a 404 different from the one of a missing resource"];
        }

        return [];
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private static function succeeds(TestResponse $response): bool
    {
        return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
    }

    /**
     * A row as its barbershop sees it, or null when it does not see it.
     *
     * @return array<string, mixed>|null
     */
    private static function snapshot(BarbershopTransaction $transaction, TenantResource $resource, string $barbershopId, string $rowId): ?array
    {
        $transaction->scope($barbershopId);
        $row = DB::table($resource->table)->where('id', $rowId)->first();

        return $row === null ? null : (array) $row;
    }
}
