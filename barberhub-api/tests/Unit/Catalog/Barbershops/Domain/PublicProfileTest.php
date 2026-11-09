<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Barbershops\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Catalog\Barbershops\Domain\Exceptions\InvalidPublicProfileValueException;
use Src\Catalog\Barbershops\Domain\ValueObjects\PublicProfile;
use Src\Catalog\Barbershops\Domain\ValueObjects\SocialLink;
use Src\Catalog\Barbershops\Domain\ValueObjects\SocialNetwork;

/** The optional public data of a barbershop validates itself (RF-01). */
final class PublicProfileTest extends TestCase
{
    /** A new profile has no data. */
    public function test_an_empty_profile_has_no_data(): void
    {
        $profile = PublicProfile::empty();

        $this->assertNull($profile->address);
        $this->assertNull($profile->contactEmail);
        $this->assertSame([], $profile->links);
    }

    /** A change keeps what does not come and removes what comes null or blank. */
    public function test_a_change_keeps_removes_and_sets(): void
    {
        $profile = PublicProfile::of(['address' => 'Calle 1', 'instagram' => 'https://instagram.com/a', 'tiktok' => 'https://tiktok.com/@a']);

        $changed = $profile->changed(['instagram' => '  ', 'facebook' => 'https://fb.com/a', 'contact_email' => 'hola@puranavaja.test']);

        $this->assertSame([
            'address' => 'Calle 1',
            'contact_email' => 'hola@puranavaja.test',
            'whatsapp' => null,
            'instagram' => null,
            'facebook' => 'https://fb.com/a',
            'tiktok' => 'https://tiktok.com/@a',
        ], $changed->toPrimitives());
    }

    /**
     * @return iterable<string, array{SocialNetwork, string}>
     */
    public static function rejectedLinks(): iterable
    {
        yield 'other domain' => [SocialNetwork::Instagram, 'https://evil.test/instagram.com'];
        yield 'subdomain trick' => [SocialNetwork::Instagram, 'https://instagram.com.evil.test/a'];
        yield 'user info trick' => [SocialNetwork::Instagram, 'https://instagram.com@evil.test/a'];
        yield 'backslash trick' => [SocialNetwork::Instagram, 'https://evil.test\\@instagram.com/a'];
        yield 'port' => [SocialNetwork::Facebook, 'https://facebook.com:8443/a'];
        yield 'uppercase scheme' => [SocialNetwork::Tiktok, 'HTTPS://tiktok.com/@a'];
        yield 'http' => [SocialNetwork::Whatsapp, 'http://wa.me/1'];
        yield 'javascript' => [SocialNetwork::Whatsapp, 'javascript:alert(1)'];
        yield 'tab inside' => [SocialNetwork::Whatsapp, "https://wa.me/1\t2"];
        yield 'blank' => [SocialNetwork::Whatsapp, '   '];
        yield 'subdomain not www' => [SocialNetwork::Facebook, 'https://m.facebook.com/a'];
    }

    /** A link of the wrong domain, scheme or shape is rejected and points at `redes.<red>`. */
    #[DataProvider('rejectedLinks')]
    public function test_a_link_outside_the_rules_is_rejected(SocialNetwork $network, string $url): void
    {
        try {
            new SocialLink($network, $url);
            $this->fail('The link should have been rejected.');
        } catch (InvalidPublicProfileValueException $exception) {
            $this->assertSame('redes.'.$network->value, $exception->field());
        }
    }

    /** The domains of each network, with www. */
    public function test_www_is_accepted_on_every_network(): void
    {
        foreach (SocialNetwork::cases() as $network) {
            foreach ($network->domains() as $domain) {
                $this->assertSame("https://www.{$domain}/a", (new SocialLink($network, "https://www.{$domain}/a"))->value);
            }
        }
    }

    /** An invalid email and an address with a line break point at their own field. */
    public function test_email_and_address_point_at_their_field(): void
    {
        foreach ([['contact_email' => 'no-es-correo', 'correo_contacto'], ['address' => "a\nb", 'direccion'], ['address' => str_repeat('a', 201), 'direccion']] as $case) {
            $field = array_pop($case);

            try {
                PublicProfile::of($case);
                $this->fail('The profile should have been rejected.');
            } catch (InvalidPublicProfileValueException $exception) {
                $this->assertSame($field, $exception->field());
            }
        }
    }
}
