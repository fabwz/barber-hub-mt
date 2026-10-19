<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Shared\Infrastructure\Http\Problem\ProblemType;

final class ProblemTypeTest extends TestCase
{
    /** Each status resolves to a type that answers with it; a 401 of the framework means no session. */
    public function test_every_status_resolves_to_a_type_with_that_status(): void
    {
        foreach (ProblemType::cases() as $type) {
            $this->assertSame($type->status(), ProblemType::fromStatus($type->status())->status());
        }

        $this->assertSame(ProblemType::Unauthenticated, ProblemType::fromStatus(401));
    }

    /** The generic conflict stays first among the 409 types so that the status resolves to it. */
    public function test_conflict_status_resolves_to_the_generic_conflict(): void
    {
        $this->assertSame(ProblemType::Conflict, ProblemType::fromStatus(409));
    }

    /** The five stable business types of RNF-17 exist and answer 409. */
    public function test_business_types_are_stable_and_answer_conflict(): void
    {
        $business = ['espacio-no-disponible', 'saldo-insuficiente', 'plazo-vencido', 'bono-vencido', 'oferta-vencida'];

        foreach ($business as $value) {
            // from() throws if the stable value was renamed or removed.
            $this->assertSame(409, ProblemType::from($value)->status(), $value);
        }
    }

    /** Every type has a title and a detail in lang/es/problems.php. */
    public function test_every_type_has_a_title_and_a_detail_in_spanish(): void
    {
        $translations = require dirname(__DIR__, 4).'/lang/es/problems.php';

        foreach (ProblemType::cases() as $type) {
            $this->assertArrayHasKey($type->value, $translations);
            $this->assertNotSame('', $translations[$type->value]['title'] ?? '', $type->value);
            $this->assertNotSame('', $translations[$type->value]['detail'] ?? '', $type->value);
        }

        $this->assertCount(count(ProblemType::cases()), $translations);
    }

    /** Statuses outside the catalog fall back by error class. */
    #[DataProvider('uncataloguedStatuses')]
    public function test_uncatalogued_status_falls_back_by_error_class(int $status, ProblemType $expected): void
    {
        $this->assertSame($expected, ProblemType::fromStatus($status));
    }

    /**
     * Statuses without a type of their own and the fallback they map to.
     *
     * @return array<string, array{int, ProblemType}>
     */
    public static function uncataloguedStatuses(): array
    {
        return [
            'client error' => [418, ProblemType::BadRequest],
            'server error' => [502, ProblemType::InternalError],
        ];
    }
}
