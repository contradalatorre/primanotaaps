<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Una riga del registro delle modifiche (glossario): chi, quando, prima, dopo. Si aggiungono righe e basta:
 * il modello rifiuta update e delete. Non va confuso con il registro degli accessi.
 */
class RegistroModifica extends Model
{
    protected $table = 'registro_modifiche';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['prima' => 'array', 'dopo' => 'array'];
    }

    protected static function booted(): void
    {
        $rifiuta = fn () => throw new LogicException('Il registro delle modifiche è in sola aggiunta.');
        static::updating($rifiuta);
        static::deleting($rifiuta);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function registra(User $utente, Model $oggetto, string $azione, ?array $prima, ?array $dopo, ?string $motivazione = null): self
    {
        return static::create([
            'user_id' => $utente->id,
            'oggetto_tipo' => $oggetto instanceof Conto ? 'conto' : 'movimento',
            'oggetto_id' => $oggetto->getKey(),
            'azione' => $azione,
            'prima' => $prima,
            'dopo' => $dopo,
            'motivazione' => $motivazione,
        ]);
    }
}
