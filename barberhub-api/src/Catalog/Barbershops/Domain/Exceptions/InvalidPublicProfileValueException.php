<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Domain\Exceptions;

use Src\Catalog\Barbershops\Domain\ValueObjects\SocialNetwork;
use Src\Shared\Domain\Exceptions\HasInvalidField;
use Src\Shared\Domain\Exceptions\InvalidValueException;

final class InvalidPublicProfileValueException extends InvalidValueException implements HasInvalidField
{
    /** Build the exception for an address that is empty after trimming, too long or has control characters. */
    public static function address(int $max): self
    {
        return new self('barbershops.address_invalid', ['max' => $max, 'field' => 'direccion']);
    }

    /** Build the exception for a contact email that is not an email or is too long. */
    public static function contactEmail(int $max): self
    {
        return new self('barbershops.contact_email_invalid', ['max' => $max, 'field' => 'correo_contacto']);
    }

    /** Build the exception for a link that is not a plain https link to the domains of its network. */
    public static function socialLink(SocialNetwork $network, int $max): self
    {
        return new self('barbershops.social_link_invalid', [
            'max' => $max,
            'domains' => implode(', ', $network->domains()),
            'field' => 'redes.'.$network->value,
        ]);
    }

    /** The attribute of the request whose value is invalid. */
    public function field(): string
    {
        return (string) $this->parameters()['field'];
    }
}
