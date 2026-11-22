<?php

declare(strict_types=1);

/*
 * Performance measurement of the availability query and of booking (issue #128, RNF-01 and RNF-02).
 *
 *   php performance/measure.php seed                          build the data of Pura Navaja (4 barbers, 7 services, 30 days of reservations)
 *   php performance/measure.php run [--iterations=200] [--bookings=100] [--warmup=20] [--json=path] [--keep]
 *                                                              seed from scratch, warm up, measure, print p50/p95/p99 and clean
 *   php performance/measure.php clean                         remove everything this script created
 *
 * It never runs in the CI: it is not part of any PHPUnit suite and no workflow calls it. It only touches the
 * barberhub_testing database (or barberhub_performance, if the environment variable PERF_DATABASE names it) and
 * refuses to boot against any other, so the development database is never reached. Every row it creates has a fixed
 * identifier or an email ending in @perf.barberhub.test, so seeding twice leaves the same rows and "clean" removes
 * exactly them. The clock is fixed (Friday 1 March 2030, 8:00), so the 30 days of data are the same every time.
 *
 * The requests go through the HTTP kernel of the application inside this process: routing, middleware, validation,
 * the use cases and real PostgreSQL queries with the application role and row-level security, without the network or
 * the web server. No payment gateway is involved: the barbershop does not charge, so booking confirms right away.
 */

const PERF_ALLOWED_DATABASES = ['barberhub_testing', 'barberhub_performance'];

$database = getenv('PERF_DATABASE') ?: 'barberhub_testing';

if (! in_array($database, PERF_ALLOWED_DATABASES, true)) {
    fwrite(STDERR, 'Refusing to run: the database must be one of '.implode(', ', PERF_ALLOWED_DATABASES).".\n");
    exit(2);
}

// The same overrides as phpunit.xml, set before the application reads its environment (they win over .env).
foreach ([
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'pgsql',
    'DB_DATABASE' => $database,
    'DB_URL' => '',
    'CACHE_STORE' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'BCRYPT_ROUNDS' => '4',
    'GEMINI_API_KEY' => '',
] as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

require __DIR__.'/../vendor/autoload.php';

use Database\Seeders\DemoBarbershopSeeder;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Src\Shared\Domain\Contracts\Clock;

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

foreach (['pgsql', 'pgsql_admin'] as $connection) {
    if (config("database.connections.{$connection}.database") !== $database) {
        fwrite(STDERR, "Refusing to run: the \"{$connection}\" connection does not point to {$database}.\n");
        exit(2);
    }
}

if (app()->environment('production')) {
    fwrite(STDERR, "Refusing to run in production.\n");
    exit(2);
}

// Fixed "today": the seeded days are the 30 that follow it.
DB::listen(static function (): void {
    $GLOBALS['perf_queries'] = ($GLOBALS['perf_queries'] ?? 0) + 1;
});

$app->instance(Clock::class, new class implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2030-03-01 08:00:00', new DateTimeZone('America/Costa_Rica'));
    }
});

const PERF_BARBERSHOP = '0192f0c4-0000-7000-8128-000000000001';
const PERF_LINK = 'pura-navaja-rendimiento';
const PERF_EMAIL_DOMAIN = '@perf.barberhub.test';
const PERF_FIRST_DAY = '2030-03-02';
const PERF_DAYS = 30;
const PERF_CUSTOMERS = 150;
const PERF_OCCUPANCY = 0.3;
const PERF_THRESHOLDS = ['availability' => 1000.0, 'booking' => 2000.0];

/** Identifier of a seeded row: a fixed prefix, the kind of row and its number. */
function perfId(int $kind, int $number): string
{
    return sprintf('0192f0c4-0000-7000-8128-%02x%010x', $kind, $number);
}

/** Connection of the table owner: it seeds and cleans, the requests use the application role. */
function admin(): Connection
{
    return DB::connection('pgsql_admin');
}

