<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\OpenApi;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Illuminate\Auth\AuthenticationException;
use Src\Shared\Infrastructure\Http\Problem\ProblemDetailsRenderer;
use Src\Shared\Infrastructure\Http\Problem\ProblemType;

final readonly class ProblemResponsesTransformer implements DocumentTransformer
{
    /** Document the problem responses every operation can answer. */
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $document->components->responses = [];

        $unauthenticated = $this->register($document, ProblemType::Unauthenticated, $this->problemSchema());
        $notFound = $this->register($document, ProblemType::NotFound, $this->problemSchema());
        $forbidden = $this->register($document, ProblemType::Forbidden, $this->problemSchema());

        $invalidData = $this->register($document, ProblemType::InvalidData, $this->problemSchema()->addProperty(
            'errores',
            new ObjectType()->additionalProperties(new ArrayType()->setItems(new StringType)),
        ));

        foreach ($document->paths as $path) {
            foreach ($path->operations as $operation) {
                $requiresToken = array_any($operation->responses ?? [], $this->isAuthenticationFailure(...));
                $operation->responses = array_filter($operation->responses ?? [], $this->isSuccessful(...));

                if ($requiresToken) {
                    $operation->addResponse($unauthenticated);
                }

                if ($operation->hasExtensionProperty('abilities')) {
                    $operation->addResponse($forbidden);
                }

                if ($operation->requestBodyObject !== null) {
                    $operation->addResponse($invalidData);
                }

                if ($this->markPathParametersAsUuid($operation)) {
                    $operation->addResponse($notFound);
                }
            }
        }
    }

    /** Add a reusable problem response to the document components. */
    private function register(OpenApi $document, ProblemType $type, ObjectType $schema): Reference
    {
        $response = Response::make($type->status())
            ->setDescription(__("problems.{$type->value}.title"))
            ->setContent(ProblemDetailsRenderer::CONTENT_TYPE, Schema::fromType($schema));

        return $document->components->add(
            new Reference('responses', $type->name, $document->components),
            $response,
        );
    }

    /** Describe the members shared by every problem document. */
    private function problemSchema(): ObjectType
    {
        return new ObjectType()
            ->addProperty('type', new StringType()->format('uri'))
            ->addProperty('title', new StringType)
            ->addProperty('status', new IntegerType)
            ->addProperty('detail', new StringType)
            ->addProperty('instance', new StringType()->format('uri'))
            ->setRequired(['type', 'title', 'status', 'detail', 'instance']);
    }

    /** Tell whether Scramble inferred the failure of the authentication middleware. */
    private function isAuthenticationFailure(Response|Reference $response): bool
    {
        return $response instanceof Reference && ltrim($response->fullName, '\\') === AuthenticationException::class;
    }

    /** Keep only the success responses inferred from the code. */
    private function isSuccessful(Response|Reference $response): bool
    {
        return $response instanceof Response && (int) $response->code < 400;
    }

    /** Flag path identifiers as UUIDs and tell whether the operation has any. */
    private function markPathParametersAsUuid(Operation $operation): bool
    {
        $pathParameters = array_filter(
            $operation->parameters,
            fn (Parameter|Reference $parameter): bool => $parameter instanceof Parameter && $parameter->in === 'path',
        );

        foreach ($pathParameters as $parameter) {
            $parameter->schema?->type->format('uuid');
        }

        return $pathParameters !== [];
    }
}
