<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Una riga del registro degli accessi (glossario). Si aggiungono righe, non si modificano. */
class RegistroAccesso extends Model
{
    protected $table = 'registro_accessi';

    public const UPDATED_AT = null;

    protected $guarded = [];

    public static function registra(string $evento, Request $request, ?User $utente = null): self
    {
        return static::create([
            'user_id' => $utente?->id,
            'evento' => $evento,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
