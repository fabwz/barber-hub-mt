<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Barbershops;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Src\Identity\Memberships\Infrastructure\Persistence\Models\MembershipModel;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\ImageFixtures;
use Tests\Support\Shared\UploadsImages;
use Tests\TestCase;

/** The owner uploads the logo and the banner of the barbershop, validated by their content, and the public cover returns them (RF-46, SV-06, MT-05, MT-08). */
final class BarbershopImagesTest extends TestCase
{
    use RefreshDatabaseAsOwner;
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

    /** A JPG of 1 MB is accepted as the logo and kept in the folder of the barbershop (SV-06, MT-05). */
    public function test_a_jpg_of_one_megabyte_is_accepted_as_the_logo(): void
    {
        Sanctum::actingAs($this->owner, []);

        $response = $this->upload('logo', $this->puraNavaja, 'logo', $this->uploadOf(ImageFixtures::jpeg(300, 300, self::MEGABYTE), 'logo.jpg'))->assertOk();

        $stored = $this->storedFiles();
        $this->assertCount(1, $stored);
        $this->assertMatchesRegularExpression('#^barbershops/'.$this->puraNavaja.'/logo/[0-9a-f-]{36}\.jpg$#', $stored[0]);
        $response->assertExactJson(['data' => ['logo' => '/api/v1/archivos/'.$stored[0], 'banner' => null]]);
        $this->assertSame($stored[0], DB::table('barbershops')->where('id', $this->puraNavaja)->value('logo_path'));
    }

    /**
     * Each accepted type is stored with the extension of its content, whatever the name it came with.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function acceptedTypes(): array
    {
        return [
            'png' => ['png', 'logo.png', 'png'],
            'webp' => ['webp', 'logo.webp', 'webp'],
            'a png that arrives named .jpg' => ['png', 'logo.jpg', 'png'],
        ];
    }

    /** PNG and WebP are accepted too, and the stored name follows the content (SV-06). */
    #[DataProvider('acceptedTypes')]
    public function test_png_and_webp_are_accepted(string $format, string $name, string $extension): void
    {
        Sanctum::actingAs($this->owner, []);
        $bytes = $format === 'png' ? ImageFixtures::png(200, 200) : ImageFixtures::webp(200, 200);

        $this->upload('logo', $this->puraNavaja, 'logo', $this->uploadOf($bytes, $name))->assertOk();

        $this->assertStringEndsWith('.'.$extension, $this->storedFiles()[0]);
    }

    /**
     * Files that are not images, with the name of one: the content decides (SV-06).
     *
     * @return array<string, array{string, string}>
     */
    public static function fakeImages(): array
    {
        return [
            'a pdf named .jpg' => [ImageFixtures::pdf(), 'logo.jpg'],
            'an executable named .jpg' => [ImageFixtures::executable(), 'logo.jpg'],
            'an executable named .png' => [ImageFixtures::executable(), 'logo.png'],
            'a pdf named .webp' => [ImageFixtures::pdf(), 'logo.webp'],
            'a text file named .jpg' => ['no soy una imagen', 'logo.jpg'],
        ];
    }

    /** A PDF or an executable is rejected for the logo even if its name ends in .jpg, and nothing is stored. */
    #[DataProvider('fakeImages')]
    public function test_a_file_that_is_not_an_image_is_rejected_by_its_content(string $bytes, string $name): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->upload('logo', $this->puraNavaja, 'logo', $this->uploadOf($bytes, $name))
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('errores.logo.0', 'El logo debe ser una imagen JPG, PNG o WebP.');

