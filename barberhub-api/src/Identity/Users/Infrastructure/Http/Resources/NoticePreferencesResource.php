<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Identity\Users\Application\DTOs\NoticePreferences;

/**
 * @property-read NoticePreferences $resource
 */
#[SchemaName('PreferenciasDeAvisos')]
final class NoticePreferencesResource extends JsonResource
{
    /** Wrap the preferences of the person who asks. */
    public function __construct(private readonly NoticePreferences $preferences)
    {
        parent::__construct($preferences);
    }

    /**
     * Expose only whether the reminders by email are on.
     *
     * @return array{recordatorios_correo: bool}
     */
    public function toArray(Request $request): array
    {
        return ['recordatorios_correo' => $this->preferences->remindersEnabled];
    }
}
