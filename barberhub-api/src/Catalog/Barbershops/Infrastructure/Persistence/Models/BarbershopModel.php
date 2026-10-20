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
 */
#[Table('barbershops')]
#[Fillable(['id', 'name', 'city', 'phone'])]
final class BarbershopModel extends Model
{
    use HasUuids;
}
