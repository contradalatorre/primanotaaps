{{-- Testo semplice: `{!! !!}`, perché `{{ }}` trasformerebbe gli apostrofi in entità. Mai descrizione o causale: solo importo, data, conto e link. --}}
Queste metà di giroconto aspettano ancora l'altra metà:

@foreach ($orfane as $proposta)
@php($movimento = $proposta['movimento'])
- {!! $movimento->verso !!} di {!! \App\Euro::formatta($movimento->importo) !!} euro, {!! $movimento->data->format('d/m/Y') !!}, {!! $movimento->conto->nome !!}
  {!! \App\Giroconti::urlPerAbbinare($movimento) !!}
@endforeach
