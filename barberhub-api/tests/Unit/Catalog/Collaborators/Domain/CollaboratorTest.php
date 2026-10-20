<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Collaborators\Domain;

use PHPUnit\Framework\TestCase;
use Src\Catalog\Collaborators\Domain\Entities\Collaborator;
use Src\Catalog\Collaborators\Domain\ValueObjects\CollaboratorId;
use Src\Catalog\Collaborators\Domain\ValueObjects\OfferedServiceId;
use Src\Catalog\Collaborators\Domain\ValueObjects\WorkSchedule;
use Src\Shared\Domain\ValueObjects\UserId;

final class CollaboratorTest extends TestCase
{
    private const string COLLABORATOR_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d11';

    private const string USER_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d44';

    private const string HAIRCUT = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0e01';

    private const string KIDS_HAIRCUT = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0e02';

    /** A new profile is active and offers no service and no working hours until the owner assigns them. */
    public function test_new_profile_is_active_and_empty(): void
    {
        $collaborator = $this->collaborator();

        $this->assertTrue($collaborator->active);
        $this->assertSame([], $collaborator->services);
        $this->assertEquals(WorkSchedule::empty(), $collaborator->schedule);
    }

    /** A collaborator offers only the services assigned, and a repeated one counts once (CO-01). */
    public function test_offers_only_the_assigned_services(): void
    {
        $collaborator = $this->collaborator();

        $collaborator->offer([new OfferedServiceId(self::HAIRCUT), new OfferedServiceId(self::HAIRCUT)]);

        $this->assertCount(1, $collaborator->services);
        $this->assertTrue($collaborator->offers(new OfferedServiceId(self::HAIRCUT)));
        $this->assertFalse($collaborator->offers(new OfferedServiceId(self::KIDS_HAIRCUT)));
    }

    /** Offering again replaces the previous services instead of adding to them. */
    public function test_offering_replaces_the_services(): void
    {
        $collaborator = $this->collaborator();
        $collaborator->offer([new OfferedServiceId(self::HAIRCUT)]);

        $collaborator->offer([new OfferedServiceId(self::KIDS_HAIRCUT)]);

        $this->assertFalse($collaborator->offers(new OfferedServiceId(self::HAIRCUT)));
        $this->assertTrue($collaborator->offers(new OfferedServiceId(self::KIDS_HAIRCUT)));
    }

    /** Deactivating keeps the profile and its services; activating brings it back. */
    public function test_deactivating_and_activating_keep_the_profile(): void
    {
        $collaborator = $this->collaborator();
        $collaborator->offer([new OfferedServiceId(self::HAIRCUT)]);

        $collaborator->deactivate();
        $this->assertFalse($collaborator->active);
        $this->assertCount(1, $collaborator->services);

        $collaborator->activate();
        $this->assertTrue($collaborator->active);
    }

    /** Build a new profile. */
    private function collaborator(): Collaborator
    {
        return Collaborator::create(new CollaboratorId(self::COLLABORATOR_ID), new UserId(self::USER_ID));
    }
}
