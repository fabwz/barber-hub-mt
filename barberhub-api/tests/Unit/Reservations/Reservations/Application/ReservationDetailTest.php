<?php

declare(strict_types=1);

namespace Tests\Unit\Reservations\Reservations\Application;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Src\Reservations\Reservations\Application\AttachNames;
use Src\Reservations\Reservations\Application\Contracts\AuthorDirectory;
use Src\Reservations\Reservations\Application\Contracts\CollaboratorDirectory;
use Src\Reservations\Reservations\Application\Contracts\CustomerDirectory;
use Src\Reservations\Reservations\Application\Contracts\ServiceNames;
use Src\Reservations\Reservations\Application\UseCases\GetCustomerReservation;
use Src\Reservations\Reservations\Application\UseCases\GetStaffReservation;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\Exceptions\ReservationNotFoundException;
use Src\Reservations\Reservations\Domain\ValueObjects\Author;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Reservations\Reservations\Domain\ValueObjects\CustomerMembershipId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;
use Src\Reservations\Reservations\Domain\ValueObjects\ServiceId;
use Src\Shared\Domain\ValueObjects\Duration;
use Src\Shared\Domain\ValueObjects\Money;
use Src\Shared\Domain\ValueObjects\UserId;
use Tests\Support\Reservations\InMemoryReservationRepository;

/** Who gets which projection of a reservation, and what each one carries (RF-14, CO-04, PA-10, RE-09, UP-02, UP-06). */
final class ReservationDetailTest extends TestCase
{
    public const string DANIELA = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d55';

    public const string KEVIN = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d56';

    public const string SERVICE = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d33';

    public const string ANA_ROLE = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d71';

    public const string LUIS_ROLE = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d72';

    public const string ANA_USER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d44';

    public const string LUIS_USER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d45';

    public const string DANIELA_USER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d46';

    public const string KEVIN_USER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d47';

    public const string OWNER_USER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d48';

    private InMemoryReservationRepository $reservations;

    private string $anas;

    /** Ana's reservation with Daniela is created by Ana, confirmed by the system and cancelled by the owner. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->reservations = new InMemoryReservationRepository;
        $at = new DateTimeImmutable('2030-03-01 08:00', new DateTimeZone('America/Costa_Rica'));
        $reservation = Reservation::create(
            $this->reservations->nextId(),
            new CollaboratorId(self::DANIELA),
            new ServiceId(self::SERVICE),
            new CustomerMembershipId(self::ANA_ROLE),
            new DateTimeImmutable('2030-03-05 10:00', new DateTimeZone('America/Costa_Rica')),
            Money::of(7000),
            Duration::ofMinutes(45),
            24,
            Author::person(new UserId(self::ANA_USER)),
            $at,
        );
        $reservation->confirm($at, Author::system());
        $reservation->cancelByBarbershop($at, Author::person(new UserId(self::OWNER_USER)));
        $this->reservations->save($reservation);
        $this->anas = $reservation->id->value;
    }

    /** The owner gets the balance and the history in order, with the name of each author and none for the system. */
    public function test_the_owner_gets_the_balance_and_the_history_with_authors(): void
    {
        $detail = $this->staff()->execute(self::OWNER_USER, true, $this->anas, $this->now());

        $this->assertSame(7000, $detail->pendingBalance);
        $this->assertSame('Ana', $detail->view->customerName);
        $this->assertNotNull($detail->history);
        $this->assertSame(
            [[null, ReservationStatus::Temporary, 'Ana'], [ReservationStatus::Temporary, ReservationStatus::Confirmed, null], [ReservationStatus::Confirmed, ReservationStatus::CancelledByBarbershop, 'Mauricio']],
            array_map(fn ($entry): array => [$entry->from, $entry->to, $entry->authorName], $detail->history),
        );
    }

