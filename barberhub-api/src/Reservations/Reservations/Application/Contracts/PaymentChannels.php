<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

use Src\Reservations\Reservations\Application\DTOs\PaymentChannel;

interface PaymentChannels
{
    /**
     * Tell, for each reservation, the gateway it is paid through and the methods that gateway admits (RF-20, PA-02).
     *
     * @param  list<string>  $reservationIds
     * @return array<string, PaymentChannel> By reservation id.
     */
    public function of(array $reservationIds): array;
}
