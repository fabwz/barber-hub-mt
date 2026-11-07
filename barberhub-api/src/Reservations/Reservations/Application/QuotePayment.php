<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application;

use DateTimeImmutable;
use Src\Reservations\Reservations\Application\Contracts\PaymentAmounts;
use Src\Reservations\Reservations\Application\Contracts\PaymentChannels;
use Src\Reservations\Reservations\Application\DTOs\PaymentDue;
use Src\Reservations\Reservations\Application\DTOs\ReservationView;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;
use Src\Shared\Domain\ValueObjects\Page;

final readonly class QuotePayment
{
    /** Receive the port that tells the amount the server would charge and the one that tells the gateway and methods to pay with. */
    public function __construct(private PaymentAmounts $amounts, private PaymentChannels $channels) {}

    /**
     * Tell what each reservation owes now (RF-20). Only a reservation still temporary at `$now` can be charged, so any other
     * owes nothing, and that includes one already paid, which is confirmed. For the temporary ones the amount is what the
     * charge would ask today by the policy of the barbershop, asked once for all; the balance is the price less that amount,
     * the deadline (RE-05) and the channel (the gateway and its methods, PA-02) are shown only while there is something to pay,
     * so a barbershop that does not charge, or a reservation that owes nothing, has no gateway.
     *
     * @param  list<Reservation>  $reservations
     * @return array<string, PaymentDue> By reservation id.
     */
    public function of(array $reservations, DateTimeImmutable $now): array
    {
        $open = array_values(array_filter($reservations, fn (Reservation $reservation): bool => $reservation->statusAt($now) === ReservationStatus::Temporary));
        $charged = $open === [] ? [] : $this->amounts->dueFor(array_map(fn (Reservation $reservation): int => $reservation->price->colones, $open));
        $amounts = [];
        foreach ($open as $index => $reservation) {
            $amounts[$reservation->id->value] = $charged[$index];
        }

        $payable = array_keys(array_filter($amounts, fn (int $amount): bool => $amount > 0));
        $channels = $this->channels->of($payable);

        $dues = [];
        foreach ($reservations as $reservation) {
            $amount = $amounts[$reservation->id->value] ?? 0;
            $dues[$reservation->id->value] = new PaymentDue($amount, $reservation->price->colones - $amount, $amount > 0 ? $reservation->expiresAt : null, $channels[$reservation->id->value] ?? null);
        }

        return $dues;
    }

    /** Quote one reservation. */
    public function one(Reservation $reservation, DateTimeImmutable $now): PaymentDue
    {
        return $this->of([$reservation], $now)[$reservation->id->value];
    }

    /**
     * Give each view of the page its quote.
     *
     * @param  Page<ReservationView>  $page
     * @return Page<ReservationView>
     */
    public function page(Page $page, DateTimeImmutable $now): Page
    {
        $dues = $this->of(array_map(fn (ReservationView $view): Reservation => $view->reservation, $page->items), $now);

        return $page->map(fn (ReservationView $view): ReservationView => $view->withPayment($dues[$view->reservation->id->value]));
    }
}
