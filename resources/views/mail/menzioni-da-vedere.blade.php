{{-- Testo semplice: `{!! !!}`, perché `{{ }}` trasformerebbe gli apostrofi in entità. Il testo della nota non va mai qui. --}}
{!! $immediata ? 'Sei stato chiamato in una nota:' : 'Hai menzioni ancora da vedere:' !!}

@foreach ($menzioni as $menzione)
@php($movimento = $menzione->nota->oggetto)
- {!! $menzione->nota->autore->name !!}, {!! $menzione->nota->created_at->timezone(config('app.timezone'))->format('d/m/Y') !!}: {!! $movimento->verso !!} di {!! \App\Euro::formatta($movimento->importo) !!} euro, {!! \Illuminate\Support\Str::limit($movimento->descrizione, 60) !!}
  {!! route('movimenti.note', $movimento) !!}
@endforeach

Per non riceverle più, o per averle subito, cambia la preferenza: {!! route('preferenze') !!}
