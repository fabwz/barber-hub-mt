<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Persistence\Repositories;

use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Identity\Users\Domain\Contracts\UserRepository;
use Src\Identity\Users\Domain\Entities\User;
use Src\Identity\Users\Domain\Exceptions\EmailAlreadyRegisteredException;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Identity\Users\Domain\ValueObjects\UserName;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Src\Shared\Domain\ValueObjects\Phone;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class EloquentUserRepository implements UserRepository
{
    /** Generate a time-ordered UUID for a new user. */
    public function nextId(): UserId
    {
        return new UserId((string) Str::uuid7());
    }

    /** Insert or update the user inside a savepoint and translate a repeated email. */
    public function save(User $user): void
    {
        try {
            DB::transaction(fn () => UserModel::query()->updateOrCreate(
                ['id' => $user->id->value],
                [
                    'name' => $user->name->value,
                    'email' => $user->email->value,
                    'password' => $user->passwordHash,
                    'phone' => $user->phone?->value,
                    'is_active' => $user->active,
                    'terms_accepted_at' => $user->termsAcceptedAt,
                    'privacy_accepted_at' => $user->privacyAcceptedAt,
                ],
            ));
        } catch (UniqueConstraintViolationException) {
            throw EmailAlreadyRegisteredException::create();
        }
    }

    /** Find the account by its normalised email. */
    public function findByEmail(Email $email): ?User
    {
        $model = UserModel::query()->where('email', $email->value)->first();

        return $model === null ? null : $this->toDomain($model);
    }

    /** Rebuild the entity from its stored state. */
    private function toDomain(UserModel $model): User
    {
        return User::reconstitute(
            new UserId($model->id),
            new UserName($model->name),
            new Email($model->email),
            $model->phone === null ? null : new Phone($model->phone),
            $model->password,
            $model->is_active,
            $this->instant($model->terms_accepted_at),
            $this->instant($model->privacy_accepted_at),
        );
    }

    /** Convert a stored timestamp to an immutable instant. */
    private function instant(?CarbonInterface $value): ?DateTimeImmutable
    {
        return $value?->toDateTimeImmutable();
    }
}
