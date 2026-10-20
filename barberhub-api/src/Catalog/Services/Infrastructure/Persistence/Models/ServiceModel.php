<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $barbershop_id
 * @property string $name
 * @property int $duration_minutes
 * @property int $price
 * @property bool $is_active
 */
#[Table('services')]
#[Fillable(['id', 'barbershop_id', 'name', 'duration_minutes', 'price', 'is_active'])]
final class ServiceModel extends Model
{
    use HasUuids;

    /**
     * Cast the numeric and boolean columns to native types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'price' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
