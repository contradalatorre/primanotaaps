<?php

namespace App\Models;

use App\Permesso;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nome', 'permessi'])]
class Ruolo extends Model
{
    protected $table = 'ruoli';

    protected function casts(): array
    {
        return ['permessi' => 'array'];
    }

    public function assegnazioni(): HasMany
    {
        return $this->hasMany(Assegnazione::class);
    }

    public function haPermesso(Permesso $permesso): bool
    {
        return in_array($permesso->value, $this->permessi, true);
    }
}
