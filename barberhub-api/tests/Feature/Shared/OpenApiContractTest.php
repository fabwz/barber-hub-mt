<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class OpenApiContractTest extends TestCase
{
    private const string CONTRACT_PATH = 'openapi/v1.json';

    /** The versioned contract matches the one generated from the code. */
    public function test_versioned_contract_matches_the_code(): void
    {
        $generatedPath = storage_path('framework/testing/openapi.json');

        Artisan::call('scramble:export', ['--path' => $generatedPath]);

        $this->assertSame(
            File::json(base_path(self::CONTRACT_PATH)),
            File::json($generatedPath),
            'The contract is outdated. Run "composer openapi" and commit the result.',
        );
    }

    /** Error responses are documented as problem documents. */
    public function test_contract_documents_errors_as_problem_documents(): void
    {
        $contract = File::json(base_path(self::CONTRACT_PATH));

        $this->assertSame('3.1.0', $contract['openapi']);

        foreach ($contract['components']['responses'] as $response) {
            $this->assertSame(['application/problem+json'], array_keys($response['content']));
        }
    }

    /** A voucher lists its covered services as UUIDs and says whether it has sales (RF-26). */
    public function test_voucher_contract_types_its_services_and_sales_flag(): void
    {
        $voucher = File::json(base_path(self::CONTRACT_PATH))['components']['schemas']['Bono'];

        $this->assertSame(['type' => 'string', 'format' => 'uuid'], $voucher['properties']['servicios']['items']);
        $this->assertSame('boolean', $voucher['properties']['tiene_ventas']['type']);
        $this->assertContains('tiene_ventas', $voucher['required']);
    }

    /** The interactive documentation and its document are published. */
    public function test_interactive_documentation_is_published(): void
    {
        $this->get('/docs/api')->assertOk();
        $this->getJson('/docs/api.json')->assertOk()->assertJsonPath('info.title', 'BarberHub API');
    }
}
