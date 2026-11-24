<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Infrastructure\Catalog;

use Src\Catalog\Barbershops\Application\UseCases\GetBarbershopDetails;
use Src\Notifications\Notices\Application\Contracts\BarbershopBranding;
use Src\Notifications\Notices\Application\DTOs\Branding;
use Src\Shared\Infrastructure\Storage\MediaUrls;

final readonly class CatalogBarbershopBranding implements BarbershopBranding
{
    /** Receive the Catalog use case that reads the current barbershop. */
    public function __construct(private GetBarbershopDetails $getBarbershop) {}

    /**
     * Read the name and the logo of the barbershop. The address of a stored file is relative to the host, which an email cannot use, so it
     * is joined to the public address of the application (APP_URL). The file route is public, so the mail client can load it without a session.
     */
    public function current(): Branding
    {
        $barbershop = $this->getBarbershop->execute();
        $path = MediaUrls::of($barbershop->images->logo);
        $origin = rtrim(config()->string('app.url'), '/');

        return new Branding($barbershop->name->value, $path === null ? null : (str_starts_with($path, '/') ? $origin.$path : $path));
    }
}
