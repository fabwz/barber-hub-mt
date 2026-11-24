<?php

declare(strict_types=1);

namespace Src\Notifications\LiveUpdates\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;

#[SchemaName('AutorizarCanal')]
final class AuthorizeChannelRequest extends FormRequest
{
    /**
     * Validation rules of the subscription the WebSocket client asks to authorize (RF-36). The two names are the ones of the protocol of the
     * client library, which sends them and expects nothing else, so they are not translated (RD-07).
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /** Identificador de la conexión que el servidor de tiempo real entrega al navegador. */
            'socket_id' => ['required', 'string', 'regex:/^\d+\.\d+$/'],
            /** Canal privado al que la persona quiere suscribirse, con su prefijo `private-`. */
            'channel_name' => ['required', 'string', 'max:200', 'starts_with:private-'],
        ];
    }
}
