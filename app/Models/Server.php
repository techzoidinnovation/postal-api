<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Server extends Model
{
    use HasUuids;
    protected $connection = 'postal_mysql';

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class, 'owner_id');
    }
}
