<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Domain\ValueObjects;

use Src\Catalog\Barbershops\Domain\Exceptions\InvalidPublicProfileValueException;

/** A link to the page of the barbershop in a social network: https only, and only on the domains of that network (RF-01). */
final readonly class SocialLink
{
    public const int MAX_LENGTH = 300;

    public string $value;

    /**
     * Trim the link and reject anything that is not an https link to a domain of the network: another scheme (javascript:,
     * data:, http:), another domain, credentials or a port in the address, spaces, line breaks or backslashes.
     */
    public function __construct(public SocialNetwork $network, string $value)
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > self::MAX_LENGTH || ! $this->belongsToNetwork($value)) {
            throw InvalidPublicProfileValueException::socialLink($network, self::MAX_LENGTH);
        }

        $this->value = $value;
    }

    /** Whether the text is a clean https link whose host is one of the network. */
    private function belongsToNetwork(string $value): bool
    {
        if (! str_starts_with($value, 'https://') || preg_match('/[\s\x00-\x1F\x7F\\\\]/u', $value) !== 0) {
            return false;
        }

        $parts = parse_url($value);

        if ($parts === false || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        return $this->network->accepts(strtolower($parts['host']));
    }
}
