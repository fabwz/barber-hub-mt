<?php

declare(strict_types=1);

namespace Src\Shared\Domain\ValueObjects;

use DateTimeImmutable;

/**
 * One entry of the technical record (SE-04): who did what to which subject, when, and what changed. The changes are built from
 * the state before and after, keeping only the fields the action allows; so a password, a token, a contact detail or any other
 * field nobody listed cannot end up in the record.
 */
final readonly class AuditEntry
{
    /**
     * @param  array<string, array{previous: bool|int|string|null, new: bool|int|string|null}|array{changed: true}>  $changes
     */
    private function __construct(
        public ?UserId $actor,
        public AuditAction $action,
        public string $subjectType,
        public string $subjectId,
        public array $changes,
        public DateTimeImmutable $at,
    ) {}

    /**
     * Build the entry from the state before and after the change, or return null when no allowed field changed (nothing to
     * record). A value that is not a boolean, an integer, a string or null is never kept: the fields of the allow lists hold plain values.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  ?UserId  $actor  The person who acted, or null when the system did (a task with no session).
     */
    public static function of(?UserId $actor, AuditAction $action, string $subjectType, string $subjectId, array $before, array $after, DateTimeImmutable $at): ?self
    {
        $changes = [];

        foreach ($action->recordedFields() as $field) {
            $previous = self::plain($before[$field] ?? null);
            $new = self::plain($after[$field] ?? null);

            if ($previous !== $new) {
                $changes[$field] = ['previous' => $previous, 'new' => $new];
            }
        }

        foreach ($action->maskedFields() as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $changes[$field] = ['changed' => true];
            }
        }

        return $changes === [] ? null : new self($actor, $action, $subjectType, $subjectId, $changes, $at);
    }

    /**
     * The same as `of()` for a change the system made on its own, with no person behind it (PL-12).
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public static function bySystem(AuditAction $action, string $subjectType, string $subjectId, array $before, array $after, DateTimeImmutable $at): ?self
    {
        return self::of(null, $action, $subjectType, $subjectId, $before, $after, $at);
    }

    /** Keep a boolean, an integer or a string as it is and drop anything else. */
    private static function plain(mixed $value): bool|int|string|null
    {
        return is_bool($value) || is_int($value) || is_string($value) ? $value : null;
    }
}
