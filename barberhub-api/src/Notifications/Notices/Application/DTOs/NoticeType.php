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
}
