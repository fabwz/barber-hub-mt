<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\DB;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\TestCase;

/**
 * The creation of a reservation is a customer operation, an exception of RD-18 (ADR 0010): a person without a role may
 * book and an owner or collaborator of that barbershop gets 403, but nothing of another barbershop is reachable, and what
 * is missing answers with the body of every missing resource (UP-06, MT-03).
 */
final class ReservationCreationTenancyTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;

    private string $ana;

    /** Seed the barbershops and an account without any role. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        $this->ana = $this->insertUser('Ana Rojas');
        $this->actAs($this->ana);
    }

    /** A barbershop that does not exist answers exactly what a missing resource answers, and creates no membership. */
    public function test_an_unknown_barbershop_answers_the_body_of_a_missing_resource(): void
    {
        $unknown = '0192f0c4-0000-7000-8000-0000000000ff';

        $body = $this->postJson($this->reservationsUrl($unknown), $this->booking())->assertNotFound()->assertHeader('Content-Type', 'application/problem+json')->json();

        $this->assertSame($this->missingResource(), $this->withoutInstance($body));
        $this->assertSame(0, DB::table('memberships')->where('user_id', $this->ana)->count());
    }

    /** A service of another barbershop answers the same body, and creates no membership. */
    public function test_a_service_of_another_barbershop_answers_the_body_of_a_missing_resource(): void
    {
        $body = $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(serviceId: $this->corte))->assertNotFound()->json();

        $this->assertSame($this->missingResource(), $this->withoutInstance($body));
        $this->assertSame(0, DB::table('memberships')->where('user_id', $this->ana)->count());
        $this->assertSame(0, $this->countIn('reservations', $this->puraNavaja));
    }

    /** A collaborator of another barbershop answers the same body, and creates no membership. */
    public function test_a_collaborator_of_another_barbershop_answers_the_body_of_a_missing_resource(): void
    {
        $body = $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(collaboratorId: $this->mauricio))->assertNotFound()->json();

        $this->assertSame($this->missingResource(), $this->withoutInstance($body));
        $this->assertSame(0, DB::table('memberships')->where('user_id', $this->ana)->count());
    }

    /** An inactive service is as missing as one that does not exist (SV-04). */
    public function test_an_inactive_service_answers_the_body_of_a_missing_resource(): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        $inactive = $this->insertService($this->puraNavaja, 'Retirado', 30, 4000, false);

        $body = $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(serviceId: $inactive))->assertNotFound()->json();

        $this->assertSame($this->missingResource(), $this->withoutInstance($body));
    }

    /** The owner and a collaborator of the barbershop get 403 `prohibido`, even with a taken space or a body that is wrong (UP-07, RD-18). */
    public function test_the_staff_of_the_barbershop_is_forbidden_before_anything_is_validated(): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        $collaboratorUser = DB::table('collaborators')->where('id', $this->daniela)->value('user_id');

        foreach ([$this->owner, $collaboratorUser] as $staff) {
            $this->actAs($staff);

            $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())
                ->assertForbidden()
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('type', 'http://localhost/problemas/prohibido');
            $this->postJson($this->reservationsUrl($this->puraNavaja), [])->assertForbidden();
            $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(startsAt: '2020-01-01T10:00:00-06:00'))->assertForbidden();
        }

        $this->assertSame(0, $this->countIn('reservations', $this->puraNavaja));
        $this->assertSame(0, DB::table('memberships')->where('role', 'customer')->count());
    }

    /** The staff of one barbershop is a customer of the other: the role is per barbershop (UP-07). */
    public function test_the_owner_of_one_barbershop_books_in_the_other(): void
    {
        $this->actAs($this->owner);

        $this->postJson($this->reservationsUrl($this->fadeLab), $this->booking($this->corte, $this->mauricio))->assertCreated();

        $this->assertSame(
            [[$this->puraNavaja, 'owner'], [$this->fadeLab, 'customer']],
            DB::table('memberships')->where('user_id', $this->owner)->orderByDesc('role')->get(['barbershop_id', 'role'])->map(fn (object $row): array => [$row->barbershop_id, $row->role])->all(),
        );
        $this->assertSame(0, $this->countIn('reservations', $this->puraNavaja));
    }

    /** The reservation is stored in the barbershop of the URL and the other barbershop sees nothing of it. */
    public function test_the_reservation_belongs_only_to_the_barbershop_of_the_url(): void
    {
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated();

        $this->assertSame(1, $this->countIn('reservations', $this->puraNavaja));
        $this->assertSame(0, $this->countIn('reservations', $this->fadeLab));
        $this->assertSame(0, $this->countIn('reservation_status_history', $this->fadeLab));
    }

    /**
     * The problem document of a missing resource as the barbershop middleware gives it to a person without a role,
     * without the identifier of the request.
     *
     * @return array<string, mixed>
     */
    private function missingResource(): array
    {
        $body = $this->getJson('/api/v1/barberias/0192f0c4-0000-7000-8000-0000000000fe/administracion/servicios')->assertNotFound()->json();

        $this->assertSame(
            [
                'type' => 'http://localhost/problemas/no-encontrado',
                'title' => 'Recurso no encontrado',
                'status' => 404,
                'detail' => 'El recurso solicitado no existe.',
            ],
            $this->withoutInstance($body),
        );

        return $this->withoutInstance($body);
    }
}
