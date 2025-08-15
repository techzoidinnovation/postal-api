<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Server extends Model
{
    use HasUuids;
    protected $connection = 'postal_mysql';
}
