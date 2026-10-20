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
 */
#[Table('barbershops')]
#[Fillable(['id', 'name', 'city', 'phone', 'public_link', 'payment_gateway'])]
final class BarbershopModel extends Model
{
    use HasUuids;
}
