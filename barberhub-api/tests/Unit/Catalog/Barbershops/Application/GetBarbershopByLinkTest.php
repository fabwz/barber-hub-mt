<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Barbershops\Application;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Catalog\Barbershops\Application\DTOs\PublicBarbershop;
use Src\Catalog\Barbershops\Application\UseCases\GetBarbershopByLink;
use Src\Catalog\Barbershops\Domain\Exceptions\BarbershopNotFoundException;
use Tests\Support\Catalog\InMemoryPublicBarbershopReader;

final class GetBarbershopByLinkTest extends TestCase
{
    private InMemoryPublicBarbershopReader $reader;

    private GetBarbershopByLink $getBarbershopByLink;

    /** Wire the use case to an in-memory reader with one barbershop. */
    protected function setUp(): void
    {
        $this->reader = new InMemoryPublicBarbershopReader;
        $this->reader->add('pura-navaja', new PublicBarbershop('0192f0c4-0000-7000-8000-000000000001', 'Barbería Pura Navaja', 'San Carlos', '2460-0000'));
        $this->getBarbershopByLink = new GetBarbershopByLink($this->reader);
    }

    /** A link returns the public data of its barbershop. */
    public function test_link_returns_the_public_data_of_its_barbershop(): void
    {
        $barbershop = $this->getBarbershopByLink->execute('pura-navaja');

        $this->assertSame('0192f0c4-0000-7000-8000-000000000001', $barbershop->id);
        $this->assertSame('Barbería Pura Navaja', $barbershop->name);
        $this->assertSame('San Carlos', $barbershop->city);
        $this->assertSame('2460-0000', $barbershop->phone);
    }

    /** A well-formed link that opens nothing is a missing barbershop. */
    public function test_unknown_link_is_not_found(): void
    {
        try {
            $this->getBarbershopByLink->execute('no-existe');
            $this->fail('An unknown link found a barbershop.');
        } catch (BarbershopNotFoundException $exception) {
            $this->assertSame('barbershops.not_found', $exception->translationKey());
            $this->assertSame(1, $this->reader->reads());
        }
    }

    /** A malformed link is a missing barbershop too, and it never reaches the reader. */
    #[DataProvider('malformedLinks')]
    public function test_malformed_link_is_not_found_without_a_query(string $link): void
    {
        try {
            $this->getBarbershopByLink->execute($link);
            $this->fail('A malformed link found a barbershop.');
        } catch (BarbershopNotFoundException) {
            $this->assertSame(0, $this->reader->reads());
        }
    }

    /**
     * Links outside the format.
     *
     * @return array<string, array{string}>
     */
    public static function malformedLinks(): array
    {
        return [
            'uppercase' => ['Pura-Navaja'],
            'underscore' => ['pura_navaja'],
            'accent' => ['barbería'],
            'empty' => [''],
            'too long' => [str_repeat('a', 121)],
        ];
    }
}
