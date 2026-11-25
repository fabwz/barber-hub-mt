<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Barbershops\Application;

use PHPUnit\Framework\TestCase;
use Src\Catalog\Barbershops\Application\Contracts\PersonMemberships;
use Src\Catalog\Barbershops\Application\DTOs\BarbershopRole;
use Src\Catalog\Barbershops\Application\DTOs\BarbershopSummary;
use Src\Catalog\Barbershops\Application\DTOs\MyBarbershop;
use Src\Catalog\Barbershops\Application\UseCases\ListMyBarbershops;
use Tests\Support\Catalog\InMemoryBarbershopSummaryReader;
use Tests\Support\Catalog\InMemoryCollaboratorProfiles;

final class ListMyBarbershopsTest extends TestCase
{
    /** Each barbershop carries the role of the person, in the order the reader gives. */
    public function test_pairs_each_barbershop_with_the_role_of_the_person(): void
    {
        $useCase = new ListMyBarbershops(
            $this->memberships(new BarbershopRole('b-2', 'customer'), new BarbershopRole('b-1', 'owner')),
            $this->reader(new BarbershopSummary('b-1', 'Fade Lab', 'fade-lab'), new BarbershopSummary('b-2', 'Pura Navaja', 'pura-navaja')),
            $this->profiles(['b-1' => 'c-1', 'b-2' => 'c-2']),
        );

        $this->assertEquals(
            [new MyBarbershop('b-1', 'Fade Lab', 'fade-lab', 'owner', 'c-1'), new MyBarbershop('b-2', 'Pura Navaja', 'pura-navaja', 'customer', null)],
            $useCase->execute('u-1'),
        );
    }

    /** Without memberships the reader is not even asked. */
    public function test_without_memberships_the_list_is_empty_and_nothing_is_read(): void
    {
        $reader = $this->reader(new BarbershopSummary('b-1', 'Fade Lab', 'fade-lab'));

        $this->assertSame([], new ListMyBarbershops($this->memberships(), $reader, $this->profiles([]))->execute('u-1'));
        $this->assertNull($reader->asked());
    }

    /** A barbershop the person holds no role in never appears, even if the reader returns it. */
    public function test_a_barbershop_without_a_role_of_the_person_is_dropped(): void
    {
        $useCase = new ListMyBarbershops(
            $this->memberships(new BarbershopRole('b-1', 'owner')),
            $this->reader(new BarbershopSummary('b-1', 'Fade Lab', 'fade-lab'), new BarbershopSummary('b-9', 'Ajena', 'ajena')),
            $this->profiles([]),
        );

        $this->assertEquals([new MyBarbershop('b-1', 'Fade Lab', 'fade-lab', 'owner', null)], $useCase->execute('u-1'));
    }

    /** The reader only receives the barbershops of the memberships of the person. */
    public function test_the_reader_only_receives_the_barbershops_of_the_person(): void
    {
        $reader = $this->reader(new BarbershopSummary('b-1', 'Fade Lab', 'fade-lab'));

        new ListMyBarbershops($this->memberships(new BarbershopRole('b-1', 'owner')), $reader, $this->profiles([]))->execute('u-1');

        $this->assertSame(['b-1'], $reader->asked());
    }

    /** The profile is looked up only where the person is owner or collaborator, and only in the barbershops of their memberships. */
    public function test_the_profile_is_only_looked_up_for_staff_roles(): void
    {
        $profiles = $this->profiles(['b-1' => 'c-1', 'b-2' => 'c-2', 'b-3' => 'c-3']);
        $useCase = new ListMyBarbershops(
            $this->memberships(new BarbershopRole('b-1', 'owner'), new BarbershopRole('b-2', 'customer'), new BarbershopRole('b-3', 'collaborator')),
            $this->reader(new BarbershopSummary('b-1', 'Fade Lab', 'fade-lab'), new BarbershopSummary('b-2', 'Corte', 'corte'), new BarbershopSummary('b-3', 'Pura Navaja', 'pura-navaja')),
            $profiles,
        );

        $list = $useCase->execute('u-1');

        $this->assertSame(['c-1', null, 'c-3'], array_map(fn (MyBarbershop $shop): ?string => $shop->collaboratorId, $list));
        $this->assertSame([['u-1', 'b-1'], ['u-1', 'b-3']], $profiles->asked);
    }

    /** An owner without a profile gets none. */
    public function test_an_owner_without_a_profile_gets_null(): void
    {
        $useCase = new ListMyBarbershops(
            $this->memberships(new BarbershopRole('b-1', 'owner')),
            $this->reader(new BarbershopSummary('b-1', 'Fade Lab', 'fade-lab')),
            $this->profiles([]),
        );

        $this->assertNull($useCase->execute('u-1')[0]->collaboratorId);
    }

    /** Fake the roles of the person. */
    private function memberships(BarbershopRole ...$roles): PersonMemberships
    {
        return new readonly class(array_values($roles)) implements PersonMemberships
        {
            /** @param list<BarbershopRole> $roles */
            public function __construct(private array $roles) {}

            /** @return list<BarbershopRole> */
            public function activeOf(string $userId): array
            {
                return $this->roles;
            }
        };
    }

    /**
     * Fake the profiles by barbershop, recording each lookup.
     *
     * @param  array<string, string>  $byBarbershop
     */
    private function profiles(array $byBarbershop): InMemoryCollaboratorProfiles
    {
        return new InMemoryCollaboratorProfiles($byBarbershop);
    }

    /** Fake the reader, recording the identifiers it was asked for. */
    private function reader(BarbershopSummary ...$summaries): InMemoryBarbershopSummaryReader
    {
        return new InMemoryBarbershopSummaryReader(array_values($summaries));
    }
}