        $this->assertNothingKept();
    }

    /** A PNG of 3 MB is rejected for being over 2 MB, and the error names the field (SV-06). */
    public function test_a_png_of_three_megabytes_is_rejected(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->upload('logo', $this->puraNavaja, 'logo', $this->uploadOf(ImageFixtures::png(300, 300, 3 * self::MEGABYTE), 'logo.png'))
            ->assertUnprocessable()
            ->assertJsonPath('type', rtrim((string) config('app.url'), '/').'/problemas/datos-invalidos')
            ->assertJsonPath('errores.logo.0', 'El logo no debe pesar más de 2 MB.');

        $this->assertNothingKept();
    }

    /** The limit is 2 MB: a file of exactly 2 MB passes and one byte more does not. */
    public function test_the_limit_is_two_megabytes(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->upload('logo', $this->puraNavaja, 'logo', $this->uploadOf(ImageFixtures::png(300, 300, 2 * self::MEGABYTE + 1), 'logo.png'))->assertUnprocessable();
        $this->upload('logo', $this->puraNavaja, 'logo', $this->uploadOf(ImageFixtures::png(300, 300, 2 * self::MEGABYTE), 'logo.png'))->assertOk();
    }

    /** Without a file the field is named (SV-06). */
    public function test_a_request_without_a_file_names_the_field(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->withHeader('Accept', 'application/json')->post($this->url($this->puraNavaja, 'logo'))->assertUnprocessable()->assertJsonValidationErrorFor('logo', 'errores');
        $this->withHeader('Accept', 'application/json')->post($this->url($this->puraNavaja, 'banner'))->assertUnprocessable()->assertJsonValidationErrorFor('banner', 'errores');
    }

    /** A banner of at least 1.200 × 400 px is accepted, exactly that size too (SV-06). */
    public function test_a_banner_of_the_minimum_size_is_accepted(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->upload('banner', $this->puraNavaja, 'banner', $this->uploadOf(ImageFixtures::png(1200, 400), 'banner.png'))->assertOk();
        $this->upload('banner', $this->puraNavaja, 'banner', $this->uploadOf(ImageFixtures::jpeg(1600, 600, self::MEGABYTE), 'banner.jpg'))->assertOk();
        $this->upload('banner', $this->puraNavaja, 'banner', $this->uploadOf(ImageFixtures::webp(1200, 400), 'banner.webp'))->assertOk();

        $this->assertMatchesRegularExpression('#^barbershops/'.$this->puraNavaja.'/banner/#', $this->storedFiles()[0]);
    }

    /**
     * Banners that miss the minimum in one side or both.
     *
     * @return array<string, array{int, int}>
     */
    public static function smallBanners(): array
    {
        return ['one pixel narrow' => [1199, 400], 'one pixel short' => [1200, 399], 'a logo-sized image' => [300, 300]];
    }

    /** A banner smaller than 1.200 × 400 px is rejected with a clear message, and nothing is stored (SV-06). */
    #[DataProvider('smallBanners')]
    public function test_a_small_banner_is_rejected_with_a_clear_message(int $width, int $height): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->upload('banner', $this->puraNavaja, 'banner', $this->uploadOf(ImageFixtures::png($width, $height), 'banner.png'))
            ->assertUnprocessable()
            ->assertJsonPath('errores.banner.0', 'El banner debe medir al menos 1.200 × 400 píxeles.');

        $this->assertNothingKept();
    }

    /** A PDF named .jpg is rejected for the banner too, and the logo has no size minimum (SV-06). */
    public function test_the_banner_is_checked_by_content_and_the_logo_has_no_minimum_size(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->upload('banner', $this->puraNavaja, 'banner', $this->uploadOf(ImageFixtures::pdf(), 'banner.jpg'))
            ->assertUnprocessable()
            ->assertJsonPath('errores.banner.0', 'El banner debe ser una imagen JPG, PNG o WebP.');
        $this->upload('logo', $this->puraNavaja, 'logo', $this->uploadOf(ImageFixtures::png(64, 64), 'logo.png'))->assertOk();
    }

    /** A new logo replaces the old one: the old file is deleted and only the new one stays. */
    public function test_a_new_logo_replaces_the_previous_file(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->upload('logo', $this->puraNavaja, 'logo', $this->uploadOf(ImageFixtures::png(200, 200), 'uno.png'))->assertOk();
        $first = $this->storedFiles();
        $this->upload('logo', $this->puraNavaja, 'logo', $this->uploadOf(ImageFixtures::jpeg(200, 200), 'dos.jpg'))->assertOk();
        $second = $this->storedFiles();

        $this->assertCount(1, $second);
        $this->assertNotSame($first, $second);
        $this->assertSame($second[0], DB::table('barbershops')->where('id', $this->puraNavaja)->value('logo_path'));
    }

    /** The logo and the banner are independent: changing one keeps the other. */
    public function test_the_logo_and_the_banner_are_independent(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->upload('logo', $this->puraNavaja, 'logo', $this->uploadOf(ImageFixtures::png(200, 200), 'logo.png'))->assertOk();
        $response = $this->upload('banner', $this->puraNavaja, 'banner', $this->uploadOf(ImageFixtures::png(1200, 400), 'banner.png'))->assertOk();

        $this->assertCount(2, $this->storedFiles());
        $this->assertNotNull($response->json('data.logo'));
        $this->assertNotNull($response->json('data.banner'));
    }

    /** The owner of another barbershop cannot change the image of this one: 404, the same as a barbershop that does not exist (MT-05, UP-06). */
    public function test_the_owner_of_another_barbershop_cannot_change_the_image(): void
    {
        Sanctum::actingAs($this->owner, []);
        $this->upload('logo', $this->puraNavaja, 'logo', $this->uploadOf(ImageFixtures::png(200, 200), 'logo.png'))->assertOk();
        $own = $this->storedFiles();

        Sanctum::actingAs($this->foreignOwner, []);
        $foreign = $this->upload('logo', $this->puraNavaja, 'logo', $this->uploadOf(ImageFixtures::png(200, 200), 'robado.png'));
        $missing = $this->upload('logo', (string) Str::uuid7(), 'logo', $this->uploadOf(ImageFixtures::png(200, 200), 'robado.png'));

        $foreign->assertNotFound();
        $this->assertSame($this->withoutInstance($missing->json()), $this->withoutInstance($foreign->json()));
        $this->assertSame($own, $this->storedFiles());
        $this->assertNull(DB::table('barbershops')->where('id', $this->fadeLab)->value('logo_path'));
    }

    /** Each barbershop keeps its image in its own folder and a new one never touches the other's (MT-05). */
    public function test_each_barbershop_keeps_its_own_image(): void
    {
        Sanctum::actingAs($this->owner, []);
        $this->upload('logo', $this->puraNavaja, 'logo', $this->uploadOf(ImageFixtures::png(200, 200), 'logo.png'))->assertOk();
        Sanctum::actingAs($this->foreignOwner, []);
        $this->upload('logo', $this->fadeLab, 'logo', $this->uploadOf(ImageFixtures::jpeg(200, 200), 'logo.jpg'))->assertOk();
        $this->upload('logo', $this->fadeLab, 'logo', $this->uploadOf(ImageFixtures::png(300, 300), 'otro.png'))->assertOk();

        $this->assertCount(2, $this->storedFiles());
        $this->assertStringStartsWith('barbershops/'.$this->puraNavaja.'/logo/', (string) DB::table('barbershops')->where('id', $this->puraNavaja)->value('logo_path'));
        $this->assertStringStartsWith('barbershops/'.$this->fadeLab.'/logo/', (string) DB::table('barbershops')->where('id', $this->fadeLab)->value('logo_path'));
    }

    /** Only the owner uploads: a collaborator and a customer get 403, a person without a role 404 and without a session 401 (UP-03). */
    public function test_only_the_owner_uploads(): void
    {
        $collaborator = $this->createMember('beto@barberhub.test', $this->puraNavaja, 'collaborator');
        $customer = $this->createMember('cata@barberhub.test', $this->puraNavaja, 'customer');
        $stranger = $this->createMember('dani@barberhub.test', null, null);
        $file = fn () => $this->uploadOf(ImageFixtures::png(1200, 400), 'logo.png');

        foreach (['logo', 'banner'] as $kind) {
            $this->upload($kind, $this->puraNavaja, $kind, $file())->assertUnauthorized();

            foreach ([[$collaborator, 403], [$customer, 403], [$stranger, 404]] as [$person, $status]) {
                Sanctum::actingAs($person, []);
                $this->upload($kind, $this->puraNavaja, $kind, $file())->assertStatus($status);
            }

            $this->app['auth']->forgetGuards();
        }

        $this->assertNothingKept();
    }

    /** A barbershop without images has neither key in its cover, so the client shows a monogram and a compact header (RF-46). */
    public function test_the_cover_of_a_barbershop_without_images_has_neither_key(): void
    {
        $response = $this->getJson('/api/v1/enlaces/pura-navaja')->assertOk();

        $response->assertJsonMissingPath('data.logo');
        $response->assertJsonMissingPath('data.banner');
    }

    /** The public cover returns the addresses of the logo and the banner when they exist, and each address serves its file (MT-08). */
    public function test_the_public_cover_returns_the_addresses_and_they_work(): void
    {
        Sanctum::actingAs($this->owner, []);
        $logo = ImageFixtures::png(200, 200);
        $banner = ImageFixtures::jpeg(1200, 400, 4096);
        $this->upload('logo', $this->puraNavaja, 'logo', $this->uploadOf($logo, 'logo.png'))->assertOk();
        $this->upload('banner', $this->puraNavaja, 'banner', $this->uploadOf($banner, 'banner.jpg'))->assertOk();
        $this->app['auth']->forgetGuards();

        $cover = $this->getJson('/api/v1/enlaces/pura-navaja')->assertOk();
        $logoUrl = (string) $cover->json('data.logo');
        $bannerUrl = (string) $cover->json('data.banner');

        $this->assertStringStartsWith('/api/v1/archivos/barbershops/'.$this->puraNavaja.'/logo/', $logoUrl);
        $this->assertStringStartsWith('/api/v1/archivos/barbershops/'.$this->puraNavaja.'/banner/', $bannerUrl);

        $served = $this->get($logoUrl)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('immutable', (string) $served->headers->get('Cache-Control'));
        $this->assertSame($logo, $served->streamedContent());
        $this->assertSame($banner, $this->get($bannerUrl)->assertOk()->assertHeader('Content-Type', 'image/jpeg')->streamedContent());
    }

    /** The file of a barbershop is not served from the address of another (MT-05). */
    public function test_the_file_of_a_barbershop_is_not_served_from_another_barbershop(): void
    {
        Sanctum::actingAs($this->owner, []);
        $this->upload('logo', $this->puraNavaja, 'logo', $this->uploadOf(ImageFixtures::png(200, 200), 'logo.png'))->assertOk();
        $this->app['auth']->forgetGuards();
        $path = $this->storedFiles()[0];
        $own = '/api/v1/archivos/'.$path;
        $elsewhere = str_replace($this->puraNavaja, $this->fadeLab, $own);

        $this->get($own)->assertOk();
        $this->get($elsewhere)->assertNotFound();
        $this->get(str_replace($this->puraNavaja, (string) Str::uuid7(), $own))->assertNotFound();
    }

    /**
     * Addresses under a barbershop that must never serve anything.
     *
     * @return array<string, array{string}>
     */
    public static function unservableAddresses(): array
    {
        return [
            'a file that does not exist' => ['logo/0192f0c4-0000-7000-8000-000000000001.png'],
            'an unknown folder' => ['otra/0192f0c4-0000-7000-8000-000000000001.png'],
            'a name with another extension' => ['logo/0192f0c4-0000-7000-8000-000000000001.php'],
            'a name that climbs' => ['logo/..%2F..%2Fsecreto.png'],
            'the folder itself' => ['logo/'],
        ];
    }

    /** What is not a stored image of the barbershop answers 404, and a file of the disk outside its folder is never reached. */
    #[DataProvider('unservableAddresses')]
    public function test_what_is_not_an_image_of_the_barbershop_is_not_served(string $tail): void
    {
        Storage::disk('public')->put('secreto.png', 'no');

        $this->get('/api/v1/archivos/barbershops/'.$this->puraNavaja.'/'.$tail)->assertNotFound();
    }

    /** Nothing stays on the disk or in the rows after a refused upload. */
    private function assertNothingKept(): void
    {
        $this->assertSame([], $this->storedFiles());
        $this->assertSame([null, null], [DB::table('barbershops')->where('id', $this->puraNavaja)->value('logo_path'), DB::table('barbershops')->where('id', $this->puraNavaja)->value('banner_path')]);
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
    private function upload(string $kind, string $barbershopId, string $field, mixed $file): TestResponse
    {
        return $this->withHeader('Accept', 'application/json')->post($this->url($barbershopId, $kind), [$field => $file]);
    }

    /** Address of the upload of one of the two images. */
    private function url(string $barbershopId, string $kind): string
    {
        return "/api/v1/barberias/{$barbershopId}/administracion/imagenes/{$kind}";
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
}