/** Run a block as the owner, reaching only the rows of the measured barbershop (row-level security is forced, owner included). */
function inBarbershop(Closure $work): void
{
    admin()->transaction(function () use ($work): void {
        admin()->statement('select set_config(?, ?, true)', ['app.barbershop_id', PERF_BARBERSHOP]);
        $work();
    });
}

/** Remove what this script created, children first. */
function clean(): void
{
    inBarbershop(function (): void {
        foreach (['reservation_status_history', 'reservations', 'collaborator_services', 'collaborator_shifts', 'collaborator_breaks', 'collaborators', 'services', 'opening_hours', 'memberships'] as $table) {
            admin()->table($table)->where('barbershop_id', PERF_BARBERSHOP)->delete();
        }
    });

    $users = admin()->table('users')->where('email', 'like', '%'.PERF_EMAIL_DOMAIN)->pluck('id')->all();
    admin()->table('personal_access_tokens')->whereIn('tokenable_id', $users)->delete();
    admin()->table('users')->whereIn('id', $users)->delete();
    admin()->table('barbershops')->where('id', PERF_BARBERSHOP)->delete();
}

/**
 * Build Pura Navaja: the catalog, hours and first four staff members of the demo seeder, a pool of customers and
 * 30 days of reservations that leave free spaces. Starting from scratch makes it idempotent.
 */
