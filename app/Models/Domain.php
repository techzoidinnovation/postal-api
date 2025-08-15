<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Domain extends Model
{
    use HasUuids;
    protected $connection = 'postal_mysql';
    protected $guarded = [];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }
}
