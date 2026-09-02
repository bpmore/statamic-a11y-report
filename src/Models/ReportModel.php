<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Models;

use Bpmore\A11yReport\Storage\ReportDatabase;
use Illuminate\Database\Eloquent\Model;

/**
 * Every report table lives on the one connection `ReportDatabase` names, which
 * is read when a query is made rather than when the class is loaded, so a test
 * or a site can change the setting and have every model follow.
 */
abstract class ReportModel extends Model
{
    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return ReportDatabase::connectionName();
    }
}
