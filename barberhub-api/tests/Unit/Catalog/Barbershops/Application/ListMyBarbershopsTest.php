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

final class ListMyBarbershopsTest extends TestCase
{
    /** Each barbershop carries the role of the person, in the order the reader gives. */
    public function test_pairs_each_barbershop_with_the_role_of_the_person(): void
    {
        $useCase = new ListMyBarbershops(
            $this->memberships(new BarbershopRole('b-2', 'customer'), new BarbershopRole('b-1', 'owner')),
            $this->reader(new BarbershopSummary('b-1', 'Fade Lab', 'fade-lab'), new BarbershopSummary('b-2', 'Pura Navaja', 'pura-navaja')),
        );

        $this->assertEquals(
            [new MyBarbershop('b-1', 'Fade Lab', 'fade-lab', 'owner'), new MyBarbershop('b-2', 'Pura Navaja', 'pura-navaja', 'customer')],
            $useCase->execute('u-1'),
        );
    }

    /** Without memberships the reader is not even asked. */
    public function test_without_memberships_the_list_is_empty_and_nothing_is_read(): void
    {
        $reader = $this->reader(new BarbershopSummary('b-1', 'Fade Lab', 'fade-lab'));

        $this->assertSame([], new ListMyBarbershops($this->memberships(), $reader)->execute('u-1'));
        $this->assertNull($reader->asked());
    }

    /** A barbershop the person holds no role in never appears, even if the reader returns it. */
    public function test_a_barbershop_without_a_role_of_the_person_is_dropped(): void
    {
        $useCase = new ListMyBarbershops(
            $this->memberships(new BarbershopRole('b-1', 'owner')),
            $this->reader(new BarbershopSummary('b-1', 'Fade Lab', 'fade-lab'), new BarbershopSummary('b-9', 'Ajena', 'ajena')),
        );

        $this->assertEquals([new MyBarbershop('b-1', 'Fade Lab', 'fade-lab', 'owner')], $useCase->execute('u-1'));
    }

    /** The reader only receives the barbershops of the memberships of the person. */
    public function test_the_reader_only_receives_the_barbershops_of_the_person(): void
    {
        $reader = $this->reader(new BarbershopSummary('b-1', 'Fade Lab', 'fade-lab'));

        new ListMyBarbershops($this->memberships(new BarbershopRole('b-1', 'owner')), $reader)->execute('u-1');

        $this->assertSame(['b-1'], $reader->asked());
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

    /** Fake the reader, recording the identifiers it was asked for. */
    private function reader(BarbershopSummary ...$summaries): InMemoryBarbershopSummaryReader
    {
        return new InMemoryBarbershopSummaryReader(array_values($summaries));
    }
}
