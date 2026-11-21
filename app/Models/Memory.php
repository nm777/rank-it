<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Memory extends Model
{
    use HasUuids;

    protected $fillable = ['wording', 'experience_date'];
}
