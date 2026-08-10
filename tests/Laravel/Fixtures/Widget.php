<?php

declare(strict_types=1);

namespace LatchVector\Sso\Tests\Laravel\Fixtures;

use Illuminate\Database\Eloquent\Model;
use LatchVector\Sso\Laravel\BelongsToTenant;

/**
 * @property int $id
 * @property string $name
 * @property int|null $tenant_id
 */
class Widget extends Model
{
    use BelongsToTenant;

    protected $table = 'widgets';
    public $timestamps = false;
    protected $guarded = [];
}
