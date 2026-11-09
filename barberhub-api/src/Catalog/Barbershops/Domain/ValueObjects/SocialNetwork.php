<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Domain\ValueObjects;

/** The social networks a barbershop may link, each with the domains its links must belong to (RF-01, RF-02). */
enum SocialNetwork: string
{
    case Whatsapp = 'whatsapp';
    case Instagram = 'instagram';
    case Facebook = 'facebook';
    case Tiktok = 'tiktok';

    /**
     * The domains a link of this network may use; the same domain with a leading "www." is accepted too.
     *
     * @return list<string>
     */
    public function domains(): array
    {
        return match ($this) {
            self::Whatsapp => ['wa.me', 'api.whatsapp.com'],
            self::Instagram => ['instagram.com'],
            self::Facebook => ['facebook.com', 'fb.com'],
            self::Tiktok => ['tiktok.com'],
        };
    }

    /** Whether a lowercase host belongs to this network. */
    public function accepts(string $host): bool
    {
        foreach ($this->domains() as $domain) {
            if ($host === $domain || $host === 'www.'.$domain) {
                return true;
            }
        }

        return false;
    }
}
