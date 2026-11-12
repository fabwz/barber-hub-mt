<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use Src\Reservations\Reservations\Application\Contracts\AuthorDirectory;
use Src\Reservations\Reservations\Application\Contracts\BlockedIntervalReader;
use Src\Reservations\Reservations\Application\Contracts\BookableServices;
use Src\Reservations\Reservations\Application\Contracts\CancellationDeadlines;
use Src\Reservations\Reservations\Application\Contracts\CollaboratorDirectory;
use Src\Reservations\Reservations\Application\Contracts\CollaboratorLock;
use Src\Reservations\Reservations\Application\Contracts\CustomerDirectory;
use Src\Reservations\Reservations\Application\Contracts\CustomerMemberships;
use Src\Reservations\Reservations\Application\Contracts\FreeSlots;
use Src\Reservations\Reservations\Application\Contracts\PaymentAmounts;
use Src\Reservations\Reservations\Application\Contracts\PaymentChannels;
use Src\Reservations\Reservations\Application\Contracts\PaymentRequirement;
use Src\Reservations\Reservations\Application\Contracts\ReservationExtractor;
use Src\Reservations\Reservations\Application\Contracts\ServiceNames;
use Src\Reservations\Reservations\Application\Exceptions\ExtractorMisconfiguredException;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Infrastructure\Accounts\UsersAuthorDirectory;
use Src\Reservations\Reservations\Infrastructure\Ai\GeminiReservationExtractor;
use Src\Reservations\Reservations\Infrastructure\Availability\AvailabilityFreeSlots;
use Src\Reservations\Reservations\Infrastructure\Barbershops\BarbershopsCancellationDeadlines;
use Src\Reservations\Reservations\Infrastructure\Barbershops\BarbershopsPaymentRequirement;
use Src\Reservations\Reservations\Infrastructure\Collaborators\CollaboratorsCollaboratorDirectory;
use Src\Reservations\Reservations\Infrastructure\Collaborators\CollaboratorsCollaboratorLock;
use Src\Reservations\Reservations\Infrastructure\Memberships\MembershipsCustomerDirectory;
use Src\Reservations\Reservations\Infrastructure\Memberships\MembershipsCustomerMemberships;
use Src\Reservations\Reservations\Infrastructure\Payments\PaymentsPaymentAmounts;
use Src\Reservations\Reservations\Infrastructure\Payments\PaymentsPaymentChannels;
use Src\Reservations\Reservations\Infrastructure\Persistence\Queries\EloquentBlockedIntervalReader;
use Src\Reservations\Reservations\Infrastructure\Persistence\Repositories\EloquentReservationRepository;
use Src\Reservations\Reservations\Infrastructure\Services\ServicesBookableServices;
use Src\Reservations\Reservations\Infrastructure\Services\ServicesServiceNames;

final class ReservationsServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        ReservationRepository::class => EloquentReservationRepository::class,
        BlockedIntervalReader::class => EloquentBlockedIntervalReader::class,
        BookableServices::class => ServicesBookableServices::class,
        CancellationDeadlines::class => BarbershopsCancellationDeadlines::class,
        PaymentRequirement::class => BarbershopsPaymentRequirement::class,
        PaymentAmounts::class => PaymentsPaymentAmounts::class,
        PaymentChannels::class => PaymentsPaymentChannels::class,
        CollaboratorLock::class => CollaboratorsCollaboratorLock::class,
        CustomerMemberships::class => MembershipsCustomerMemberships::class,
        FreeSlots::class => AvailabilityFreeSlots::class,
        CollaboratorDirectory::class => CollaboratorsCollaboratorDirectory::class,
        CustomerDirectory::class => MembershipsCustomerDirectory::class,
        ServiceNames::class => ServicesServiceNames::class,
        AuthorDirectory::class => UsersAuthorDirectory::class,
    ];

    /** Bind the Gemini adapter with its configuration and the extraction port with the provider chosen in config/ai.php. */
    public function register(): void
    {
        $this->app->bind(GeminiReservationExtractor::class, fn (Application $app): GeminiReservationExtractor => new GeminiReservationExtractor(
            $app->make(Factory::class),
            (string) config('ai.gemini.api_key'),
            (string) config('ai.gemini.model'),
            (string) config('ai.gemini.base_url'),
        ));

        $this->app->bind(ReservationExtractor::class, function (Application $app): ReservationExtractor {
            $class = config('ai.providers.'.config('ai.provider'));
            $extractor = is_string($class) ? $app->make($class) : null;

            return $extractor instanceof ReservationExtractor ? $extractor : throw ExtractorMisconfiguredException::because('the provider in config/ai.php has no adapter.');
        });
    }
}
