<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

#[Fillable(['name', 'email', 'google_id'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    protected $hidden = ['google_refresh_token'];

    protected function casts(): array
    {
        return [
            'google_refresh_token' => 'encrypted',
            'google_verificato_il' => 'datetime',
        ];
    }
}
