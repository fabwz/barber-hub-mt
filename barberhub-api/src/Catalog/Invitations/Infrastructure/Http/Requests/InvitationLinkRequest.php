<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;

#[SchemaName('ConsultaDeInvitacion')]
final class InvitationLinkRequest extends FormRequest
{
    /** Longest token the API reads: the link carries 64 characters, anything longer is not one of ours. */
    private const int TOKEN_MAX_LENGTH = 128;

    /**
     * Validation rules for the token of the link.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /** El `token` del enlace que llegó por correo. */
            'token' => ['required', 'string', 'max:'.self::TOKEN_MAX_LENGTH],
        ];
    }

    /** The token of the link. */
    public function token(): string
    {
        return $this->string('token')->toString();
    }
}
