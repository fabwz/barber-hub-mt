<?php

declare(strict_types=1);

namespace Tests\Unit\Reservations\Reservations\Application;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Src\Reservations\Reservations\Application\AttachNames;
use Src\Reservations\Reservations\Application\Contracts\CollaboratorDirectory;
use Src\Reservations\Reservations\Application\Contracts\CustomerDirectory;
use Src\Reservations\Reservations\Application\Contracts\ServiceNames;
use Src\Reservations\Reservations\Application\DTOs\ReservationSearch;
use Src\Reservations\Reservations\Application\UseCases\ListCustomerReservations;
use Src\Reservations\Reservations\Application\UseCases\ListStaffReservations;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\Exceptions\CollaboratorNotFoundException;
use Src\Reservations\Reservations\Domain\ValueObjects\Author;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Reservations\Reservations\Domain\ValueObjects\CustomerMembershipId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;
use Src\Reservations\Reservations\Domain\ValueObjects\ServiceId;
use Src\Shared\Domain\ValueObjects\Duration;
use Src\Shared\Domain\ValueObjects\Money;
use Src\Shared\Domain\ValueObjects\PageRequest;
use Src\Shared\Domain\ValueObjects\UserId;
use stdClass;
use Tests\Support\Reservations\InMemoryReservationRepository;

/** Who sees which reservations (RF-13, CL-04, CO-04, UP-06): the use cases decide the scope, whatever the request asks. */
final class ListReservationsTest extends TestCase
{
    public const string DANIELA = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d55';

    public const string KEVIN = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d56';

    public const string SERVICE = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d33';

    public const string ANA_ROLE = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d71';

    public const string LUIS_ROLE = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d72';

    public const string ANA_USER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d44';

    public const string KEVIN_USER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d46';

    private InMemoryReservationRepository $reservations;

    /** @var array<string, string> The collaborator profile of each account. */
    private array $profiles = [self::KEVIN_USER => self::KEVIN];

    /** @var array<string, string> The customer role of each account. */
    private array $roles = [self::ANA_USER => self::ANA_ROLE];

    private stdClass $directoryReads;

    /** Daniela has two reservations, of Ana and of Luis, and Kevin one, of Ana. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->directoryReads = new stdClass;
        $this->directoryReads->count = 0;
        $this->reservations = new InMemoryReservationRepository;
        $this->book(self::DANIELA, self::ANA_ROLE, '2030-03-05 10:00');
        $this->book(self::DANIELA, self::LUIS_ROLE, '2030-03-05 11:00');
        $this->book(self::KEVIN, self::ANA_ROLE, '2030-03-06 09:00');
    }

    /** The owner sees the reservations of every collaborator and may narrow to one. */
    public function test_the_owner_sees_every_collaborator(): void
    {
        $all = $this->staff()->execute(self::ANA_USER, true, new ReservationSearch, new PageRequest);
        $kevin = $this->staff()->execute(self::ANA_USER, true, new ReservationSearch(collaboratorId: self::KEVIN), new PageRequest);

        $this->assertSame(3, $all->total);
        $this->assertSame(['Ana', 'Luis', 'Ana'], array_map(fn ($view): ?string => $view->customerName, $all->items));
        $this->assertSame(['Kevin'], array_map(fn ($view): string => $view->collaboratorName, $kevin->items));
    }

    /** The owner naming a collaborator that is not in the barbershop gets the not-found of any missing one. */
    public function test_the_owner_asking_for_an_unknown_collaborator_gets_not_found(): void
    {
        $this->expectException(CollaboratorNotFoundException::class);

        $this->staff()->execute(self::ANA_USER, true, new ReservationSearch(collaboratorId: '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0dff'), new PageRequest);
    }

    /** A collaborator sees only their own profile's reservations, naming it or not. */
    public function test_a_collaborator_sees_only_their_own(): void
    {
        $named = $this->staff()->execute(self::KEVIN_USER, false, new ReservationSearch(collaboratorId: self::KEVIN), new PageRequest);
        $unnamed = $this->staff()->execute(self::KEVIN_USER, false, new ReservationSearch, new PageRequest);

        $this->assertSame(1, $named->total);
        $this->assertSame(1, $unnamed->total);
        $this->assertSame(self::KEVIN, $unnamed->items[0]->reservation->collaboratorId->value);
    }