    /** A collaborator gets the balance of their own reservation, never the history. */
    public function test_a_collaborator_gets_the_balance_without_the_history(): void
    {
        $detail = $this->staff()->execute(self::DANIELA_USER, false, $this->anas, $this->now());

        $this->assertSame(7000, $detail->pendingBalance);
        $this->assertNull($detail->history);
    }

    /** A collaborator, or one without a profile, asking for a reservation of another agenda gets not found. */
    public function test_a_collaborator_does_not_reach_another_agenda(): void
    {
        foreach ([self::KEVIN_USER, self::ANA_USER] as $user) {
            try {
                $this->staff()->execute($user, false, $this->anas, $this->now());
                $this->fail('The reservation of another agenda was shown.');
            } catch (ReservationNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** A reservation that does not exist is not found, for the owner too. */
    public function test_an_unknown_reservation_is_not_found(): void
    {
        $this->expectException(ReservationNotFoundException::class);

        $this->staff()->execute(self::OWNER_USER, true, '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0dff', $this->now());
    }

    /** The customer gets their reservation without the balance, the history or the name of a customer. */
    public function test_a_customer_gets_their_own_without_balance_or_history(): void
    {
        $detail = $this->customer()->execute(self::ANA_USER, $this->anas, $this->now());

        $this->assertSame($this->anas, $detail->view->reservation->id->value);
        $this->assertNull($detail->view->customerName);
        $this->assertNull($detail->pendingBalance);
        $this->assertNull($detail->history);
    }

    /** Another customer, a person without a role and an unknown reservation all get not found. */
    public function test_a_customer_does_not_reach_a_foreign_reservation(): void
    {
        foreach ([[self::LUIS_USER, $this->anas], ['0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d99', $this->anas], [self::ANA_USER, '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0dff']] as [$user, $reservation]) {
            try {
                $this->customer()->execute($user, $reservation, $this->now());
                $this->fail('A foreign reservation was shown.');
            } catch (ReservationNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function staff(): GetStaffReservation
    {
        return new GetStaffReservation(
            $this->reservations,
            $this->collaborators(),
            new class implements AuthorDirectory
            {
                public function namesOf(array $userIds): array
                {
                    return array_intersect_key([ReservationDetailTest::ANA_USER => 'Ana', ReservationDetailTest::OWNER_USER => 'Mauricio'], array_flip($userIds));
                }
            },
            $this->attach(),
        );
    }

    private function customer(): GetCustomerReservation
    {
        return new GetCustomerReservation($this->reservations, $this->customers(), $this->attach());
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2030-03-01 08:00:00');
    }

    private function attach(): AttachNames
    {
        return new AttachNames(
            new class implements ServiceNames
            {
                public function all(): array
                {
                    return [ReservationDetailTest::SERVICE => 'Degradado'];
                }
            },
            $this->collaborators(),
            $this->customers(),
        );
    }

    private function collaborators(): CollaboratorDirectory
    {
        return new class implements CollaboratorDirectory
        {
            public function idOfUser(string $userId): ?string
            {
                return [ReservationDetailTest::DANIELA_USER => ReservationDetailTest::DANIELA, ReservationDetailTest::KEVIN_USER => ReservationDetailTest::KEVIN][$userId] ?? null;
            }

            public function names(): array
            {
                return [ReservationDetailTest::DANIELA => 'Daniela', ReservationDetailTest::KEVIN => 'Kevin'];
            }
        };
    }

    private function customers(): CustomerDirectory
    {
        return new class implements CustomerDirectory
        {
            public function find(string $userId): ?string
            {
                return [ReservationDetailTest::ANA_USER => ReservationDetailTest::ANA_ROLE, ReservationDetailTest::LUIS_USER => ReservationDetailTest::LUIS_ROLE][$userId] ?? null;
            }

            public function namesOf(array $membershipIds): array
            {
                return [ReservationDetailTest::ANA_ROLE => 'Ana', ReservationDetailTest::LUIS_ROLE => 'Luis'];
            }
        };
    }
}
