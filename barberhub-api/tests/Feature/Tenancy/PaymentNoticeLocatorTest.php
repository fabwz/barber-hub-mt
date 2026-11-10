<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Payments\Payments\Application\DTOs\PaymentLocation;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Payments\Payments\Infrastructure\Notices\DatabasePaymentLocator;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\TestCase;

/**
 * The only thing that reads payments across barbershops is the function that finds a payment from the reference of its provider
 * (docs/database.md, "Procesos sin sesión"). These tests prove what it is allowed to do: answer the two identifiers of the payment,
 * leave the barbershop of the connection as it found it, and keep the application role without privileges. The policies stay as they
 * were, which BarbershopPolicyCatalogTest checks on the catalog.
 */
final class PaymentNoticeLocatorTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;

    private string $paymentOfPuraNavaja;

    private string $paymentOfFadeLab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        $this->paymentOfPuraNavaja = $this->insertPayment($this->puraNavaja, $this->daniela, $this->degradado, 'onvo', 'pi_pura_navaja');
        $this->paymentOfFadeLab = $this->insertPayment($this->fadeLab, $this->mauricio, $this->corte, 'paypal', 'ORDER-FADE-LAB');
    }

    /** Each reference leads to its own payment and barbershop, whichever barbershop the connection was in; an unknown one or one of another provider leads nowhere. */
    public function test_it_finds_the_payment_and_barbershop_of_a_reference(): void
    {
        $locator = new DatabasePaymentLocator;

        foreach ([null, $this->puraNavaja, $this->fadeLab] as $scope) {
            $this->scopeQueriesTo($scope);

            $this->assertEquals(new PaymentLocation($this->puraNavaja, $this->paymentOfPuraNavaja), $locator->locate(PaymentProvider::Onvo, 'pi_pura_navaja'));
            $this->assertEquals(new PaymentLocation($this->fadeLab, $this->paymentOfFadeLab), $locator->locate(PaymentProvider::Paypal, 'ORDER-FADE-LAB'));
            $this->assertNull($locator->locate(PaymentProvider::Onvo, 'ORDER-FADE-LAB'));
            $this->assertNull($locator->locate(PaymentProvider::Onvo, 'unknown'));
        }
    }

    /** The function leaves the barbershop of the connection exactly as it found it, found or not. */
    public function test_it_leaves_the_barbershop_of_the_connection_as_it_found_it(): void
    {
        $locator = new DatabasePaymentLocator;

        foreach ([$this->puraNavaja, $this->fadeLab, ''] as $scope) {
            $this->scopeQueriesTo($scope);
            $locator->locate(PaymentProvider::Paypal, 'ORDER-FADE-LAB');
            $this->assertEquals($scope, $this->currentBarbershop());

            $locator->locate(PaymentProvider::Paypal, 'unknown');
            $this->assertEquals($scope, $this->currentBarbershop());
        }
    }

    /** The application role is still neither a superuser nor able to bypass the policies, and without a barbershop it still sees no payment. */
    public function test_the_application_role_keeps_no_privilege_and_sees_nothing_without_a_barbershop(): void
    {
        $role = DB::selectOne('select rolsuper, rolbypassrls from pg_roles where rolname = current_user');
        $this->assertNotNull($role);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);

        $this->scopeQueriesTo(null);
        $this->assertSame(0, DB::table('payments')->count());
    }

    /** The function is read-only, fixes its search_path and runs with the rights of its owner; nobody but the application role may execute it. */
    public function test_the_function_is_narrow(): void
    {
        $function = DB::connection('pgsql_admin')->selectOne(
            "select prosecdef, provolatile, proconfig, array_to_string(proacl, ',') as acl, pg_get_function_result(oid) as result
             from pg_proc where proname = 'locate_payment_of_notice'",
        );

        $this->assertNotNull($function);
        $this->assertTrue($function->prosecdef);
        $this->assertStringContainsString('search_path=pg_catalog, public', (string) $function->proconfig);
        $this->assertSame('TABLE(barbershop_id uuid, payment_id uuid)', $function->result);
        // A grant to PUBLIC is written with no role before the "=" ("=X/owner"); there is none.
        $this->assertDoesNotMatchRegularExpression('#(^|,)=X/#', (string) $function->acl);
        $this->assertStringContainsString(config('database.connections.pgsql.username').'=X/', (string) $function->acl);
    }

    /** The barbershop the connection is scoped to right now; empty when none. */
    private function currentBarbershop(): mixed
    {
        return DB::selectOne("select current_setting('app.barbershop_id', true) as value")->value ?? null;
    }

    /** Insert a pending payment, with its temporary reservation, in a barbershop and return its identifier. */
    private function insertPayment(string $barbershopId, string $collaboratorId, string $serviceId, string $provider, string $reference): string
    {
        [, $membership] = $this->insertCustomer($barbershopId, 'Cliente '.$reference);
        $reservation = $this->insertReservation($barbershopId, $collaboratorId, $serviceId, $membership, '2030-03-05 10:00:00-06:00', 'temporary', 30);
        $payment = (string) Str::uuid7();
        $this->scopeQueriesTo($barbershopId);
        DB::table('payments')->insert([
            'id' => $payment, 'barbershop_id' => $barbershopId, 'reservation_id' => $reservation, 'provider' => $provider,
            'method' => 'card', 'status' => 'pending', 'amount' => 1500, 'provider_payment_id' => $reference,
        ]);

        return $payment;
    }
}
