<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Src\Catalog\Barbershops\Application\DTOs\UpdateBarbershopFeaturesCommand;
use Src\Catalog\Barbershops\Application\UseCases\UpdateBarbershopFeatures;
use Src\Identity\Memberships\Infrastructure\Persistence\Models\MembershipModel;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Src\Shared\Domain\Contracts\AuditLog;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\AuditAction;
use Src\Shared\Domain\ValueObjects\AuditEntry;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\FixedBarbershopContext;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;

/**
 * The sensitive changes of the configuration leave an entry in the technical record, in the same transaction as the change, with
 * who, when, the previous and the new value, and without passwords, credentials or contact details; the record has no way to be
 * read through the API and each barbershop has its own (SE-04, MT-04).
 */
final class TechnicalRecordTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;

    private string $puraNavaja;

    private string $fadeLab;

    private UserModel $owner;

    /** Two barbershops, each with its owner. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->puraNavaja = $this->createBarbershop('pura-navaja');
        $this->fadeLab = $this->createBarbershop('fade-lab');
        $this->owner = $this->createOwner('ana@barberhub.test', $this->puraNavaja);
        $this->createOwner('luis@barberhub.test', $this->fadeLab);
    }

    /** Changing the policy leaves an entry for the policy and another for the gateway, each with the author, the date and both values (RF-08, RF-23). */
    public function test_a_change_of_policy_and_gateway_leaves_entries_with_author_date_and_both_values(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->patchJson($this->policyUrl(), ['modalidad' => 'deposito', 'porcentaje_deposito' => 30, 'plazo_cancelacion_horas' => 4, 'pasarela' => 'onvo'])->assertOk();

        $entries = $this->entries($this->puraNavaja);
        $this->assertSame(['barbershop.payment_policy.updated', 'barbershop.payment_gateway.updated'], array_column($entries, 'action'));
        $this->assertSame([$this->owner->id, $this->owner->id], array_column($entries, 'actor_user_id'));
        $this->assertSame(['barbershop', 'barbershop'], array_column($entries, 'subject_type'));
        $this->assertSame([$this->puraNavaja, $this->puraNavaja], array_column($entries, 'subject_id'));
        $this->assertEquals([
            'mode' => ['previous' => 'none', 'new' => 'deposit'],
            'deposit_percentage' => ['previous' => null, 'new' => 30],
            'cancellation_deadline_hours' => ['previous' => 24, 'new' => 4],
        ], json_decode($entries[0]['changes'], true));
        $this->assertEquals(['gateway' => ['previous' => null, 'new' => 'onvo']], json_decode($entries[1]['changes'], true));

        foreach ($entries as $entry) {
            $this->assertLessThan(60, abs(time() - (new DateTimeImmutable($entry['created_at']))->getTimestamp()));
        }
    }

    /** Switching a function leaves an entry; sending the same value again, or nothing, leaves none (RF-09). */
    public function test_a_change_of_functions_leaves_an_entry_and_a_repeat_leaves_none(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->patchJson($this->featuresUrl(), ['bonos_activos' => true])->assertOk();
        $this->patchJson($this->featuresUrl(), ['bonos_activos' => true])->assertOk();
        $this->patchJson($this->featuresUrl(), [])->assertOk();

        $entries = $this->entries($this->puraNavaja);
        $this->assertCount(1, $entries);
        $this->assertSame('barbershop.features.updated', $entries[0]['action']);
        $this->assertEquals(['vouchers_enabled' => ['previous' => false, 'new' => true]], json_decode($entries[0]['changes'], true));
    }

    /** A refused change, or one that is not allowed to the person, leaves nothing in the record. */
    public function test_a_refused_change_leaves_no_entry(): void
    {
        Sanctum::actingAs($this->owner, []);
        $this->patchJson($this->policyUrl(), ['modalidad' => 'deposito', 'porcentaje_deposito' => 5, 'pasarela' => 'onvo'])->assertUnprocessable();

        $this->assertSame([], $this->entries($this->puraNavaja));
    }

    /** If the change is rolled back, its entry goes with it: there is no record of what did not happen. */
    public function test_a_rolled_back_change_leaves_no_entry(): void
    {
        $this->useBarbershop($this->puraNavaja);

        try {
            DB::transaction(function (): void {
                $this->app->make(UpdateBarbershopFeatures::class)->execute(new UpdateBarbershopFeaturesCommand(true, true), $this->owner->id, new DateTimeImmutable);
                $this->assertCount(1, $this->entries($this->puraNavaja), 'The entry is written together with the change.');

                throw new RuntimeException('The request failed after the change.');
            });
        } catch (RuntimeException) {
            // The savepoint of the test is the transaction that was rolled back.
        }

        $this->assertSame([], $this->entries($this->puraNavaja));
        $this->assertSame([false, false], $this->storedFeatures());
    }

    /** If the entry cannot be written, the change is not kept either: they are one transaction. */
    public function test_an_entry_that_fails_undoes_the_change(): void
    {
        $this->useBarbershop($this->puraNavaja);
        $this->app->bind(AuditLog::class, fn (): AuditLog => new class implements AuditLog
        {
            public function record(AuditEntry $entry): void
            {
                throw new RuntimeException('The record is not available.');
            }
        });

        try {
            $this->app->make(UpdateBarbershopFeatures::class)->execute(new UpdateBarbershopFeaturesCommand(true, true), $this->owner->id, new DateTimeImmutable);
            $this->fail('The change was kept without its entry.');
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame([false, false], $this->storedFeatures());
        $this->assertSame([], $this->entries($this->puraNavaja));
    }

    /** A credential, a password and the contact details never reach the record: a credential shows only that it changed. */
    public function test_the_record_holds_no_password_credential_or_contact(): void
    {
        $this->useBarbershop($this->puraNavaja);
        $entry = AuditEntry::of(
            new UserId($this->owner->id),
            AuditAction::PaymentGatewayUpdated,
            'barbershop',
            $this->puraNavaja,
            ['gateway' => 'onvo', 'credentials' => 'onvo_test_secret_vieja', 'password' => 'Vieja#2030', 'contact_email' => 'ana@barberhub.test', 'phone' => '2460-0000'],
            ['gateway' => 'onvo', 'credentials' => 'onvo_test_secret_nueva', 'password' => 'Nueva#2030', 'contact_email' => 'luis@barberhub.test', 'phone' => '2460-1111'],
            new DateTimeImmutable,
        );
        $this->assertNotNull($entry);
        $this->app->make(AuditLog::class)->record($entry);

        $entries = $this->entries($this->puraNavaja);
        $this->assertCount(1, $entries);
        $this->assertSame(['credentials' => ['changed' => true]], json_decode($entries[0]['changes'], true));
        $this->assertNothingSensitiveIn($entries);
    }

    /** Whatever the owner changes through the API, no entry carries the password, the account email or the phone of the barbershop. */
    public function test_the_entries_of_the_changes_carry_no_contact_data(): void
    {
        Sanctum::actingAs($this->owner, []);
        $this->patchJson($this->policyUrl(), ['modalidad' => 'total', 'pasarela' => 'paypal'])->assertOk();
        $this->patchJson($this->featuresUrl(), ['bonos_activos' => true, 'lista_espera_activa' => true])->assertOk();

        $entries = $this->entries($this->puraNavaja);
        $this->assertNotEmpty($entries);
        $this->assertNothingSensitiveIn($entries);
    }

    /** The record has no route: nothing in the API reads it, and the paths where it could be found do not exist. */
    public function test_no_route_reads_the_record(): void
    {
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            $this->assertDoesNotMatchRegularExpression('/audit|registro-tecnico|bitacora/i', $route->uri(), "The route {$route->uri()} looks like a read of the record.");
            $this->assertDoesNotMatchRegularExpression('/Audit/', $route->getActionName(), "The route {$route->uri()} is served by something that names the record.");
        }

        Sanctum::actingAs($this->owner, []);
        foreach (['auditoria', 'registro', 'registro-tecnico', 'bitacora'] as $path) {
            $this->getJson("/api/v1/barberias/{$this->puraNavaja}/administracion/{$path}")->assertNotFound();
        }
    }

    /** Each barbershop has its own record: a change in one is not visible from the other, and a write on behalf of the other is refused (MT-04). */
    public function test_the_record_is_isolated_by_barbershop(): void
    {
        Sanctum::actingAs($this->owner, []);
        $this->patchJson($this->featuresUrl(), ['bonos_activos' => true])->assertOk();

        $this->assertCount(1, $this->entries($this->puraNavaja));
        $this->assertSame([], $this->entries($this->fadeLab));

        $this->scopeQueriesTo($this->puraNavaja);
        try {
            DB::table('audit_log')->insert([
                'id' => (string) Str::uuid7(),
                'barbershop_id' => $this->fadeLab,
                'actor_user_id' => $this->owner->id,
                'action' => 'barbershop.features.updated',
                'subject_type' => 'barbershop',
                'subject_id' => $this->fadeLab,
                'changes' => '{}',
            ]);
            $this->fail('An entry for another barbershop was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', (string) $exception->getCode(), $exception->getMessage());
        }
    }

    /**
     * Fail when the entries mention a password, a secret, a token or the contact data the test set up.
     *
     * @param  list<array<string, mixed>>  $entries
     */
    private function assertNothingSensitiveIn(array $entries): void
    {
        $text = json_encode($entries, JSON_THROW_ON_ERROR);

        foreach (['Vieja', 'Nueva', 'secret', 'token', 'password', 'hash', 'barberhub.test', '2460-'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text);
        }
    }

    /** Make the use cases and the record work for a barbershop, as the middleware does for a request. */
    private function useBarbershop(string $barbershopId): void
    {
        $this->scopeQueriesTo($barbershopId);
        $this->app->bind(BarbershopContext::class, fn (): BarbershopContext => new FixedBarbershopContext(new BarbershopId($barbershopId)));
    }

    /**
     * The entries of the record of a barbershop, in order, read as that barbershop.
     *
     * @return list<array<string, mixed>>
     */
    private function entries(string $barbershopId): array
    {
        $this->scopeQueriesTo($barbershopId);

        return array_values(DB::table('audit_log')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all());
    }

    /**
     * The switches of Pura Navaja as they are stored.
     *
     * @return array{bool, bool}
     */
    private function storedFeatures(): array
    {
        $this->scopeQueriesTo($this->puraNavaja);
        $row = DB::table('barbershops')->where('id', $this->puraNavaja)->first();
        $this->assertNotNull($row);

        return [(bool) $row->vouchers_enabled, (bool) $row->waitlist_enabled];
    }

    private function policyUrl(): string
    {
        return "/api/v1/barberias/{$this->puraNavaja}/administracion/cobro";
    }

    private function featuresUrl(): string
    {
        return "/api/v1/barberias/{$this->puraNavaja}/administracion/funciones";
    }

    /** Insert a barbershop straight into the table and return its identifier. */
    private function createBarbershop(string $link): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => $link, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);

        return $id;
    }

    /** Create the owner of a barbershop. */
    private function createOwner(string $email, string $barbershopId): UserModel
    {
        $user = UserModel::query()->create(['name' => $email, 'email' => $email, 'password' => 'hash']);
        MembershipModel::query()->create(['user_id' => $user->id, 'barbershop_id' => $barbershopId, 'role' => 'owner', 'is_active' => true]);

        return $user;
    }
}