function seed(): int
{
    clean();
    mt_srand(128);

    $catalog = DemoBarbershopSeeder::CATALOG['pura-navaja'];
    $staff = array_slice($catalog['staff'], 0, 4);
    $password = Hash::make('perf-not-used');
    $now = '2030-03-01 08:00:00-06:00';

    admin()->table('barbershops')->insert([
        'id' => PERF_BARBERSHOP, 'name' => 'Barbería Pura Navaja (rendimiento)', 'city' => 'Ciudad Quesada', 'phone' => '0000-0128',
        'public_link' => PERF_LINK, 'payment_mode' => 'none', 'cancellation_deadline_hours' => 4,
    ]);

    $user = static fn (int $kind, int $number, string $name, string $email): array => [
        'id' => perfId($kind, $number), 'name' => $name, 'email' => $email, 'password' => $password, 'is_active' => true,
        'must_change_password' => false, 'terms_accepted_at' => $now, 'privacy_accepted_at' => $now,
    ];

    /** @var array<string, string> $serviceIds */
    $serviceIds = [];
    /** @var array<string, array{id: string, minutes: int, price: int}> $services */
    $services = [];
    /** @var list<array{id: string, name: string, services: list<string>, shifts: array<int, list<array{int, int}>>, breaks: array<int, list<array{int, int}>>}> $barbers */
    $barbers = [];
    $minutes = static fn (string $time): int => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);

    inBarbershop(function () use ($catalog, $staff, $user, &$serviceIds, &$services, &$barbers, $minutes): void {
        foreach ($catalog['hours'] as [$days, $from, $to]) {
            foreach ($days as $day) {
                admin()->table('opening_hours')->insert(['id' => Str::uuid7()->toString(), 'barbershop_id' => PERF_BARBERSHOP, 'day_of_week' => $day, 'opens_at' => $from, 'closes_at' => $to]);
            }
        }

        foreach ($catalog['services'] as $index => [$name, $duration, $price]) {
            $id = perfId(2, $index + 1);
            $serviceIds[$name] = $id;
            $services[$id] = ['id' => $id, 'minutes' => $duration, 'price' => $price];
            admin()->table('services')->insert(['id' => $id, 'barbershop_id' => PERF_BARBERSHOP, 'name' => $name, 'duration_minutes' => $duration, 'price' => $price, 'is_active' => true]);
        }

        foreach ($staff as $index => $member) {
            $position = $index + 1;
            $userId = perfId(3, $position);
            $collaboratorId = perfId(4, $position);
            admin()->table('users')->insert($user(3, $position, $member['name'], "colaborador-{$position}".PERF_EMAIL_DOMAIN));
            admin()->table('memberships')->insert(['id' => perfId(5, $position), 'user_id' => $userId, 'barbershop_id' => PERF_BARBERSHOP, 'role' => 'collaborator', 'is_active' => true]);
            admin()->table('collaborators')->insert(['id' => $collaboratorId, 'barbershop_id' => PERF_BARBERSHOP, 'user_id' => $userId, 'is_active' => true, 'created_at' => "2030-01-0{$position} 08:00:00-06:00"]);

            $offered = [];
            foreach ($member['services'] as $name) {
                $offered[] = $serviceIds[$name];
                admin()->table('collaborator_services')->insert(['id' => Str::uuid7()->toString(), 'barbershop_id' => PERF_BARBERSHOP, 'collaborator_id' => $collaboratorId, 'service_id' => $serviceIds[$name]]);
            }

            $windows = ['shifts' => [], 'breaks' => []];
            foreach (['shifts' => 'collaborator_shifts', 'breaks' => 'collaborator_breaks'] as $key => $table) {
                foreach ($member[$key] as [$days, $from, $to]) {
                    foreach ($days as $day) {
                        $windows[$key][$day][] = [$minutes($from), $minutes($to)];
                        admin()->table($table)->insert(['id' => Str::uuid7()->toString(), 'barbershop_id' => PERF_BARBERSHOP, 'collaborator_id' => $collaboratorId, 'day_of_week' => $day, 'starts_at' => $from, 'ends_at' => $to]);
                    }
                }
            }

            $barbers[] = ['id' => $collaboratorId, 'name' => $member['name'], 'services' => $offered, 'shifts' => $windows['shifts'], 'breaks' => $windows['breaks']];
        }

        foreach (range(1, PERF_CUSTOMERS) as $number) {
            admin()->table('users')->insert($user(6, $number, "Cliente {$number}", "cliente-{$number}".PERF_EMAIL_DOMAIN));
            admin()->table('memberships')->insert(['id' => perfId(7, $number), 'user_id' => perfId(6, $number), 'barbershop_id' => PERF_BARBERSHOP, 'role' => 'customer', 'is_active' => true]);
        }
    });

    $reservations = [];
    $history = [];
    /** @var array<int, list<array{int, int}>> $busy intervals (unix seconds) of each customer */
    $busy = [];
    $counter = 0;
    $first = new DateTimeImmutable(PERF_FIRST_DAY.' 00:00:00', new DateTimeZone('America/Costa_Rica'));

    for ($offset = 0; $offset < PERF_DAYS; $offset++) {
        $day = $first->modify("+{$offset} days");
        $weekday = (int) $day->format('N');

        foreach ($barbers as $barber) {
            foreach ($barber['shifts'][$weekday] ?? [] as [$from, $to]) {
                $cursor = $from;

                while ($cursor < $to) {
                    $serviceId = $barber['services'][mt_rand(0, count($barber['services']) - 1)];
                    $duration = $services[$serviceId]['minutes'];
                    $blocked = $duration + 5;
                    $inBreak = false;
                    foreach ($barber['breaks'][$weekday] ?? [] as [$breakFrom, $breakTo]) {
                        $inBreak = $inBreak || ($cursor < $breakTo && $cursor + $blocked > $breakFrom);
                    }

                    if ($cursor + $duration > $to || $inBreak || mt_rand() / mt_getrandmax() > PERF_OCCUPANCY) {
                        $cursor += 15;

                        continue;
                    }

                    $start = $day->modify("+{$cursor} minutes");
                    $end = $start->modify("+{$duration} minutes");
                    $customer = mt_rand(1, PERF_CUSTOMERS);
                    for ($tries = 0; $tries < PERF_CUSTOMERS; $tries++) {
                        $clash = false;
                        foreach ($busy[$customer] ?? [] as [$busyFrom, $busyTo]) {
                            $clash = $clash || ($start->getTimestamp() < $busyTo && $end->getTimestamp() > $busyFrom);
                        }
                        if (! $clash) {
                            break;
                        }
                        $customer = $customer % PERF_CUSTOMERS + 1;
                    }
                    $busy[$customer][] = [$start->getTimestamp(), $end->getTimestamp()];

                    $id = perfId(8, ++$counter);
                    $cancelled = mt_rand(1, 10) === 1;
                    $reservations[] = [
                        'id' => $id, 'barbershop_id' => PERF_BARBERSHOP, 'collaborator_id' => $barber['id'], 'service_id' => $serviceId,
                        'customer_membership_id' => perfId(7, $customer), 'status' => $cancelled ? 'cancelled_by_customer' : 'confirmed',
                        'starts_at' => $start->format('Y-m-d H:i:sP'), 'ends_at' => $end->format('Y-m-d H:i:sP'),
                        'blocked_until' => $end->modify('+5 minutes')->format('Y-m-d H:i:sP'), 'price' => $services[$serviceId]['price'],
                        'duration_minutes' => $duration, 'cancellation_deadline_hours' => 4, 'created_at' => $now, 'updated_at' => $now,
                    ];
                    $changes = [[null, 'temporary'], ['temporary', 'confirmed']];
                    if ($cancelled) {
                        $changes[] = ['confirmed', 'cancelled_by_customer'];
                    }
                    foreach ($changes as [$from_status, $to_status]) {
                        $history[] = [
                            'id' => Str::uuid7()->toString(), 'barbershop_id' => PERF_BARBERSHOP, 'reservation_id' => $id,
                            'from_status' => $from_status, 'to_status' => $to_status, 'changed_by_user_id' => perfId(6, $customer), 'changed_at' => $now,
                        ];
                    }

                    $cursor = intdiv($cursor + $blocked + 14, 15) * 15;
                }
            }
        }
    }

    inBarbershop(function () use ($reservations, $history): void {
        foreach (array_chunk($reservations, 500) as $chunk) {
            admin()->table('reservations')->insert($chunk);
        }
        foreach (array_chunk($history, 500) as $chunk) {
            admin()->table('reservation_status_history')->insert($chunk);
        }
        admin()->statement('analyze');
    });

    fwrite(STDOUT, sprintf("Seeded Pura Navaja: %d barbers, %d services, %d customers, %d reservations over %d days.\n", count($barbers), count($services), PERF_CUSTOMERS, count($reservations), PERF_DAYS));

    return count($reservations);
}

