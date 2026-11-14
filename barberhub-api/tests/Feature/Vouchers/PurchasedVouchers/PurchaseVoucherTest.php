<?php

declare(strict_types=1);

namespace Tests\Feature\Vouchers\PurchasedVouchers;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Src\Payments\Payments\Application\Contracts\PaymentGateway;
use Src\Payments\Payments\Application\DTOs\ChargeRequest;
use Src\Payments\Payments\Application\DTOs\ChargeResult;
use Src\Payments\Payments\Application\DTOs\GatewayStatus;
use Src\Payments\Payments\Application\DTOs\PaymentInput;
use Src\Payments\Payments\Application\DTOs\RefundResult;
use Src\Payments\Payments\Domain\ValueObjects\PaymentId;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Shared\Domain\ValueObjects\Money;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Payments\PaymentNoticeScenario;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Vouchers\SeedsVouchers;
use Tests\TestCase;

/**
 * Buying a voucher and activating it with the verified notice of its payment (RF-27, BO-02, BO-07, PA-03, PA-05, MT-01, UP-07, UP-09).
 * The gateway is simulated and no request leaves the process (PA-09). "Bono 5 cortes" is 5 sessions for ₡30.000 that last 90 days.
 */
final class PurchaseVoucherTest extends TestCase
{
    use PaymentNoticeScenario;
    use RefreshDatabaseAsOwner;
    use SeedsVouchers;

    private const string SECRET = 'onvo-test-signing-secret';

    private const string NOTICES = '/api/v1/pagos/avisos/onvo';

    private string $voucher;

    private string $fadeLabVoucher;

    /** Pura Navaja sells "Bono 5 cortes" and Fade Lab another one; ONVO Pay is simulated and its notices are signed with a known secret. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScenario(PaymentProvider::Onvo);
        config(['payments.onvo.webhook_secret' => self::SECRET]);
        $this->voucher = $this->insertVoucherDefinition($this->puraNavaja, [$this->degradado]);
        $this->fadeLabVoucher = $this->insertVoucherDefinition($this->fadeLab, [$this->corte], 'Bono de Fade Lab', 3, 12000, 30);
        $this->scopeQueriesTo($this->puraNavaja);
    }

    /** Without the notice of a confirmed payment there is no voucher: not even an approval that comes with the charge activates it (BO-02, PA-03). */
    public function test_without_the_confirmed_notice_there_is_no_voucher(): void
    {
        $this->gateway->approves();
        $this->actAs($this->ana);

        $payment = $this->buy()->assertCreated()->assertJsonPath('data.estado', 'pendiente')->assertJsonPath('data.reserva', null)->assertJsonPath('data.monto', 30000)->json('data');

        $voucher = $this->theVoucher();
        $this->assertSame($voucher->id, $payment['bono_comprado']);
        $this->assertSame('pending', $voucher->status);
        $this->assertSame(0, $voucher->remaining_sessions);
        $this->assertNull($voucher->activated_at);
        $this->assertNull($voucher->expires_at);
        $this->assertSame(0, $this->movementsOf($voucher->id));
        $this->assertSame(0, $this->recordedNotices());
        $row = $this->theOnlyPayment();
        $this->assertSame('pending', $row->status);
        $this->assertSame($voucher->id, $row->purchased_voucher_id);
        $this->assertNull($row->reservation_id);
    }

    /** The same event of payment received twice, and another event of the same payment, grant the sessions once (PA-05, RF-27). */
    public function test_the_same_event_twice_does_not_duplicate_the_sessions(): void
    {
        $this->gateway->approves();
        $this->actAs($this->ana);
        $this->buy()->assertCreated();
        $reference = $this->reference();

        $this->notice($this->event('evt_1', 'payment_intent.succeeded', $reference))->assertOk();
        $this->notice($this->event('evt_1', 'payment_intent.succeeded', $reference))->assertOk();
        $this->notice($this->event('evt_2', 'payment_intent.succeeded', $reference))->assertOk();

        $voucher = $this->theVoucher();
        $this->assertSame('active', $voucher->status);
        $this->assertSame(5, $voucher->remaining_sessions);
        $this->assertSame(1, $this->movementsOf($voucher->id));
        $this->assertSame(5, (int) DB::table('voucher_movements')->where('purchased_voucher_id', $voucher->id)->sum('sessions_delta'));
        $this->assertSame('approved', $this->theOnlyPayment()->status);
        $this->assertSame(2, $this->recordedNotices());
        $this->assertSame([$reference], array_values(array_unique($this->gateway->statusChecks)));
    }

