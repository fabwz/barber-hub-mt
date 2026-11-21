<?php

declare(strict_types=1);

namespace Tests\Unit\Availability\Slots\Domain;

use PHPUnit\Framework\TestCase;
use Src\Availability\Slots\Domain\Services\AnyAvailableAssigner;
use Src\Availability\Slots\Domain\ValueObjects\AssignmentCandidate;
use Src\Availability\Slots\Domain\ValueObjects\CollaboratorId;

/** The strategy of "any available": the fewest reservations that day and, on a tie, the oldest registration (DI-04). */
final class AnyAvailableAssignerTest extends TestCase
{
    private const string DANIELA = '0192f3a0-0000-7000-8000-000000000001';

    private const string KEVIN = '0192f3a0-0000-7000-8000-000000000002';

    private const string LUIS = '0192f3a0-0000-7000-8000-000000000003';

    /** The collaborator with fewer reservations wins even if they were registered last. */
    public function test_the_one_with_fewer_reservations_that_day_is_chosen(): void
    {
        $chosen = new AnyAvailableAssigner()->choose([
            $this->candidate(self::DANIELA, reservations: 4, rank: 0),
            $this->candidate(self::KEVIN, reservations: 1, rank: 1),
            $this->candidate(self::LUIS, reservations: 2, rank: 2),
        ]);

        $this->assertSame(self::KEVIN, $chosen?->value);
    }

    /** With the same reservations the one registered first wins, whatever the order of the list. */
    public function test_a_tie_goes_to_the_oldest_registration(): void
    {
        $assigner = new AnyAvailableAssigner;
        $candidates = [
            $this->candidate(self::LUIS, reservations: 2, rank: 2),
            $this->candidate(self::KEVIN, reservations: 2, rank: 1),
            $this->candidate(self::DANIELA, reservations: 5, rank: 0),
        ];

        $this->assertSame(self::KEVIN, $assigner->choose($candidates)?->value);
        $this->assertSame(self::KEVIN, $assigner->choose(array_reverse($candidates))?->value);
    }

    /** With no reservations at all the oldest registration is chosen. */
    public function test_with_no_reservations_the_oldest_is_chosen(): void
    {
        $chosen = new AnyAvailableAssigner()->choose([
            $this->candidate(self::LUIS, reservations: 0, rank: 2),
            $this->candidate(self::DANIELA, reservations: 0, rank: 0),
        ]);

        $this->assertSame(self::DANIELA, $chosen?->value);
    }

    /** One candidate is the answer, however loaded their day. */
    public function test_a_single_candidate_is_chosen(): void
    {
        $this->assertSame(self::LUIS, new AnyAvailableAssigner()->choose([$this->candidate(self::LUIS, reservations: 9, rank: 3)])?->value);
    }

    /** Without candidates there is nobody to assign. */
    public function test_without_candidates_nobody_is_chosen(): void
    {
        $this->assertNotInstanceOf(CollaboratorId::class, new AnyAvailableAssigner()->choose([]));
    }

    private function candidate(string $id, int $reservations, int $rank): AssignmentCandidate
    {
        return new AssignmentCandidate(new CollaboratorId($id), $reservations, $rank);
    }
}
