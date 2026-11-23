<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Src\Identity\Memberships\Infrastructure\Persistence\Models\MembershipModel;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\ImageFixtures;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\Support\Shared\UploadsImages;
use Tests\TestCase;

/** The owner uploads the image of a service, validated by its content, and the customer sees it where the service is chosen (RF-46, SV-06, SV-07, MT-05). */
final class ServiceImageTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;
    use UploadsImages;

    private const int MEGABYTE = 1024 * 1024;

    private string $puraNavaja;

    private string $fadeLab;

    private UserModel $owner;

    private UserModel $foreignOwner;

    /** Two barbershops with their owners, on a temporary disk. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeMediaDisk();
        $this->puraNavaja = $this->createBarbershop('pura-navaja');
        $this->fadeLab = $this->createBarbershop('fade-lab');
        $this->owner = $this->createMember('ana@barberhub.test', $this->puraNavaja, 'owner');
        $this->foreignOwner = $this->createMember('luis@barberhub.test', $this->fadeLab, 'owner');
    }

    /** The owner uploads a JPG of 1 MB for a service: it is kept in the folder of the barbershop and the service answers its address (SV-06, MT-05). */
    public function test_the_owner_uploads_the_image_of_a_service(): void
    {
        $service = $this->createService($this->puraNavaja, 'Corte clásico');
        Sanctum::actingAs($this->owner, []);

        $response = $this->upload($this->puraNavaja, $service, $this->uploadOf(ImageFixtures::jpeg(400, 300, self::MEGABYTE), 'corte.jpg'))->assertOk();

        $stored = $this->storedFiles();
        $this->assertCount(1, $stored);
        $this->assertMatchesRegularExpression('#^barbershops/'.$this->puraNavaja.'/servicios/[0-9a-f-]{36}\.jpg$#', $stored[0]);
        $response->assertJsonPath('data.id', $service)->assertJsonPath('data.imagen', '/api/v1/archivos/'.$stored[0]);
        $this->assertSame($stored[0], $this->imagePathOf($this->puraNavaja, $service));
    }

    /**
     * Files that are not images, with the name of one, and an image over 2 MB: the content and the weight decide (SV-06).
     *
     * @return array<string, array{string, string, string}>
     */
    public static function refusedFiles(): array
    {
        return [
            'a pdf named .jpg' => [ImageFixtures::pdf(), 'corte.jpg', 'La imagen debe ser JPG, PNG o WebP.'],
            'an executable named .jpg' => [ImageFixtures::executable(), 'corte.jpg', 'La imagen debe ser JPG, PNG o WebP.'],
            'a png of 3 MB' => [ImageFixtures::png(300, 300, 3 * self::MEGABYTE), 'corte.png', 'La imagen no debe pesar más de 2 MB.'],
        ];
    }

    /** A refused file names the field, keeps nothing and leaves the previous image of the service as it was. */
    #[DataProvider('refusedFiles')]
    public function test_a_refused_file_names_the_field_and_changes_nothing(string $bytes, string $name, string $message): void
    {
        $service = $this->createService($this->puraNavaja, 'Corte clásico');
        Sanctum::actingAs($this->owner, []);
        $this->upload($this->puraNavaja, $service, $this->uploadOf(ImageFixtures::png(200, 200), 'bien.png'))->assertOk();
        $before = $this->storedFiles();

        $this->upload($this->puraNavaja, $service, $this->uploadOf($bytes, $name))
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('errores.imagen.0', $message);

        $this->assertSame($before, $this->storedFiles());
        $this->assertSame($before[0], $this->imagePathOf($this->puraNavaja, $service));
    }

    /** Without a file the field is named. */
    public function test_a_request_without_a_file_names_the_field(): void
    {
        $service = $this->createService($this->puraNavaja, 'Corte clásico');
        Sanctum::actingAs($this->owner, []);

        $this->withHeader('Accept', 'application/json')->post($this->url($this->puraNavaja, $service))->assertUnprocessable()->assertJsonValidationErrorFor('imagen', 'errores');
    }

    /** A new image replaces the old one: the old file is deleted. */
    public function test_a_new_image_replaces_the_previous_file(): void
    {
        $service = $this->createService($this->puraNavaja, 'Corte clásico');
        Sanctum::actingAs($this->owner, []);

        $this->upload($this->puraNavaja, $service, $this->uploadOf(ImageFixtures::png(200, 200), 'uno.png'))->assertOk();
        $first = $this->storedFiles();
        $this->upload($this->puraNavaja, $service, $this->uploadOf(ImageFixtures::webp(200, 200), 'dos.webp'))->assertOk();
        $second = $this->storedFiles();

        $this->assertCount(1, $second);
        $this->assertNotSame($first, $second);
        $this->assertSame($second[0], $this->imagePathOf($this->puraNavaja, $service));
    }

    /** The owner of another barbershop cannot change the image of a service of this one: 404, the same as a service that does not exist (MT-05, UP-06). */
    public function test_the_owner_of_another_barbershop_cannot_change_the_image_of_a_service(): void
    {
        $service = $this->createService($this->puraNavaja, 'Corte clásico');
        Sanctum::actingAs($this->owner, []);
        $this->upload($this->puraNavaja, $service, $this->uploadOf(ImageFixtures::png(200, 200), 'bien.png'))->assertOk();
        $before = $this->storedFiles();

        Sanctum::actingAs($this->foreignOwner, []);
        $this->upload($this->puraNavaja, $service, $this->uploadOf(ImageFixtures::png(200, 200), 'robado.png'))->assertNotFound();
        $this->assertSame($before, $this->storedFiles());
        $this->assertSame($before[0], $this->imagePathOf($this->puraNavaja, $service));
    }

    /** The owner of a barbershop cannot reach a service of another one through its own barbershop: 404 and no file is kept (MT-05). */
    public function test_a_service_of_another_barbershop_is_not_found_through_the_own_barbershop(): void
    {
        $foreign = $this->createService($this->fadeLab, 'Corte de Fade Lab');
        Sanctum::actingAs($this->owner, []);

        $foreignAnswer = $this->upload($this->puraNavaja, $foreign, $this->uploadOf(ImageFixtures::png(200, 200), 'robado.png'));
        $missingAnswer = $this->upload($this->puraNavaja, (string) Str::uuid7(), $this->uploadOf(ImageFixtures::png(200, 200), 'robado.png'));

        $foreignAnswer->assertNotFound();
        $this->assertSame($this->withoutInstance($missingAnswer->json()), $this->withoutInstance($foreignAnswer->json()));
        $this->assertSame([], $this->storedFiles());
        $this->assertNull($this->imagePathOf($this->fadeLab, $foreign));
    }

    /** Only the owner uploads: a collaborator and a customer get 403, a person without a role 404 and without a session 401. */
    public function test_only_the_owner_uploads(): void
    {
        $service = $this->createService($this->puraNavaja, 'Corte clásico');
        $collaborator = $this->createMember('beto@barberhub.test', $this->puraNavaja, 'collaborator');
        $customer = $this->createMember('cata@barberhub.test', $this->puraNavaja, 'customer');
        $stranger = $this->createMember('dani@barberhub.test', null, null);

        $this->upload($this->puraNavaja, $service, $this->uploadOf(ImageFixtures::png(200, 200), 'corte.png'))->assertUnauthorized();

        foreach ([[$collaborator, 403], [$customer, 403], [$stranger, 404]] as [$person, $status]) {
            Sanctum::actingAs($person, []);
            $this->upload($this->puraNavaja, $service, $this->uploadOf(ImageFixtures::png(200, 200), 'corte.png'))->assertStatus($status);
        }

        $this->assertSame([], $this->storedFiles());
    }

    /** Where the customer chooses the service, the one with an image shows its address and the one without it shows none; the address serves the file (SV-07). */
    public function test_the_customer_sees_the_image_where_the_service_is_chosen(): void
    {
        $withImage = $this->createService($this->puraNavaja, 'Corte clásico');
        $without = $this->createService($this->puraNavaja, 'Barba');
        $bytes = ImageFixtures::png(200, 200);
        Sanctum::actingAs($this->owner, []);
        $this->upload($this->puraNavaja, $withImage, $this->uploadOf($bytes, 'corte.png'))->assertOk();
        $this->app['auth']->forgetGuards();

        $list = $this->getJson("/api/v1/barberias/{$this->puraNavaja}/servicios")->assertOk();
        $byId = [];
        foreach ((array) $list->json('data') as $service) {
            $byId[(string) $service['id']] = (array) $service;
        }

        $this->assertStringStartsWith('/api/v1/archivos/barbershops/'.$this->puraNavaja.'/servicios/', (string) $byId[$withImage]['imagen']);
        $this->assertArrayNotHasKey('imagen', $byId[$without]);
        $this->assertSame($bytes, $this->get((string) $byId[$withImage]['imagen'])->assertOk()->assertHeader('Content-Type', 'image/png')->streamedContent());
    }

    /** The owner sees the image of every service in the list of the administration, and `null` where there is none. */
    public function test_the_administration_list_shows_the_image_or_null(): void
    {
        $withImage = $this->createService($this->puraNavaja, 'Corte clásico');
        $this->createService($this->puraNavaja, 'Barba');
        Sanctum::actingAs($this->owner, []);
        $this->upload($this->puraNavaja, $withImage, $this->uploadOf(ImageFixtures::png(200, 200), 'corte.png'))->assertOk();

        $images = [];
        foreach ((array) $this->getJson("/api/v1/barberias/{$this->puraNavaja}/administracion/servicios")->assertOk()->json('data') as $service) {
            $images[(string) $service['nombre']] = $service['imagen'];
        }

        $this->assertNull($images['Barba']);
        $this->assertStringStartsWith('/api/v1/archivos/barbershops/'.$this->puraNavaja.'/servicios/', (string) $images['Corte clásico']);
    }

    /** The image stays through an edit of the service (the PATCH does not touch it). */
    public function test_editing_the_service_keeps_its_image(): void
    {
        $service = $this->createService($this->puraNavaja, 'Corte clásico');
        Sanctum::actingAs($this->owner, []);
        $this->upload($this->puraNavaja, $service, $this->uploadOf(ImageFixtures::png(200, 200), 'corte.png'))->assertOk();
        $path = $this->imagePathOf($this->puraNavaja, $service);

        $this->patchJson("/api/v1/barberias/{$this->puraNavaja}/administracion/servicios/{$service}", ['precio' => 7500])->assertOk();

        $this->assertSame($path, $this->imagePathOf($this->puraNavaja, $service));
    }

    /**
     * The answer without the identifier of the request, which is different each time.
     *
     * @return array<string, mixed>
     */
    private function withoutInstance(mixed $problem): array
    {
        $problem = (array) $problem;
        unset($problem['instance']);

        return $problem;
    }

    /**
     * Send a file as the browser would, in a multipart body.
     *
     * @return TestResponse<Response>
     */
    private function upload(string $barbershopId, string $serviceId, mixed $file): TestResponse
    {
        return $this->withHeader('Accept', 'application/json')->post($this->url($barbershopId, $serviceId), ['imagen' => $file]);
    }

    /** Address of the upload of the image of a service. */
    private function url(string $barbershopId, string $serviceId): string
    {
        return "/api/v1/barberias/{$barbershopId}/administracion/servicios/{$serviceId}/imagen";
    }

    /** The path of the image of a service, read as its barbershop. */
    private function imagePathOf(string $barbershopId, string $serviceId): ?string
    {
        $this->scopeQueriesTo($barbershopId);

        $path = DB::table('services')->where('id', $serviceId)->value('image_path');

        return is_string($path) ? $path : null;
    }

    /** Insert a barbershop straight into the table and return its identifier. */
    private function createBarbershop(string $link): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => $link, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);

        return $id;
    }

    /** Create a person, with a role in a barbershop unless none is given. */
    private function createMember(string $email, ?string $barbershopId, ?string $role): UserModel
    {
        $user = UserModel::query()->create(['name' => $email, 'email' => $email, 'password' => 'hash']);

        if ($barbershopId !== null) {
            MembershipModel::query()->create(['user_id' => $user->id, 'barbershop_id' => $barbershopId, 'role' => $role, 'is_active' => true]);
        }

        return $user;
    }

    /** Insert a service of a barbershop straight into the table and return its identifier. */
    private function createService(string $barbershopId, string $name): string
    {
        $id = (string) Str::uuid7();
        $this->scopeQueriesTo($barbershopId);
        DB::table('services')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'name' => $name, 'duration_minutes' => 30, 'price' => 7000]);

        return $id;
    }
}
