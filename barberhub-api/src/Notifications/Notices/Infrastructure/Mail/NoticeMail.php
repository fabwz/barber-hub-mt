<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Infrastructure\Mail;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Src\Notifications\Notices\Application\DTOs\Notice;
use Src\Notifications\Notices\Application\DTOs\NoticeType;

/**
 * The email of a notice: one template for the five kinds, whose texts live in `lang/es/notices.php`. It is built and sent inside the task
 * of the queue that carries the notice, so the queue is what retries it.
 */
final class NoticeMail extends Mailable
{
    /** Header that carries the idempotency key to the transport; the transport removes it before the message leaves. */
    public const string KEY_HEADER = 'X-Notice-Idempotency-Key';

    public function __construct(public readonly Notice $notice) {}

    /** The subject, by kind of notice. */
    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->phrase('subject'));
    }

    /** The HTML and the plain text, with the same data. */
    public function content(): Content
    {
        return new Content(view: 'mail.notice', text: 'mail.notice-text', with: $this->data());
    }

    /** Carry the idempotency key, if the notice has one. */
    public function headers(): Headers
    {
        return new Headers(text: $this->notice->idempotencyKey === null ? [] : [self::KEY_HEADER => $this->notice->idempotencyKey]);
    }

    /**
     * The texts and the data the templates show.
     *
     * @return array<string, mixed>
     */
    private function data(): array
    {
        return [
            'greeting' => $this->notice->recipient->name === '' ? __('notices.greeting_anonymous') : __('notices.greeting', ['name' => $this->notice->recipient->name]),
            'lines' => $this->lines(),
            'footer' => $this->footer(),
            'actionUrl' => $this->notice->actionUrl,
            'actionLabel' => $this->notice->actionUrl === null ? null : __("notices.{$this->key()}.action_label"),
            'barbershop' => $this->notice->branding->barbershopName,
            'logoUrl' => $this->notice->branding->logoUrl,
        ];
    }

    /**
     * The sentences of the body, in order.
     *
     * @return list<string>
     */
    private function lines(): array
    {
        $lines = [$this->phrase('line')];

        $body = match ($this->notice->type) {
            NoticeType::Offer => [...$lines, __('notices.offer.deadline', ['deadline' => $this->time($this->notice->expiresAt)]), __('notices.offer.action')],
            NoticeType::Cancellation => [...$lines, __('notices.cancellation.apology')],
            NoticeType::PasswordReset => [...$lines, __('notices.password_reset.validity', ['minutes' => $this->validityMinutes()]), __('notices.password_reset.ignore')],
            NoticeType::CollaboratorInvitation => [...$lines, __('notices.collaborator_invitation.validity', ['days' => $this->validityDays()]), __('notices.collaborator_invitation.ignore')],
            NoticeType::Reschedule => [...$lines, __('notices.reschedule.before', ['previous' => $this->moment($this->notice->previousStartsAt)]), __('notices.reschedule.after', ['when' => $this->moment($this->notice->startsAt)])],
            default => $lines,
        };

        return array_map($this->closeSentence(...), $body);
    }

    /** The sign-off: a notice of a barbershop is sent on its behalf; one of the platform is not. */
    private function footer(): string
    {
        return $this->notice->type === NoticeType::PasswordReset
            ? __('notices.password_reset.footer')
            : __('notices.footer', ['barbershop' => $this->notice->branding->barbershopName]);
    }

    /** Minutes the link of a notice is valid, from the moment it is sent to the moment it expires. */
    private function validityMinutes(): int
    {
        return $this->notice->expiresAt === null ? 0 : (int) round(($this->notice->expiresAt->getTimestamp() - $this->notice->startsAt->getTimestamp()) / 60);
    }

    /** Days the link of a notice is valid, from the moment it is sent to the moment it expires. */
    private function validityDays(): int
    {
        return $this->notice->expiresAt === null ? 0 : (int) round(($this->notice->expiresAt->getTimestamp() - $this->notice->startsAt->getTimestamp()) / 86400);
    }

    /** The period of «a. m.» or «p. m.» followed by the period of the sentence is one point, not two; the texts have no ellipsis. */
    private function closeSentence(string $sentence): string
    {
        return str_replace('..', '.', $sentence);
    }

    /** A text of the kind of notice, with the parameters of the notice. */
    private function phrase(string $part): string
    {
        return __("notices.{$this->key()}.{$part}", [
            'barbershop' => $this->notice->branding->barbershopName,
            'service' => $this->notice->serviceName,
            'collaborator' => $this->notice->collaboratorName,
            'when' => $this->moment($this->notice->startsAt),
        ]);
    }

    /** The key of the texts: the kind, and for a reminder also how long before it is. */
    private function key(): string
    {
        return $this->notice->type === NoticeType::Reminder && $this->notice->reminder !== null
            ? "reminder.{$this->notice->reminder->value}"
            : $this->notice->type->value;
    }

    /** A day and an hour in the time zone of the application, in Spanish: «martes 3 de marzo, 3:00 p. m.». */
    private function moment(?DateTimeImmutable $at): string
    {
        return $at === null ? '' : $this->local($at)->isoFormat('dddd D [de] MMMM, h:mm a');
    }

    /** An hour in the time zone of the application: «3:15 p. m.». */
    private function time(?DateTimeImmutable $at): string
    {
        return $at === null ? '' : $this->local($at)->isoFormat('h:mm a');
    }

    /** The instant seen in the time zone of the application, with Spanish names. */
    private function local(DateTimeImmutable $at): CarbonImmutable
    {
        $local = CarbonImmutable::instance($at)->setTimezone(config()->string('app.timezone'));
        $localized = $local->locale(app()->getLocale());

        return $localized instanceof CarbonImmutable ? $localized : $local;
    }
}