    /** After the payment the balance is 5 sessions and the voucher expires 90 days after the activation, not after the purchase (RF-27, BO-02). */
    public function test_after_the_payment_the_balance_is_five_and_it_expires_in_90_days_from_the_activation(): void
    {
        $this->gateway->leavesPending();
        $this->actAs($this->ana);
        $this->buy()->assertCreated()->assertJsonPath('data.estado', 'pendiente');
        $activation = new DateTimeImmutable('2030-03-03 10:30:00', new DateTimeZone('America/Costa_Rica'));
        $this->clock->moveTo($activation);
        $this->gateway->approves();

        $this->notice($this->event('evt_1', 'payment_intent.succeeded', $this->reference()))->assertOk();

        $voucher = $this->theVoucher();
        $this->assertSame('active', $voucher->status);
        $this->assertSame(5, $voucher->remaining_sessions);
        $this->assertSame($activation->getTimestamp(), (new DateTimeImmutable((string) $voucher->activated_at))->getTimestamp());
        $this->assertSame($activation->modify('+90 days')->getTimestamp(), (new DateTimeImmutable((string) $voucher->expires_at))->getTimestamp());
        $purchase = DB::table('voucher_movements')->where('purchased_voucher_id', $voucher->id)->first();
        $this->assertSame(['purchase', 5, null], [$purchase?->type, $purchase?->sessions_delta, $purchase?->reservation_id]);
    }

    /** The customer reads the state of their payment, which asks the provider and activates the voucher too; another customer reads nothing (UP-06). */
    public function test_the_customer_reads_the_payment_of_their_voucher_and_nobody_else_does(): void
    {
        $this->gateway->leavesPending();
        $this->actAs($this->ana);
        $payment = $this->buy()->assertCreated()->json('data.id');
        $this->gateway->approves();

        $this->getJson($this->paymentUrl($this->puraNavaja, $payment))->assertOk()->assertJsonPath('data.estado', 'aprobado')->assertJsonPath('data.bono_comprado', $this->theVoucher()->id);

        $this->assertSame('active', $this->theVoucher()->status);
        [$luis] = $this->insertCustomer($this->puraNavaja, 'Luis Mora');
        $this->actAs($luis);
        $this->getJson($this->paymentUrl($this->puraNavaja, $payment))->assertNotFound();
        $this->actAs($this->owner);
        $this->getJson($this->paymentUrl($this->puraNavaja, $payment))->assertOk()->assertJsonPath('data.estado', 'aprobado');
    }

    /** A voucher bought in one barbershop does not exist in another: not through its URL, not through its payment, not in the database (MT-01, BO-02). */
    public function test_a_voucher_of_one_barbershop_cannot_be_used_in_another(): void
    {
        $this->actAs($this->ana);

        $this->buy($this->voucher, $this->fadeLab)->assertNotFound()->assertHeader('Content-Type', 'application/problem+json');
        $this->buy($this->fadeLabVoucher, $this->puraNavaja)->assertNotFound();
        $this->assertSame(0, $this->countIn('purchased_vouchers', $this->puraNavaja) + $this->countIn('purchased_vouchers', $this->fadeLab));
        $this->assertSame(0, DB::table('memberships')->where('user_id', $this->ana)->count());

        $this->gateway->approves();
        $payment = $this->buy()->assertCreated()->json('data.id');
        $this->notice($this->event('evt_1', 'payment_intent.succeeded', $this->reference()))->assertOk();

        $this->actAs($this->ana);
        $this->getJson($this->paymentUrl($this->fadeLab, $payment))->assertNotFound();
        $this->assertSame(0, $this->countIn('purchased_vouchers', $this->fadeLab));
        $this->assertSame(1, $this->countIn('purchased_vouchers', $this->puraNavaja));
    }

