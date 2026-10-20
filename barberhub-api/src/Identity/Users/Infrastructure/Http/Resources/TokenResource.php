<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Identity\Users\Application\DTOs\IssuedToken;

/**
 * @property-read IssuedToken $resource
 */
#[SchemaName('TokenDeAcceso')]
final class TokenResource extends JsonResource
{
    private const string TYPE = 'Bearer';

    /** Wrap a token issued at login. */
    public function __construct(private readonly IssuedToken $token)
    {
        parent::__construct($token);
    }

    /**
     * Expose the token and the way to send it.
     *
     * @return array<string, string>
     */
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->token->plainTextToken,
            'tipo' => self::TYPE,
        ];
    }
}
