<?php

declare(strict_types=1);

/*
 * Registers one barbershop in its own process, so a test can run it in parallel with another session.
 * Usage: php register_barbershop_worker.php "<name>" <owner id>. It prints the link as JSON.
 */

use Illuminate\Contracts\Console\Kernel;
use Src\Catalog\Barbershops\Application\DTOs\RegisterBarbershopCommand;
use Src\Catalog\Barbershops\Application\UseCases\RegisterBarbershop;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Same guard as the test suite: this process writes, so it must never reach another database.
if (config('database.connections.pgsql.database') !== 'barberhub_testing') {
    fwrite(STDERR, "The worker only runs on the barberhub_testing database.\n");
    exit(2);
}

$barbershop = $app->make(RegisterBarbershop::class)->execute(
    new RegisterBarbershopCommand($argv[1], 'San Carlos', '2460-0000', $argv[2]),
);

echo json_encode(['id' => $barbershop->id->value, 'link' => $barbershop->link->value], JSON_THROW_ON_ERROR);