    /** The database refuses a payment of one barbershop that points at the voucher of another (composite key, MT-04). */
    public function test_a_payment_cannot_point_at_the_voucher_of_another_barbershop(): void
    {
        $this->actAs($this->ana);
        $this->buy()->assertCreated();
        $voucher = $this->theVoucher()->id;
        $this->scopeQueriesTo($this->fadeLab);

        $this->expectException(QueryException::class);

        DB::table('payments')->insert([
            'id' => (string) Str::uuid7(), 'barbershop_id' => $this->fadeLab, 'purchased_voucher_id' => $voucher, 'provider' => 'onvo', 'method' => 'card',
            'status' => 'pending', 'amount' => 30000,
        ]);
    }

    /** A person without a role in the barbershop is its customer after the first purchase, once, and the retry does not duplicate anything (UP-09). */
    public function test_a_person_without_a_role_is_a_customer_after_the_first_purchase(): void
    {
        $stranger = $this->insertUser('Dani Mora');
        $this->gateway->leavesPending();
        $this->actAs($stranger);
        $this->assertSame(0, DB::table('memberships')->where('user_id', $stranger)->count());

        $first = $this->buy()->assertCreated()->json('data.id');
        $again = $this->buy()->assertOk()->json('data.id');

        $this->assertSame($first, $again);
        $roles = DB::table('memberships')->where('user_id', $stranger)->get();
        $role = $roles->sole();
        $this->assertSame(['customer', $this->puraNavaja], [$role->role, $role->barbershop_id]);
        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame(1, DB::table('purchased_vouchers')->count());
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(1, count($this->gateway->charges));
        $this->assertSame($role->id, $this->theVoucher()->customer_membership_id);
    }

    /** A person who is already a customer keeps their role: it is not duplicated. */
    public function test_a_customer_keeps_their_one_role(): void
    {
        [$luis, $membership] = $this->insertCustomer($this->puraNavaja, 'Luis Mora');
        $this->gateway->leavesPending();
        $this->actAs($luis);

        $this->buy()->assertCreated();

        $this->assertSame(1, DB::table('memberships')->where('user_id', $luis)->count());
        $this->assertSame($membership, $this->theVoucher()->customer_membership_id);
    }

    /** If the purchase fails and is rolled back, the customer role goes with it: no role, no voucher, no payment (UP-09). */
    public function test_a_failed_purchase_leaves_no_membership(): void
    {
        $stranger = $this->insertUser('Dani Mora');
        $this->app->instance('tests.gateway.onvo', $this->explodingGateway());
        $this->actAs($stranger);

        $this->buy()->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR);

