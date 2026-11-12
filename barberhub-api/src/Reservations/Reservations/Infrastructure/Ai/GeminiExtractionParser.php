<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Ai;

use JsonException;
use Src\Reservations\Reservations\Application\DTOs\ExtractedDayPart;
use Src\Reservations\Reservations\Application\DTOs\ExtractedReservation;
use Src\Reservations\Reservations\Application\Exceptions\ExtractionInvalidException;

/**
 * Validates what Gemini answered, always, even though the request asks for a JSON schema: the schema is a request and not a
 * guarantee. Anything that is not the expected shape, type or length discards the whole answer (`ExtractionInvalidException`);
 * it is never used in part. The reasons name the field and never carry what the provider said.
 */
final readonly class GeminiExtractionParser
{
    /** Longest name of a service or a barber that is accepted. */
    public const int MAX_NAME_LENGTH = 100;

    /**
     * Read the decoded body of a generateContent response.
     *
     * @param  array<mixed>|null  $body
     *
     * @throws ExtractionInvalidException
     */
    public function parse(?array $body): ExtractedReservation
    {
        if ($body === null) {
            throw ExtractionInvalidException::because('the body is not JSON');
        }

        $fields = $this->fields($this->text($body));

        return new ExtractedReservation(
            service: $this->name($fields, 'service'),
            collaborator: $this->name($fields, 'collaborator'),
            date: $this->date($fields),
            time: $this->time($fields),
            dayPart: $this->dayPart($fields),
        );
    }

    /**
     * The text of the first candidate, which must have finished normally and not be blocked.
     *
     * @param  array<mixed>  $body
     */
    private function text(array $body): string
    {
        $feedback = $body['promptFeedback'] ?? null;

        if (is_array($feedback) && isset($feedback['blockReason'])) {
            throw ExtractionInvalidException::because('the prompt was blocked');
        }

        $candidate = $body['candidates'][0] ?? null;

        if (! is_array($candidate)) {
            throw ExtractionInvalidException::because('there is no candidate');
        }

        if (($candidate['finishReason'] ?? null) !== 'STOP') {
            throw ExtractionInvalidException::because('the candidate did not finish normally');
        }

        $parts = $candidate['content']['parts'] ?? null;

        foreach (is_array($parts) ? $parts : [] as $part) {
            if (is_array($part) && is_string($part['text'] ?? null) && ($part['thought'] ?? false) !== true) {
                return $part['text'];
            }
        }

        throw ExtractionInvalidException::because('the candidate has no text');
    }

    /**
     * The JSON object the text holds.
     *
     * @return array<mixed>
     */
    private function fields(string $text): array
    {
        try {
            $fields = json_decode($text, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw ExtractionInvalidException::because('the text is not JSON', $exception);
        }

        if (! is_array($fields) || ($fields !== [] && array_is_list($fields))) {
            throw ExtractionInvalidException::because('the text is not a JSON object');
        }

        return $fields;
    }

    /**
     * A name as the person said it.
     *
     * @param  array<mixed>  $fields
     */
    private function name(array $fields, string $key): ?string
    {
        $value = $this->optionalString($fields, $key);

        if ($value === null) {
            return null;
        }

        if (mb_strlen($value) > self::MAX_NAME_LENGTH) {
            throw ExtractionInvalidException::because("{$key} is too long");
        }

        return $value;
    }

    /** @param  array<mixed>  $fields */
    private function date(array $fields): ?string
    {
        $value = $this->optionalString($fields, 'date');

        if ($value === null) {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $match) !== 1 || ! checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            throw ExtractionInvalidException::because('date is not a valid YYYY-MM-DD');
        }

        return $value;
    }

    /** @param  array<mixed>  $fields */
    private function time(array $fields): ?string
    {
        $value = $this->optionalString($fields, 'time');

        if ($value === null) {
            return null;
        }

        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            throw ExtractionInvalidException::because('time is not a valid HH:MM');
        }

        return $value;
    }

    /** @param  array<mixed>  $fields */
    private function dayPart(array $fields): ?ExtractedDayPart
    {
        $value = $this->optionalString($fields, 'day_part');

        if ($value === null) {
            return null;
        }

        return ExtractedDayPart::tryFrom($value) ?? throw ExtractionInvalidException::because('day_part is not a known part of the day');
    }

    /**
     * A field that may be absent or null, and otherwise must be a string; a blank text counts as not mentioned.
     *
     * @param  array<mixed>  $fields
     */
    private function optionalString(array $fields, string $key): ?string
    {
        $value = $fields[$key] ?? null;

        if ($value !== null && ! is_string($value)) {
            throw ExtractionInvalidException::because("{$key} is not a string");
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
