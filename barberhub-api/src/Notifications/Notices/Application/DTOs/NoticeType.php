<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Application\DTOs;

/** What a notice tells the customer. Only the reminder can be turned off by the customer (NO-10). */
enum NoticeType: string
{
    case Confirmation = 'confirmation';
    case Offer = 'offer';
    case Cancellation = 'cancellation';
    case Reschedule = 'reschedule';
    case Reminder = 'reminder';
    /** The link to reset the password (RF-41): it protects the account, so the customer cannot turn it off either (NO-10). */
    case PasswordReset = 'password_reset';
    /** The link of the invitation of a collaborator (RF-06): the owner asked for it and there is no account to set a preference in, so it never asks for one. */
    case CollaboratorInvitation = 'collaborator_invitation';
}