        $this->assertSame(0, DB::table('memberships')->where('user_id', $stranger)->count());
        $this->assertSame(0, $this->countIn('purchased_vouchers', $this->puraNavaja));
        $this->assertSame(0, $this->countIn('payments', $this->puraNavaja));
    }

    /** The owner and a collaborator of the barbershop get 403 `prohibido` and nothing is created (UP-07, RD-18). */
    public function test_the_owner_and_a_collaborator_cannot_buy_a_voucher_of_their_barbershop(): void
    {
        $collaborator = $this->insertUser('Beto Mora');
        $this->grantRole($collaborator, $this->puraNavaja, 'collaborator');

        foreach ([$this->owner, $collaborator] as $staff) {
            $this->actAs($staff);

            $this->buy()->assertForbidden()->assertJsonPath('type', 'http://localhost/problemas/prohibido');
        }

        $this->assertSame(0, $this->countIn('purchased_vouchers', $this->puraNavaja));
        $this->assertSame(0, $this->countIn('payments', $this->puraNavaja));
        $this->assertSame([], $this->gateway->charges);
        $this->assertSame(0, DB::table('memberships')->where('role', 'customer')->count());
    }

    /** Without a session the answer is 401 and nothing is created. */
    public function test_a_purchase_requires_a_session(): void
    {
        $this->buy()->assertUnauthorized();

        $this->assertSame(0, $this->countIn('purchased_vouchers', $this->puraNavaja));
    }

    /** A refused payment leaves the voucher unpaid with no sessions; trying again reuses it, and the notice of the new payment activates it. */
    public function test_a_rejected_payment_does_not_activate_and_a_retry_reuses_the_voucher(): void
    {
        $this->gateway->rejects('Card declined');
        $this->actAs($this->ana);

        $this->buy()->assertCreated()->assertJsonPath('data.estado', 'rechazado');

        $voucher = $this->theVoucher();
        $this->assertSame(['pending', 0, 0], [$voucher->status, $voucher->remaining_sessions, $this->movementsOf($voucher->id)]);

        $this->gateway->approves();
        $this->buy()->assertCreated()->assertJsonPath('data.estado', 'pendiente');
        $this->notice($this->event('evt_1', 'payment_intent.succeeded', $this->reference()))->assertOk();

        $this->assertSame(1, DB::table('purchased_vouchers')->count());
        $this->assertSame(2, DB::table('payments')->count());
        $this->assertSame(['active', 5], [$this->theVoucher()->status, $this->theVoucher()->remaining_sessions]);
    }

    /** The notice of a failed payment does not activate the voucher: it stays unpaid, without sessions and without a record (PA-03, BO-02). */
    public function test_the_notice_of_a_failed_payment_does_not_activate(): void
    {
        $this->gateway->leavesPending();
        $this->actAs($this->ana);
        $this->buy()->assertCreated();
        $this->gateway->rejects('Insufficient funds');

        $this->notice($this->event('evt_1', 'payment_intent.failed', $this->reference()))->assertOk();

        $voucher = $this->theVoucher();
        $this->assertSame(['pending', 0, null, 0], [$voucher->status, $voucher->remaining_sessions, $voucher->expires_at, $this->movementsOf($voucher->id)]);
        $this->assertSame('rejected', $this->theOnlyPayment()->status);
    }

    /** A voucher withdrawn from sale, or one that does not exist, answers 404 and creates nothing (RF-26). */
    public function test_a_withdrawn_or_missing_voucher_cannot_be_bought(): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('voucher_definitions')->where('id', $this->voucher)->update(['is_active' => false]);
        $this->actAs($this->ana);

        $this->buy()->assertNotFound();
        $this->buy((string) Str::uuid7())->assertNotFound();

        $this->assertSame(0, DB::table('purchased_vouchers')->count());
        $this->assertSame(0, DB::table('memberships')->where('user_id', $this->ana)->count());
    }

    /** The amount is the price of the voucher, in full: neither the body nor the deposit policy of the barbershop change it (PA-06). */
    public function test_the_amount_is_the_price_of_the_voucher_and_never_comes_from_the_body(): void
    {
        $this->gateway->leavesPending();
        $this->actAs($this->ana);

        $this->buy(body: ['monto' => 1, 'amount' => 1])->assertCreated()->assertJsonPath('data.monto', 30000);

        $this->assertSame(30000, $this->gateway->charges[0]->amount->colones);
        $this->assertNull($this->gateway->charges[0]->reservationId);
        $this->assertSame($this->theVoucher()->id, $this->gateway->charges[0]->voucherId?->value);
        $this->assertSame(30000, $this->theOnlyPayment()->amount);
    }

    /** The voucher keeps what was on sale when it was bought: editing the price afterwards does not change it (BO-01). */
    public function test_the_voucher_keeps_the_terms_it_was_bought_with(): void
    {
        $this->gateway->leavesPending();
        $this->actAs($this->ana);
        $this->buy()->assertCreated();
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('voucher_definitions')->where('id', $this->voucher)->update(['price' => 99000]);

        $voucher = $this->theVoucher();

        $this->assertSame(['Bono 5 cortes', 30000, 5, 90], [$voucher->name, $voucher->price, $voucher->sessions_count, $voucher->validity_days]);
    }

    /** A voucher is neither refundable nor transferable (BO-07): the owner cannot give back its payment, even approved. */
    public function test_the_payment_of_a_voucher_is_not_refundable(): void
    {
        $this->gateway->approves();
        $this->actAs($this->ana);
        $payment = $this->buy()->assertCreated()->json('data.id');
        $this->notice($this->event('evt_1', 'payment_intent.succeeded', $this->reference()))->assertOk();
        $this->actAs($this->owner);

        $this->postJson($this->paymentUrl($this->puraNavaja, $payment).'/reembolsos')->assertStatus(Response::HTTP_CONFLICT)->assertJsonPath('type', 'http://localhost/problemas/conflicto');

        $this->assertSame([], $this->gateway->refunds);
        $this->assertSame('approved', $this->theOnlyPayment()->status);
        $this->assertSame('active', $this->theVoucher()->status);
    }

    /**
     * Buy the voucher with the card, as the person of the session.
     *
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function buy(?string $voucher = null, ?string $barbershop = null, array $body = [])
    {
        return $this->postJson('/api/v1/barberias/'.($barbershop ?? $this->puraNavaja).'/bonos/'.($voucher ?? $this->voucher).'/compras', ['metodo' => 'tarjeta', 'token' => 'pm_test_1', ...$body]);
    }

    private function paymentUrl(string $barbershop, string $payment): string
    {
        return "/api/v1/barberias/{$barbershop}/pagos/{$payment}";
    }

    /** The only voucher of Pura Navaja. */
    private function theVoucher(): stdClass
    {
        $this->scopeQueriesTo($this->puraNavaja);

        return DB::table('purchased_vouchers')->sole();
    }

    /** The last payment of Pura Navaja. */
    private function theOnlyPayment(): stdClass
    {
        $this->scopeQueriesTo($this->puraNavaja);

        return DB::table('payments')->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();
    }

    /** The identifier the provider gave to the last payment. */
    private function reference(): string
    {
        return (string) $this->theOnlyPayment()->provider_payment_id;
    }

    /** Entries of the record of a voucher. */
    private function movementsOf(string $voucher): int
    {
        $this->scopeQueriesTo($this->puraNavaja);

        return DB::table('voucher_movements')->where('purchased_voucher_id', $voucher)->count();
    }

    /** The JSON of an event of ONVO Pay for a payment intent. */
    private function event(string $id, string $type, string $intent): string
    {
        return (string) json_encode([
            'id' => $id, 'object' => 'event', 'createdAt' => '2030-03-01T14:00:00Z', 'mode' => 'test', 'accountId' => 'acc_1', 'type' => $type,
            'data' => ['object' => ['object' => 'payment_intent', 'id' => $intent, 'status' => 'succeeded']],
        ]);
    }

    /**
     * Send a notice as ONVO Pay does: raw JSON with a signature made at the current instant of the test.
     *
     * @return TestResponse<Response>
     */
    private function notice(string $body)
    {
        $timestamp = $this->clock->now()->getTimestamp();
        $signature = "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", self::SECRET);

        return $this->call('POST', self::NOTICES, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_ONVO_SIGNATURE' => $signature], $body);
    }

    /** A gateway that breaks in a way nobody expected, after the purchase was registered. */
    private function explodingGateway(): PaymentGateway
    {
        return new class implements PaymentGateway
        {
            public function provider(): PaymentProvider
            {
                return PaymentProvider::Onvo;
            }

            public function supports(PaymentMethod $method): bool
            {
                return true;
            }

            public function requiredInput(PaymentMethod $method): array
            {
                return [PaymentInput::CardToken];
            }

            public function charge(ChargeRequest $request): ChargeResult
            {
                throw new RuntimeException('The gateway broke in an unexpected way.');
            }

            public function status(string $providerPaymentId, PaymentId $paymentId): GatewayStatus
            {
                throw new RuntimeException('Not used.');
            }

            public function refund(string $providerPaymentId, Money $amount, PaymentId $paymentId): RefundResult
            {
                throw new RuntimeException('Not used.');
            }
        };
    }
}
