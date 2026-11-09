<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $name
 * @property string $city
 * @property string $phone
 * @property string $public_link
 * @property string|null $payment_gateway
 * @property string $payment_mode
 * @property int|null $deposit_percentage
 * @property int $cancellation_deadline_hours
 * @property string|null $address
 * @property string|null $contact_email
 * @property string|null $whatsapp_url
 * @property string|null $instagram_url
 * @property string|null $facebook_url
 * @property string|null $tiktok_url
 */
#[Table('barbershops')]
#[Fillable(['id', 'name', 'city', 'phone', 'public_link', 'payment_gateway', 'payment_mode', 'deposit_percentage', 'cancellation_deadline_hours', 'address', 'contact_email', 'whatsapp_url', 'instagram_url', 'facebook_url', 'tiktok_url'])]
final class BarbershopModel extends Model
{
    use HasUuids;
}
