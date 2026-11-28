<?php

declare(strict_types=1);

namespace Src\Catalog\RentInstallments\Infrastructure;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Src\Catalog\RentInstallments\Application\Contracts\BarbershopScope;
use Src\Catalog\RentInstallments\Application\Contracts\RegisteredBarbershops;
use Src\Catalog\RentInstallments\Domain\Contracts\RentAmount;
use Src\Catalog\RentInstallments\Domain\Contracts\RentInstallmentRepository;
use Src\Catalog\RentInstallments\Domain\Services\RentCalendar;
use Src\Catalog\RentInstallments\Infrastructure\Console\GenerateRentInstallmentsCommand;
use Src\Catalog\RentInstallments\Infrastructure\Persistence\Queries\DatabaseRegisteredBarbershops;
use Src\Catalog\RentInstallments\Infrastructure\Persistence\Repositories\EloquentRentInstallmentRepository;
use Src\Catalog\RentInstallments\Infrastructure\Rent\ConfiguredRentAmount;
use Src\Catalog\RentInstallments\Infrastructure\Tenancy\ProcessBarbershopScope;

final class RentInstallmentsServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        RentInstallmentRepository::class => EloquentRentInstallmentRepository::class,
        RegisteredBarbershops::class => DatabaseRegisteredBarbershops::class,
        BarbershopScope::class => ProcessBarbershopScope::class,
    ];

    /** Bind the rent in force to the configured amount. */
    public function register(): void
    {
        $this->app->bind(RentAmount::class, fn (): RentAmount => new ConfiguredRentAmount((int) config('rent.monthly_amount')));
    }

    /** Register the command and declare its daily schedule. */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([GenerateRentInstallmentsCommand::class]);
        }

        // Daily, a few minutes after midnight of Costa Rica (HO-03). In production it only runs when the scheduler exists (#117); until then
        // `php artisan rent:generate-installments` is run by hand. Without overlapping: the unique key already makes a second run harmless.
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('rent:generate-installments')->dailyAt('00:05')->timezone(RentCalendar::ZONE)->withoutOverlapping();
        });
    }
}