    /** A collaborator asking for another agenda gets the same exception as an unknown collaborator, and nothing is read. */
    public function test_a_collaborator_asking_for_another_agenda_gets_not_found(): void
    {
        $this->expectException(CollaboratorNotFoundException::class);

        $this->staff()->execute(self::KEVIN_USER, false, new ReservationSearch(collaboratorId: self::DANIELA), new PageRequest);
    }

    /** A collaborator without a profile has no agenda: an empty page, not every reservation. */
    public function test_a_collaborator_without_a_profile_sees_nothing(): void
    {
        $page = $this->staff()->execute(self::ANA_USER, false, new ReservationSearch, new PageRequest);

        $this->assertSame([], $page->items);
        $this->assertSame(0, $page->total);
    }

    /** A customer sees only their role's reservations, without the name of any customer. */
    public function test_a_customer_sees_only_their_own(): void
    {
        $page = $this->customer()->execute(self::ANA_USER, new ReservationSearch, new PageRequest);

        $this->assertSame(2, $page->total);
        $this->assertSame([null, null], array_map(fn ($view): ?string => $view->customerName, $page->items));
        $this->assertSame([self::ANA_ROLE, self::ANA_ROLE], array_map(fn ($view): string => $view->reservation->customerMembershipId->value, $page->items));
    }

    /** A person without a customer role has an empty page and the repository and the directories are not read (RD-18). */
    public function test_a_person_without_a_role_gets_an_empty_page(): void
    {
        $page = $this->customer()->execute('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d99', new ReservationSearch, new PageRequest(2, 10));

        $this->assertSame([], $page->items);
        $this->assertSame(0, $page->total);
        $this->assertSame(2, $page->request->number);
        $this->assertSame(0, $this->directoryReads->count);
    }

    /** A filter by status narrows the page. */
    public function test_the_status_filter_narrows_the_page(): void
    {
        $page = $this->customer()->execute(self::ANA_USER, new ReservationSearch(status: ReservationStatus::Completed), new PageRequest);

        $this->assertSame(0, $page->total);
    }

    private function staff(): ListStaffReservations
    {
        return new ListStaffReservations($this->reservations, $this->collaborators(), $this->attach());
    }

    private function customer(): ListCustomerReservations
    {
        return new ListCustomerReservations($this->reservations, $this->customers(), $this->attach());
    }

    private function attach(): AttachNames
    {
        return new AttachNames(
            new class($this->directoryReads) implements ServiceNames
            {
                public function __construct(private stdClass $reads) {}

                public function all(): array
                {
                    $this->reads->count++;

                    return [ListReservationsTest::SERVICE => 'Degradado'];
                }
            },
            $this->collaborators(),
            $this->customers(),
        );
    }

    private function collaborators(): CollaboratorDirectory
    {
        return new class($this->profiles, $this->directoryReads) implements CollaboratorDirectory
        {
            /** @param array<string, string> $profiles */
            public function __construct(private array $profiles, private stdClass $reads) {}

            public function idOfUser(string $userId): ?string
            {
                return $this->profiles[$userId] ?? null;
            }

            public function names(): array
            {
                $this->reads->count++;

                return [ListReservationsTest::DANIELA => 'Daniela', ListReservationsTest::KEVIN => 'Kevin'];
            }
        };
    }

    private function customers(): CustomerDirectory
    {
        return new class($this->roles, $this->directoryReads) implements CustomerDirectory
        {
            /** @param array<string, string> $roles */
            public function __construct(private array $roles, private stdClass $reads) {}

            public function find(string $userId): ?string
            {
                return $this->roles[$userId] ?? null;
            }

            public function namesOf(array $membershipIds): array
            {
                $this->reads->count++;

                return [ListReservationsTest::ANA_ROLE => 'Ana', ListReservationsTest::LUIS_ROLE => 'Luis'];
            }
        };
    }

    /** Store a confirmed reservation of 45 minutes. */
    private function book(string $collaborator, string $role, string $startsAt): void
    {
        $author = Author::person(new UserId(self::ANA_USER));
        $now = new DateTimeImmutable('2030-03-01 08:00', new DateTimeZone('America/Costa_Rica'));
        $reservation = Reservation::create(
            $this->reservations->nextId(),
            new CollaboratorId($collaborator),
            new ServiceId(self::SERVICE),
            new CustomerMembershipId($role),
            new DateTimeImmutable($startsAt, new DateTimeZone('America/Costa_Rica')),
            Money::of(7000),
            Duration::ofMinutes(45),
            24,
            $author,
            $now,
        );
        $reservation->confirm($now, $author);
        $this->reservations->save($reservation);
    }
}
