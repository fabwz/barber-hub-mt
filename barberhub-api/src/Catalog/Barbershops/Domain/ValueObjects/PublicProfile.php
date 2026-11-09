<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Domain\ValueObjects;

/** What a barbershop shows besides its name, city and phone: the address, the contact email and up to four social links, all optional (RF-01, RF-02). */
final readonly class PublicProfile
{
    /**
     * Hold the optional data, already valid.
     *
     * @param  array<string, SocialLink>  $links  By network name, only the networks that have a link.
     */
    private function __construct(
        public ?Address $address,
        public ?ContactEmail $contactEmail,
        public array $links,
    ) {}

    /** A profile with no data, which is how a barbershop starts. */
    public static function empty(): self
    {
        return new self(null, null, []);
    }

    /**
     * Build a profile from texts; a null or blank text means there is no such data.
     *
     * @param  array<string, string|null>  $values  Keys "address", "contact_email" and the name of each network.
     */
    public static function of(array $values): self
    {
        $links = [];
        foreach (SocialNetwork::cases() as $network) {
            $link = self::text($values[$network->value] ?? null);

            if ($link !== null) {
                $links[$network->value] = new SocialLink($network, $link);
            }
        }

        $address = self::text($values['address'] ?? null);
        $email = self::text($values['contact_email'] ?? null);

        return new self(
            $address === null ? null : new Address($address),
            $email === null ? null : new ContactEmail($email),
            $links,
        );
    }

    /**
     * The same profile with only the given data changed; a key that is missing keeps its value and one that is null or blank
     * removes it. The result is validated as a whole.
     *
     * @param  array<string, string|null>  $changes  Keys "address", "contact_email" and the name of each network.
     */
    public function changed(array $changes): self
    {
        return self::of([...$this->toPrimitives(), ...$changes]);
    }

    /**
     * The data as texts, with null for what is not set.
     *
     * @return array<string, string|null>
     */
    public function toPrimitives(): array
    {
        $values = ['address' => $this->address?->value, 'contact_email' => $this->contactEmail?->value];

        foreach (SocialNetwork::cases() as $network) {
            $values[$network->value] = ($this->links[$network->value] ?? null)?->value;
        }

        return $values;
    }

    /** The text, or null when it is missing or blank. */
    private static function text(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }
}
