<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Http\Problem;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Src\Shared\Domain\Exceptions\AuthenticationFailedException;
use Src\Shared\Domain\Exceptions\BusinessRuleException;
use Src\Shared\Domain\Exceptions\DomainException;
use Src\Shared\Domain\Exceptions\HasInvalidField;
use Src\Shared\Domain\Exceptions\HasProblemMembers;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\Exceptions\NotFoundException;
use Src\Shared\Domain\Exceptions\TooManyRequestsException;
use Src\Shared\Infrastructure\Http\Middleware\AssignRequestId;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final readonly class ProblemDetailsRenderer
{
    public const string CONTENT_TYPE = 'application/problem+json';

    /** Members of every problem document (RFC 9457); an error cannot replace them. */
    private const array STANDARD_MEMBERS = ['type', 'title', 'status', 'detail', 'instance'];

    /** Render any exception as an RFC 9457 problem document. */
    public function __invoke(Throwable $exception): JsonResponse
    {
        return match (true) {
            $exception instanceof ValidationException => $this->respond(
                ProblemType::InvalidData,
                extensions: ['errores' => $exception->errors()],
            ),
            $exception instanceof TooManyRequestsException => $this->respond(
                ProblemType::TooManyRequests,
                trans_choice(
                    $exception->translationKey(),
                    max(1, (int) ceil($exception->retryAfterSeconds() / 60)),
                    $exception->parameters(),
                ),
                headers: ['Retry-After' => (string) $exception->retryAfterSeconds()],
            ),
            $exception instanceof DomainException => $this->respondToDomainException($exception),
            $exception instanceof AuthenticationException => $this->respond(ProblemType::Unauthenticated),
            $exception instanceof HttpExceptionInterface => $this->respond(
                ProblemType::fromStatus($exception->getStatusCode()),
                headers: $exception->getHeaders(),
            ),
            default => $this->respond(ProblemType::InternalError),
        };
    }

    /** Render a domain exception; one that points at a field also reports it under `errores`, like a validation error of the request. */
    private function respondToDomainException(DomainException $exception): JsonResponse
    {
        $detail = __($exception->translationKey(), $exception->parameters());
        $extensions = $exception instanceof HasProblemMembers ? $this->customMembers($exception) : [];

        if ($exception instanceof HasInvalidField) {
            $extensions['errores'] = [$exception->field() => [$detail]];
        }

        return $this->respond($this->typeOfDomainException($exception), $detail, extensions: $extensions);
    }

    /** Classify a domain exception into a problem type. */
    private function typeOfDomainException(DomainException $exception): ProblemType
    {
        return match (true) {
            $exception instanceof NotFoundException => ProblemType::NotFound,
            $exception instanceof InvalidValueException => ProblemType::InvalidData,
            $exception instanceof AuthenticationFailedException => ProblemType::InvalidCredentials,
            $exception instanceof BusinessRuleException => ProblemType::tryFrom($exception->rule()) ?? ProblemType::Conflict,
            default => ProblemType::Conflict,
        };
    }

    /**
     * The extra members an error brings, without the ones the document already has.
     *
     * @return array<string, mixed>
     */
    private function customMembers(HasProblemMembers $exception): array
    {
        return array_diff_key($exception->problemMembers(), array_flip(self::STANDARD_MEMBERS));
    }

    /**
     * Build the problem document for a type.
     *
     * @param  array<string, mixed>  $extensions
     * @param  array<string, mixed>  $headers
     */
    private function respond(
        ProblemType $type,
        ?string $detail = null,
        array $extensions = [],
        array $headers = [],
    ): JsonResponse {
        return new JsonResponse(
            [
                'type' => rtrim((string) config('app.url'), '/').'/problemas/'.$type->value,
                'title' => __("problems.{$type->value}.title"),
                'status' => $type->status(),
                'detail' => $detail ?? __("problems.{$type->value}.detail"),
                'instance' => 'urn:uuid:'.Context::get(AssignRequestId::CONTEXT_KEY),
                ...$extensions,
            ],
            $type->status(),
            [...$headers, 'Content-Type' => self::CONTENT_TYPE],
        );
    }
}
