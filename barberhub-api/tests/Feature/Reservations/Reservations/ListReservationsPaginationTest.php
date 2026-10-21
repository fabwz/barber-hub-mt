<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\Reservations;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\TestCase;

/**
 * The two listings of reservations are paginated as the reference paginates its listings (15 by default, up to 50) and
 * read the names of what they show once, never row by row.
 */
final class ListReservationsPaginationTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;

    private string $ana;

    private string $anaRole;

    private int $created = 0;

    /** Seed the barbershops and Ana as a customer of Pura Navaja. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        $this->scopeQueriesTo($this->puraNavaja);
        [$this->ana, $this->anaRole] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
    }

    /** Without parameters a listing answers the first 15 reservations and says how many pages there are. */
    #[DataProvider('listings')]
    public function test_listing_answers_the_first_page_of_fifteen(string $who): void
    {
        $this->addReservations(20, $who);
        $url = $this->listing($who);

        $this->getJson($url)
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta', ['pagina' => 1, 'por_pagina' => 15, 'total' => 20, 'ultima_pagina' => 2]);
    }

    /** The second page continues where the first one ended, without repeating reservations and keeping the order of the start. */
    #[DataProvider('listings')]
    public function test_pages_do_not_repeat_reservations(string $who): void
    {
        $this->addReservations(20, $who);
        $url = $this->listing($who);

        $first = $this->getJson($url.'?por_pagina=12')->assertOk()->json('data.*.id');
        $second = $this->getJson($url.'?por_pagina=12&pagina=2')->assertOk()->assertJsonCount(8, 'data')->json('data.*.id');

        $this->assertSame([], array_intersect($first, $second));
        $starts = collect([...$this->getJson($url.'?por_pagina=12')->json('data'), ...$this->getJson($url.'?por_pagina=12&pagina=2')->json('data')])->pluck('inicio')->all();
        $sorted = $starts;
        sort($sorted);
        $this->assertSame($sorted, $starts);
    }

    /** A page after the last one is empty but keeps the total. */
    #[DataProvider('listings')]
    public function test_page_after_the_last_is_empty(string $who): void
    {
        $this->addReservations(20, $who);

        $this->getJson($this->listing($who).'?pagina=3')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 20);
    }

    /** At most 50 reservations per page; a page starts at 1. */
    #[DataProvider('listings')]
    public function test_page_out_of_range_is_rejected(string $who): void
    {
        $this->getJson($this->listing($who).'?por_pagina=51&pagina=0')
            ->assertUnprocessable()
            ->assertJsonPath('errores.por_pagina.0', 'El campo elementos por página debe estar entre 1 y 50.')
            ->assertJsonPath('errores.pagina.0', 'El campo página debe ser al menos 1.');
    }

    /** A listing runs the same queries for 1 reservation as for 50, whoever asks: nothing is read row by row. */
    #[DataProvider('listings')]
    public function test_listing_runs_the_same_queries_for_one_row_or_fifty(string $who): void
    {
        $this->addReservations(1, $who);
        $url = $this->listing($who).'?por_pagina=50';
        $withOne = $this->queriesOf($url);

        $this->addReservations(49, $who);
        $withFifty = $this->queriesOf($url);

        $this->assertSame($withOne, $withFifty);
        $this->assertGreaterThan(0, $withOne);
    }

    /**
     * The listings, by who asks: the owner reads the staff one and Ana, her own.
     *
     * @return array<string, array{string}>
     */
    public static function listings(): array
    {
        return ['staff' => ['owner'], 'customer' => ['customer']];
    }

    /** Authenticate who asks and give the address of the listing they read. */
    private function listing(string $who): string
    {
        if ($who === 'owner') {
            $this->actAs($this->owner);

            return $this->reservationsUrl($this->puraNavaja);
        }

        $this->actAs($this->ana);

        return $this->myReservationsUrl($this->puraNavaja);
    }

    /** Number of statements a request runs. */
    private function queriesOf(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($url)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    /**
     * Add reservations to Pura Navaja, one a day from the first free one so no two overlap, alternating the collaborator.
     * Those the owner reads each belong to a new customer, so a read per customer would show; those Ana reads are all hers.
     */
    private function addReservations(int $count, string $who): void
    {
        $collaborators = [$this->daniela, $this->kevin];

        for ($i = 0; $i < $count; $i++) {
            $n = ++$this->created;
            $role = $who === 'owner' ? $this->insertCustomer($this->puraNavaja, "Cliente {$n}")[1] : $this->anaRole;
            $start = new DateTimeImmutable('2030-04-01T09:00:00-06:00')->modify("+{$n} days")->format(DATE_ATOM);
            $this->insertReservation($this->puraNavaja, $collaborators[$n % 2], $this->degradado, $role, $start);
        }
    }
}
