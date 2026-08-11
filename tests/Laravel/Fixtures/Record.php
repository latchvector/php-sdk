<?php

declare(strict_types=1);

namespace LatchVector\Sso\Tests\Laravel\Fixtures;

use Illuminate\Database\Eloquent\Model;
use LatchVector\Sso\Laravel\BelongsToTenant;

/**
 * Relies on the DEFAULT scope (subtree) — no `$tenantScope` declared — to prove
 * org-tree isolation is on out of the box.
 *
 * @property int $id
 * @property string $name
 * @property int|null $tenant_id
 * @property int|null $org_id
 * @property string|null $org_path
 */
class Record extends Model
{
    use BelongsToTenant;

    protected $table = 'records';
    public $timestamps = false;
    protected $guarded = [];
}
