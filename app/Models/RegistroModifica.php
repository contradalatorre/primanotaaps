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
            'oggetto_tipo' => match (true) {
                $oggetto instanceof Conto => 'conto',
                $oggetto instanceof Movimento => 'movimento',
                $oggetto instanceof Tipologia => 'tipologia',
                $oggetto instanceof Attivita => 'attivita',
                $oggetto instanceof Iniziativa => 'iniziativa',
                $oggetto instanceof Tag => 'tag',
                $oggetto instanceof ImportEstratto => 'import',
                $oggetto instanceof ManualeSezione => 'manuale',
                $oggetto instanceof Nota => 'nota',
                $oggetto instanceof Regola => 'regola',
                $oggetto instanceof ConversioneCausale => 'conversione',
                $oggetto instanceof ImpostazioniAssociazione => 'impostazioni',
                $oggetto instanceof DatiEsercizio => 'dati_esercizio',
                $oggetto instanceof RigaFigurativa => 'riga_figurativa',
                $oggetto instanceof EsercizioStorico => 'esercizio_storico',
                default => throw new LogicException('Oggetto non previsto nel registro: '.$oggetto::class),
            },
            'oggetto_id' => $oggetto->getKey(),
            'azione' => $azione,
            'prima' => $prima,
            'dopo' => $dopo,
            'motivazione' => $motivazione,
        ]);
    }
}
