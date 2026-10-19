<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use SensitiveParameter;
use Src\Identity\Users\Domain\ValueObjects\PlainPassword;

final class MeetsPasswordPolicy implements ValidationRule
{
    /** Add one error for each requirement of SE-07 the password misses, so the form can list them all. */
    public function validate(string $attribute, #[SensitiveParameter] mixed $value, Closure $fail): void
    {
        foreach (PlainPassword::missingRequirements((string) $value) as $key) {
            $fail($key)->translate(['min' => PlainPassword::MIN_LENGTH]);
        }
    }
}
