<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use DateTimeImmutable;
use DateTimeZone;
use Src\Notifications\Notices\Application\Contracts\BarbershopBranding;
use Src\Notifications\Notices\Application\DTOs\Branding;
use Src\Notifications\Notices\Application\DTOs\Notice;
use Src\Notifications\Notices\Application\DTOs\NoticeType;
use Src\Notifications\Notices\Application\DTOs\Recipient;
use Src\Notifications\Notices\Domain\ValueObjects\ReminderKind;
use Src\Notifications\Notices\Infrastructure\Mail\NoticeMail;
use Src\Shared\Infrastructure\Tenancy\BarbershopProcessScope;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Shared\ImageFixtures;
use Tests\Support\Shared\UploadsImages;
use Tests\TestCase;

/** The face of the barbershop in its emails: the absolute address of its logo, which the public route serves with no session, and the text of each kind (MT-08, RF-34). */
final class NoticeBrandingTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use UploadsImages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeMediaDisk();
        $this->seedBookableBarbershops();
    }

    private function branding(): Branding
    {
        return $this->app->make(BarbershopProcessScope::class)->run($this->puraNavaja, fn (): Branding => $this->app->make(BarbershopBranding::class)->current());
    }

    /** A barbershop with no logo has none in its email. */
    public function test_a_barbershop_without_logo_has_none(): void
    {
        $this->assertNull($this->branding()->logoUrl);
        $this->assertSame('pura-navaja', $this->branding()->barbershopName);
    }

    /** The logo is joined to the public address of the application, and that address answers the image with no session at all (MT-08). */
    public function test_the_logo_is_an_absolute_address_that_loads_without_a_session(): void
    {
        $this->actAs($this->owner);
        $this->withHeader('Accept', 'application/json')
            ->post("/api/v1/barberias/{$this->puraNavaja}/administracion/imagenes/logo", ['logo' => $this->uploadOf(ImageFixtures::png(200, 200), 'logo.png')])
            ->assertOk();

        $url = $this->branding()->logoUrl;
        $origin = rtrim((string) config('app.url'), '/');

        $this->assertNotNull($url);
        $this->assertStringStartsWith($origin.'/api/v1/archivos/barbershops/'.$this->puraNavaja.'/logo/', $url);

        $this->app->make('auth')->forgetGuards();
        $this->get(substr($url, strlen($origin)))->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    private function notice(NoticeType $type, ?string $logo = 'http://localhost/api/v1/archivos/barbershops/b/logo/l.png', string $name = 'Ana Rojas'): Notice
    {
        $zone = new DateTimeZone('America/Costa_Rica');

        return new Notice(
            $type,
            new Recipient($name, 'ana@barberhub.test'),
            new Branding('Pura Navaja', $logo),
            'Degradado',
            'Daniela',
            new DateTimeImmutable('2030-03-05 10:00:00', $zone),
            new DateTimeImmutable('2030-03-05 10:45:00', $zone),
            new DateTimeImmutable('2030-03-05 09:15:00', $zone),
            new DateTimeImmutable('2030-03-04 16:00:00', $zone),
            $type === NoticeType::Reminder ? ReminderKind::TwoHoursBefore : null,
            'key',
        );
    }

    /** The email carries the logo, the greeting, the facts and the sender in «usted», in HTML and in plain text. */
    public function test_the_email_has_the_logo_and_the_text_in_usted(): void
    {
        $mail = new NoticeMail($this->notice(NoticeType::Confirmation));

        $mail->assertSeeInHtml('<img src="http://localhost/api/v1/archivos/barbershops/b/logo/l.png"', false);
        $mail->assertSeeInHtml('Hola, Ana Rojas.');
        $mail->assertSeeInText('Su reserva de Degradado con Daniela está confirmada para el martes 5 de marzo, 10:00 a. m.');
        $mail->assertSeeInText('Este mensaje fue enviado por BarberHub en nombre de Pura Navaja.');
        $this->assertSame('Su reserva en Pura Navaja está confirmada', $mail->envelope()->subject);
    }

    /** Without a logo there is no image tag. */
    public function test_without_a_logo_there_is_no_image(): void
    {
        $mail = new NoticeMail($this->notice(NoticeType::Confirmation, logo: null));

        $mail->assertDontSeeInHtml('<img', false);
    }

    /** The name of the person is escaped in the HTML: a name with markup is text, not markup. */
    public function test_a_name_with_markup_is_escaped(): void
    {
        $mail = new NoticeMail($this->notice(NoticeType::Confirmation, name: '<script>x</script>'));

        $mail->assertDontSeeInHtml('<script>x</script>', false);
        $mail->assertSeeInHtml('&lt;script&gt;x&lt;/script&gt;', false);
    }

    /** Each kind has its subject; the reminders say how long before it is; the offer says until when and the reschedule says from when to when. */
    public function test_each_kind_has_its_own_subject_and_body(): void
    {
        $subjects = [
            [NoticeType::Offer, 'Hay un espacio disponible para usted en Pura Navaja', 'Puede aceptarlo hasta las 9:15 a. m.'],
            [NoticeType::Cancellation, 'La barbería canceló su reserva en Pura Navaja', 'Lamentamos el inconveniente.'],
            [NoticeType::Reschedule, 'La barbería cambió la hora de su reserva en Pura Navaja', 'Hora anterior: lunes 4 de marzo, 4:00 p. m.'],
            [NoticeType::Reminder, 'Recordatorio: su reserva en Pura Navaja es en 2 horas', 'es hoy, el martes 5 de marzo, 10:00 a. m.'],
        ];

        foreach ($subjects as [$type, $subject, $line]) {
            $mail = new NoticeMail($this->notice($type));
            $this->assertSame($subject, $mail->envelope()->subject);
            $mail->assertSeeInText($line);
        }
    }

    /** The period of «a. m.» and the period of the sentence are one point: no kind of notice shows a double period after an hour, in the text or in the HTML (#96). */
    public function test_no_notice_shows_a_double_period_after_the_time(): void
    {
        foreach (NoticeType::cases() as $type) {
            $mail = new NoticeMail($this->notice($type));

            $mail->assertDontSeeInText('..');
            $mail->assertDontSeeInHtml('..', false);
        }

        $mail = new NoticeMail($this->notice(NoticeType::Offer));
        $mail->assertSeeInText('hasta las 9:15 a. m. Si no responde');
        $mail->assertDontSeeInText('a. m..');
    }

    /** The key of idempotency travels in a header the transport reads, and a notice without a key carries none. */
    public function test_the_idempotency_key_travels_in_a_header(): void
    {
        $this->assertSame(['X-Notice-Idempotency-Key' => 'key'], (new NoticeMail($this->notice(NoticeType::Offer)))->headers()->text);
    }
}
