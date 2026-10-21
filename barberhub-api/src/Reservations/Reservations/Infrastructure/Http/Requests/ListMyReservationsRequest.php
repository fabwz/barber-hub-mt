<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Http\Requests;

use DateTimeImmutable;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use LogicException;
use Src\Reservations\Reservations\Application\DTOs\ReservationSearch;
use Src\Reservations\Reservations\Infrastructure\Http\ReservationStatusNames;
use Src\Shared\Infrastructure\Http\Requests\ListRequest;

#[SchemaName('ConsultarMisReservas')]
class ListMyReservationsRequest extends ListRequest
{
    private const string DAY_FORMAT = 'Y-m-d';

    /**
     * Validation rules for the page and the filters of a listing of reservations (RF-13).
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            /** Día de las reservas, fecha de pared en Costa Rica con la forma `2030-03-05`. Sin él se listan todos los días. */
            'dia' => ['sometimes', 'date_format:'.self::DAY_FORMAT],
            /** Estado por el que filtrar: `temporal`, `confirmada`, `completada`, `ausente`, `cancelada_por_cliente`, `cancelada_por_barberia` o `expirada`. Sin él se listan todos. */
            'estado' => ['sometimes', 'string', 'in:'.implode(',', ReservationStatusNames::names())],
        ];
    }

    /** What the person filters by; the day starts at midnight of Costa Rica, whatever the time zone of the client. */
    public function search(): ReservationSearch
    {
        return new ReservationSearch(
            $this->filled('dia') ? $this->day() : null,
            $this->filled('estado') ? ReservationStatusNames::status($this->string('estado')->toString()) : null,
            $this->collaborator(),
        );
    }

    /** The collaborator asked for; only the listing of the staff accepts one. */
    protected function collaborator(): ?string
    {
        return null;
    }

    /** The instant the day asked for begins in the time zone of the application, Costa Rica. */
    private function day(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat('!'.self::DAY_FORMAT, $this->string('dia')->toString(), new DateTimeZone((string) config('app.timezone')))
            ?: throw new LogicException('The day was validated and still does not parse.');
    }
}
