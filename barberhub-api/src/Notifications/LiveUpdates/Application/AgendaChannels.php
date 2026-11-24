<?php

declare(strict_types=1);

namespace Src\Notifications\LiveUpdates\Application;

/**
 * The names of the private channels of the live agenda (RF-36), without the `private-` prefix that the transport adds. The channel of a
 * barbershop carries every change of its agenda and only its owner may listen to it; the channel of a collaborator carries the changes
 * of their own agenda and only they may listen to it (CO-04). The identifiers in the names are the ones of the domain events, which were
 * written from a verified context, never anything a person sent.
 */
final class AgendaChannels
{
    /** Name of the channel with every change of the agenda of a barbershop. */
    public static function ofBarbershop(string $barbershopId): string
    {
        return "barberia.{$barbershopId}.agenda";
    }

    /** Name of the channel with the changes of the agenda of one collaborator. */
    public static function ofCollaborator(string $barbershopId, string $collaboratorId): string
    {
        return "barberia.{$barbershopId}.colaboradores.{$collaboratorId}";
    }
}
