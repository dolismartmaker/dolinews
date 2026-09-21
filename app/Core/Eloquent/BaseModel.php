<?php

declare(strict_types=1);

namespace App\Core\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Application base model with sane, project-wide defaults.
 *
 * Guards against silent mass-assignment surprises by disabling the legacy
 * "guarded" behaviour: subclasses declare an explicit $fillable list.
 */
abstract class BaseModel extends Model
{
    /**
     * Prevent silently ignoring unknown attributes on mass assignment.
     *
     * @var list<string>
     */
    protected $guarded = [];
}
