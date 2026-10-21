<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Src\Shared\Domain\Exceptions\AuthenticationFailedException;
use Src\Shared\Domain\Exceptions\BusinessRuleException;
use Src\Shared\Domain\Exceptions\HasProblemMembers;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\Exceptions\TooManyRequestsException;
use Tests\TestCase;

final class ProblemDetailsTest extends TestCase
{
    private const string CONTENT_TYPE = 'application/problem+json';

    private const string BASE = 'http://localhost/problemas/';

    /** Register throwaway routes that fail in each supported way. */
    protected function setUp(): void
    {
        parent::setUp();

        Route::prefix('api/v1/pruebas')->middleware('api')->group(function (): void {
            Route::get(
                'fallo',
                fn () => throw new RuntimeException('SQLSTATE[08006] host db.internal.example password=hunter2 at /var/www/secret.php'),
            );

            Route::get(
                'dominio',
                fn () => throw new InvalidValueException('shared.money_negative'),
            );

            Route::post(
                'validacion',
                fn (Request $request) => $request->validate(['precio' => 'required|integer']),
            );

            Route::post(
                'credenciales',
                fn () => throw new class('problems.credenciales-invalidas.detail') extends AuthenticationFailedException {},
            );

            Route::get('espera/{seconds}', fn (int $seconds) => throw new WaitViolation('pruebas.espera', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]));

            Route::get('miembros', fn () => throw new MembersViolation('problems.conflicto.detail'));

            Route::get('regla/{rule}', fn (string $rule) => throw new RuleViolation('problems.conflicto.detail', ['rule' => $rule]));
        });
    }

    /** An unknown route answers a complete problem document. */
    public function test_unknown_route_answers_a_not_found_problem(): void
    {
        $response = $this->getJson('/api/v1/desconocido');

        $response
            ->assertNotFound()
            ->assertHeader('Content-Type', self::CONTENT_TYPE)
            ->assertExactJson([
                'type' => self::BASE.'no-encontrado',
                'title' => 'Recurso no encontrado',
                'status' => 404,
                'detail' => 'El recurso solicitado no existe.',
                'instance' => 'urn:uuid:'.$response->headers->get('X-Request-Id'),
            ]);
    }

    /** A wrong verb answers a method-not-allowed problem. */
    public function test_wrong_verb_answers_a_method_not_allowed_problem(): void
    {
        $this->deleteJson('/api/v1')
            ->assertMethodNotAllowed()
            ->assertHeader('Content-Type', self::CONTENT_TYPE)
            ->assertJsonPath('type', self::BASE.'metodo-no-permitido')
            ->assertJsonPath('title', 'Método no permitido');
    }

    /** Validation failures list the errors of each field in Spanish. */
    public function test_validation_failure_lists_the_errors_per_field(): void
    {
        $this->postJson('/api/v1/pruebas/validacion', ['precio' => 'caro'])
            ->assertUnprocessable()
            ->assertHeader('Content-Type', self::CONTENT_TYPE)
            ->assertJsonPath('type', self::BASE.'datos-invalidos')
            ->assertJsonPath('detail', 'Los datos enviados no son válidos.')
            ->assertJsonPath('errores.precio.0', 'El campo precio debe ser un número entero.');
    }

    /** A domain error answers its translated message. */
    public function test_domain_error_answers_its_translated_message(): void
    {
        $this->getJson('/api/v1/pruebas/dominio')
            ->assertUnprocessable()
            ->assertJsonPath('type', self::BASE.'datos-invalidos')
            ->assertJsonPath('detail', 'El monto no puede ser negativo.');
    }

    /** A failed authentication answers the invalid credentials problem. */
    public function test_failed_authentication_answers_an_invalid_credentials_problem(): void
    {
        $this->postJson('/api/v1/pruebas/credenciales')
            ->assertUnauthorized()
            ->assertHeader('Content-Type', self::CONTENT_TYPE)
            ->assertJsonPath('type', self::BASE.'credenciales-invalidas')
            ->assertJsonPath('title', 'Credenciales inválidas')
            ->assertJsonPath('detail', 'El correo o la contraseña no son correctos.');
    }

    /** Each stable business type answers 409 with its own title. */
    #[DataProvider('businessTypes')]
    public function test_business_rule_answers_its_own_problem_type(string $rule, string $title): void
    {
        $this->getJson("/api/v1/pruebas/regla/{$rule}")
            ->assertConflict()
            ->assertHeader('Content-Type', self::CONTENT_TYPE)
            ->assertJsonPath('type', self::BASE.$rule)
            ->assertJsonPath('title', $title)
            ->assertJsonPath('status', 409);
    }

    /**
     * The five stable business types of RNF-17.
     *
     * @return array<string, array{string, string}>
     */
    public static function businessTypes(): array
    {
        return [
            'slot' => ['espacio-no-disponible', 'Espacio no disponible'],
            'balance' => ['saldo-insuficiente', 'Saldo insuficiente'],
            'deadline' => ['plazo-vencido', 'Plazo vencido'],
            'voucher' => ['bono-vencido', 'Bono vencido'],
            'offer' => ['oferta-vencida', 'Oferta vencida'],
        ];
    }

    /** A rule named «prohibido» answers the forbidden problem with 403. */
    public function test_forbidden_rule_answers_a_forbidden_problem(): void
    {
        $this->getJson('/api/v1/pruebas/regla/prohibido')
            ->assertForbidden()
            ->assertHeader('Content-Type', self::CONTENT_TYPE)
            ->assertJsonPath('type', self::BASE.'prohibido')
            ->assertJsonPath('title', 'Acción no permitida');
    }

    /** A rule that is not in the catalog falls back to the generic conflict. */
    public function test_unknown_business_rule_answers_a_conflict(): void
    {
        $this->getJson('/api/v1/pruebas/regla/regla-desconocida')
            ->assertConflict()
            ->assertJsonPath('type', self::BASE.'conflicto');
    }

    /** A rule that asks to wait sends Retry-After in seconds and a message with the minutes, singular or plural. */
    #[DataProvider('waits')]
    public function test_retryable_rule_sends_retry_after_and_pluralises_the_minutes(int $seconds, string $detail): void
    {
        app('translator')->addLines(['pruebas.espera' => 'Espere :minutes minuto.|Espere :minutes minutos.'], 'es');

        $this->getJson("/api/v1/pruebas/espera/{$seconds}")
            ->assertStatus(429)
            ->assertHeader('Retry-After', (string) $seconds)
            ->assertHeader('Content-Type', self::CONTENT_TYPE)
            ->assertJsonPath('type', self::BASE.'demasiadas-solicitudes')
            ->assertJsonPath('detail', $detail);
    }

    /**
     * Seconds to wait and the message each one produces.
     *
     * @return array<string, array{int, string}>
     */
    public static function waits(): array
    {
        return [
            'less than a minute' => [20, 'Espere 1 minuto.'],
            'exactly one minute' => [60, 'Espere 1 minuto.'],
            'a few seconds over one minute' => [61, 'Espere 2 minutos.'],
            'fourteen minutes' => [840, 'Espere 14 minutos.'],
        ];
    }

    /** An error that brings its own members adds them to the document, and cannot replace the standard ones. */
    public function test_error_with_members_adds_them_to_the_document(): void
    {
        $response = $this->getJson('/api/v1/pruebas/miembros')->assertConflict();

        $response->assertHeader('Content-Type', self::CONTENT_TYPE)
            ->assertJsonPath('type', self::BASE.'espacio-no-disponible')
            ->assertJsonPath('status', 409)
            ->assertJsonPath('instance', 'urn:uuid:'.$response->headers->get('X-Request-Id'))
            ->assertJsonPath('alternativas', [['inicio' => '2030-03-05T10:00:00-06:00']]);
        $this->assertSame(['type', 'title', 'status', 'detail', 'instance', 'alternativas'], array_keys($response->json()));
    }

    /** An unexpected failure never leaks technical details, not even in debug mode (SE-03). */
    public function test_unexpected_failure_hides_technical_details(): void
    {
        config(['app.debug' => true]);

        $response = $this->getJson('/api/v1/pruebas/fallo');

        $response
            ->assertInternalServerError()
            ->assertHeader('Content-Type', self::CONTENT_TYPE)
            ->assertJsonPath('type', self::BASE.'error-interno')
            ->assertJsonPath('detail', 'Ocurrió un error inesperado. Intente de nuevo más tarde.');

        $this->assertSame(['type', 'title', 'status', 'detail', 'instance'], array_keys($response->json()));

        $exposed = (string) $response->getContent().$response->headers;

        foreach (['SQLSTATE', 'db.internal.example', 'password', 'hunter2', 'secret.php', 'RuntimeException', 'trace'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $exposed);
        }
    }
}

/** Rate limit whose wait comes from the request, to exercise Retry-After and the plural message. */
final class WaitViolation extends TooManyRequestsException
{
    public function retryAfterSeconds(): int
    {
        return (int) $this->parameters()['seconds'];
    }
}

/** Business rule whose name comes from the request, to exercise the renderer. */
final class RuleViolation extends BusinessRuleException
{
    public function rule(): string
    {
        return (string) $this->parameters()['rule'];
    }
}

/** Business rule with extra members, one of them trying to replace a standard member. */
final class MembersViolation extends BusinessRuleException implements HasProblemMembers
{
    public function rule(): string
    {
        return 'espacio-no-disponible';
    }

    public function problemMembers(): array
    {
        return ['status' => 200, 'alternativas' => [['inicio' => '2030-03-05T10:00:00-06:00']]];
    }
}
