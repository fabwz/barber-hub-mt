<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

interface PaymentAmounts
{
    /**
     * Tell how much the server would charge today over each price, in the same order, by the policy of the current barbershop (PA-01, PA-06).
     *
     * @param  list<int>  $prices  Colones.
     * @return list<int> Colones.
     */
    public function dueFor(array $prices): array;
}