/**
 * Create the accounts that will book, one per request, so every booking is the first of its customer (UP-09): the
 * worst case, since it also creates the role. Their tokens are made here, outside the timed part.
 *
 * @return list<string> bearer tokens
 */
function bookingTokens(int $count): array
{
    $now = '2030-03-01 08:00:00-06:00';
    $rows = [];
    for ($number = 1; $number <= $count; $number++) {
        $rows[] = [
            'id' => perfId(9, $number), 'name' => "Reserva {$number}", 'email' => "reserva-{$number}".PERF_EMAIL_DOMAIN, 'password' => 'not-used', 'is_active' => true,
            'must_change_password' => false, 'terms_accepted_at' => $now, 'privacy_accepted_at' => $now,
        ];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        admin()->table('users')->insert($chunk);
    }

    $tokens = [];
    for ($number = 1; $number <= $count; $number++) {
        $tokens[] = UserModel::query()->findOrFail(perfId(9, $number))->createToken('perf')->plainTextToken;
    }

    return $tokens;
}

/** Send a request through the HTTP kernel and return the status, the decoded body the milliseconds the kernel took and the number of SQL queries it ran. */
function send(string $method, string $uri, ?string $token = null, ?array $body = null): array
{
    global $app;

    $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
    if ($token !== null) {
        $server['HTTP_AUTHORIZATION'] = "Bearer {$token}";
    }

    $request = Request::create($uri, $method, [], [], [], $server, $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
    $app['auth']->forgetGuards();
    $kernel = $app->make(HttpKernel::class);

    $GLOBALS['perf_queries'] = 0;
    $started = hrtime(true);
    $response = $kernel->handle($request);
    $elapsed = (hrtime(true) - $started) / 1e6;
    $queries = $GLOBALS['perf_queries'];
    $kernel->terminate($request, $response);

    return [$response->getStatusCode(), json_decode((string) $response->getContent(), true) ?? [], $elapsed, $queries];
}

/** Nearest-rank percentile of a sorted list. */
function percentile(array $sorted, float $percent): float
{
    return $sorted[max(0, (int) ceil($percent / 100 * count($sorted)) - 1)];
}

/**
 * Warm up and measure a scenario.
 *
 * @param  Closure(int): array{int, array<string, mixed>, float, int}  $call  the request of an iteration
 * @return array<string, mixed>
 */
function measure(string $name, string $kind, int $expected, int $warmup, int $iterations, Closure $call): array
{
    $times = [];
    $failures = [];
    $queryCounts = [];

    for ($i = 0; $i < $warmup + $iterations; $i++) {
        $began = hrtime(true);
        [$status, , $elapsed, $queries] = $call($i);
        if ($i % 25 === 0) {
            fwrite(STDOUT, sprintf('  %s: %d/%d (last call %.0f ms in total)
', $kind, $i, $warmup + $iterations, (hrtime(true) - $began) / 1e6));
        }

        if ($status !== $expected) {
            $failures[$status] = ($failures[$status] ?? 0) + 1;

            continue;
        }

        if ($i >= $warmup) {
            $times[] = $elapsed;
            $queryCounts[] = $queries;
        }
    }

    sort($times);
    $count = count($times);
    $p95 = $count > 0 ? percentile($times, 95) : NAN;

    return [
        'scenario' => $name,
        'threshold_ms' => PERF_THRESHOLDS[$kind],
        'warmup' => $warmup,
        'samples' => $count,
        'failures' => $failures,
        'min_ms' => $count > 0 ? round($times[0], 2) : null,
        'mean_ms' => $count > 0 ? round(array_sum($times) / $count, 2) : null,
        'p50_ms' => $count > 0 ? round(percentile($times, 50), 2) : null,
        'p95_ms' => $count > 0 ? round($p95, 2) : null,
        'p99_ms' => $count > 0 ? round(percentile($times, 99), 2) : null,
        'max_ms' => $count > 0 ? round($times[$count - 1], 2) : null,
        'queries_per_request' => $count > 0 ? round(array_sum($queryCounts) / $count, 1) : null,
        'meets_threshold' => $count > 0 && $p95 <= PERF_THRESHOLDS[$kind] && $failures === [],
    ];
}

/** The days of the seeded window. */
function perfDays(): array
{
    $first = new DateTimeImmutable(PERF_FIRST_DAY);

    return array_map(static fn (int $offset): string => $first->modify("+{$offset} days")->format('Y-m-d'), range(0, PERF_DAYS - 1));
}

/** Environment of the measurement, with nothing that identifies the person or the machine. */
function environment(): array
{
    $commit = trim((string) shell_exec('git rev-parse --short HEAD 2>&1'));
    $dirty = trim((string) shell_exec('git status --porcelain -- . 2>&1')) !== '';
    $cpu = getenv('PROCESSOR_IDENTIFIER') ?: trim((string) @shell_exec('lscpu 2>/dev/null | grep "Model name"'));
    $memory = null;
    if (PHP_OS_FAMILY === 'Windows') {
        $bytes = trim((string) @shell_exec('powershell -NoProfile -Command "(Get-CimInstance Win32_ComputerSystem).TotalPhysicalMemory" 2>NUL'));
        $memory = ctype_digit($bytes) ? round((int) $bytes / 1024 ** 3, 1).' GB' : null;
    }

    return [
        'date' => date('Y-m-d H:i:sP'),
        'commit' => $commit.($dirty ? ' (with uncommitted changes)' : ''),
        'os' => php_uname('s').' '.php_uname('r').' '.php_uname('m'),
        'cpu' => $cpu !== '' ? $cpu : null,
        'logical_cpus' => getenv('NUMBER_OF_PROCESSORS') ?: null,
        'memory' => $memory,
        'php' => PHP_VERSION.(extension_loaded('opcache') && ini_get('opcache.enable_cli') ? ' (opcache on)' : ' (opcache off for the CLI)'),
        'laravel' => app()->version(),
        'postgresql' => (string) DB::scalar('select current_setting(\'server_version\')'),
        'database' => config('database.connections.pgsql.database'),
        'database_host' => in_array(config('database.connections.pgsql.host'), ['127.0.0.1', 'localhost', '::1'], true) ? 'local' : 'remote',
    ];
}

/** Seed, warm up, measure and (unless asked to keep them) clean. */
function run(int $iterations, int $bookings, int $warmup, ?string $jsonPath, bool $keep): int
{
    $seeded = seed();

    $days = perfDays();
    $base = '/api/v1/barberias/'.PERF_BARBERSHOP;
    $services = array_values(DB::connection('pgsql_admin')->transaction(function (): array {
        admin()->statement('select set_config(?, ?, true)', ['app.barbershop_id', PERF_BARBERSHOP]);

        return admin()->table('services')->where('barbershop_id', PERF_BARBERSHOP)->orderBy('id')->pluck('id')->all();
    }));
    $barbers = array_map(static fn (int $n): string => perfId(4, $n), range(1, 4));

    // Barbers that offer a service, to ask for one of them.
    $offering = [];
    inBarbershop(function () use (&$offering): void {
        foreach (admin()->table('collaborator_services')->where('barbershop_id', PERF_BARBERSHOP)->get(['collaborator_id', 'service_id']) as $row) {
            $offering[$row->service_id][] = $row->collaborator_id;
        }
    });

    $availability = static fn (string $service, string $day, string $query): array => send('GET', "{$base}/disponibilidad?servicio={$service}&fecha={$day}{$query}");
    $parameters = static fn (int $i): array => [$services[$i % count($services)], $days[($i * 7) % count($days)]];

    $results = [];
    fwrite(STDOUT, 'Measuring availability...
');

    $results[] = measure('GET /disponibilidad de un día, todos los barberos del servicio', 'availability', 200, $warmup, $iterations, function (int $i) use ($availability, $parameters): array {
        [$service, $day] = $parameters($i);

        return $availability($service, $day, '');
    });

    $results[] = measure('GET /disponibilidad de un día, un barbero', 'availability', 200, $warmup, $iterations, function (int $i) use ($availability, $parameters, $offering): array {
        [$service, $day] = $parameters($i);
        $barber = $offering[$service][$i % count($offering[$service])];

        return $availability($service, $day, "&barbero={$barber}");
    });

    $results[] = measure('GET /disponibilidad de un día, «cualquiera» (extra, #54)', 'availability', 200, $warmup, $iterations, function (int $i) use ($availability, $parameters): array {
        [$service, $day] = $parameters($i);

        return $availability($service, $day, '&cualquiera=true');
    });

    $total = $warmup + $bookings;
    fwrite(STDOUT, 'Creating the booking accounts...
');
    $tokens = bookingTokens($total * 2);
    fwrite(STDOUT, 'Measuring booking...
');

    // A booking asks for a free space of a day first (untimed), as a client does, and then books it (timed). "Any available" books
    // the same space without naming the barber. A day or service without a free space is skipped.
    $book = static function (int $i, array $tokens, bool $any) use ($availability, $base, $days, $services): array {
        for ($attempt = 0; $attempt < count($days) * count($services); $attempt++) {
            [$service, $day] = [$services[($i + intdiv($attempt, count($days))) % count($services)], $days[($i * 11 + $attempt) % count($days)]];
            [, $body] = $availability($service, $day, '');
            $slots = [];
            foreach ($body['data']['barberos'] ?? [] as $barber) {
                foreach ($barber['espacios'] as $slot) {
                    $slots[] = $slot;
                }
            }

            if ($slots !== []) {
                $slot = $slots[($i * 13) % count($slots)];
                $payload = ['servicio' => $service, 'inicio' => $slot['inicio']] + ($any ? [] : ['barbero' => $slot['barbero']]);

                return send('POST', "{$base}/reservas", $tokens[$i], $payload);
            }
        }

        return [0, [], 0.0, 0];
    };

    $results[] = measure('POST /reservas con un barbero, sin cobro (sin pasarela)', 'booking', 201, $warmup, $bookings, static fn (int $i): array => $book($i, $tokens, false));
    $results[] = measure('POST /reservas con «cualquiera», sin cobro (extra)', 'booking', 201, $warmup, $bookings, static fn (int $i): array => $book($total + $i, $tokens, true));

    $report = [
        'environment' => environment(),
        'command' => 'php performance/measure.php run --iterations='.$iterations.' --bookings='.$bookings.' --warmup='.$warmup,
        'bookings' => $bookings,
        'iterations' => $iterations,
        'warmup' => $warmup,
        'data' => [
            'barbershop' => 'Pura Navaja (datos ficticios)',
            'barbers' => 4,
            'services' => 7,
            'days' => PERF_DAYS,
            'reservations_seeded' => $seeded,
        ],
        'scenarios' => $results,
    ];

    fwrite(STDOUT, sprintf("\n%-70s %7s %8s %8s %8s %8s %6s  %s\n", 'Scenario', 'samples', 'p50 ms', 'p95 ms', 'p99 ms', 'max ms', 'SQL', 'p95 limit'));
    foreach ($results as $result) {
        fwrite(STDOUT, sprintf(
            "%-70s %7d %8.1f %8.1f %8.1f %8.1f %6.1f  %s%s\n",
            $result['scenario'], $result['samples'], $result['p50_ms'] ?? NAN, $result['p95_ms'] ?? NAN, $result['p99_ms'] ?? NAN, $result['max_ms'] ?? NAN,
            $result['queries_per_request'] ?? NAN, $result['meets_threshold'] ? 'OK  ' : 'FAIL', $result['failures'] === [] ? '' : ' failures: '.json_encode($result['failures']),
        ));
    }

    if ($jsonPath !== null) {
        file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        fwrite(STDOUT, "\nJSON written to {$jsonPath}\n");
    }

    if (! $keep) {
        clean();
        fwrite(STDOUT, "Cleaned the data of the measurement.\n");
    }

    return 0;
}

$command = $argv[1] ?? '';
$options = [];
foreach (array_slice($argv, 2) as $argument) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $matches) === 1) {
        $options[$matches[1]] = $matches[2] ?? true;
    }
}

exit(match ($command) {
    'seed' => (static function (): int {
        seed();

        return 0;
    })(),
    'clean' => (static function (): int {
        clean();
        fwrite(STDOUT, "Cleaned the data of the measurement.\n");

        return 0;
    })(),
    'run' => run(
        max(1, (int) ($options['iterations'] ?? 200)),
        max(1, (int) ($options['bookings'] ?? 100)),
        max(0, (int) ($options['warmup'] ?? 20)),
        is_string($options['json'] ?? null) ? $options['json'] : null,
        isset($options['keep']),
    ),
    default => (static function (): int {
        fwrite(STDERR, "Usage: php performance/measure.php seed|run|clean [--iterations=200] [--bookings=100] [--warmup=20] [--json=path] [--keep]\n");

        return 1;
    })(),
});
