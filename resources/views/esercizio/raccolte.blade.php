@extends('layouts.app')

@section('titolo', 'Raccolte fondi occasionali '.$anno)

@section('contenuto')
    @php($euro = fn (int $c) => \App\Euro::formatta($c))
    <h1>Raccolte fondi occasionali · {{ $anno }}</h1>
    @include('esercizio.avviso-approvato', ['anno' => $anno])
    <p class="nota">Una raccolta per ogni iniziativa dell’area C occasionale: rendiconto e relazione illustrativa (art. 87 c. 6 e art. 48 c. 3 CTS, DM 9/6/2022). Si allegano al PDF di deposito, dopo il Modello D, e si scaricano anche da sole.
        {{ $proposte ? 'Con anche le imputazioni proposte, ancora da confermare.' : 'Solo le imputazioni confermate.' }}</p>
    <p class="nota">
        <a href="{{ route('esercizio.raccolte', $anno - 1) }}" class="link">Esercizio {{ $anno - 1 }}</a> ·
        <a href="{{ route('esercizio.raccolte', $anno + 1) }}" class="link">Esercizio {{ $anno + 1 }}</a> ·
        <a href="{{ route('modello-d', $anno) }}" class="link">Modello D {{ $anno }}</a> ·
        <a href="{{ route('esercizio.approvazione', $anno) }}" class="link">Approvazione {{ $anno }}</a>
        @can('gestire_esercizio') · <a href="{{ route('esercizio.raccolte.righe', $anno) }}" class="link">Righe delle raccolte {{ $anno }}</a> @endcan
    </p>

    <section data-merlo="raccolte-quadratura">
        <h2>Quadratura con la voce C2</h2>
        @if ($r['quadratura']['quadra'])
            <p class="nota">La somma delle raccolte coincide con la voce C2 del Modello D, in entrata e in uscita (solo il denaro: le liberalità non monetarie non sono nel Modello D).</p>
        @else
            <p class="avviso" role="alert">Non coincide, e senza questo non si approva né si deposita:</p>
            <ul class="elenco">@foreach ($r['quadratura']['messaggi'] as $messaggio) <li>{{ $messaggio }}</li> @endforeach</ul>
        @endif
        <div class="tabella-scorrevole">
            <table class="tabella">
                <thead><tr><th scope="col"></th><th scope="col" class="text-right">Somma delle raccolte</th><th scope="col" class="text-right">Voce C2</th><th scope="col" class="text-right">Differenza</th></tr></thead>
                <tbody>
                    @foreach (['entrate' => 'Entrate', 'uscite' => 'Uscite'] as $lato => $titolo)
                        <tr><th scope="row" class="text-left">{{ $titolo }}</th><td class="text-right">{{ $euro($r['quadratura'][$lato]['raccolte']) }}</td><td class="text-right">{{ $euro($r['quadratura'][$lato]['c2']) }}</td><td class="text-right">{{ $euro($r['quadratura'][$lato]['differenza']) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section data-merlo="raccolte-elenco">
        <h2>Le raccolte</h2>
        @forelse ($r['raccolte'] as $raccolta)
            @if ($loop->first) <ul class="schede"> @endif
            <li class="scheda">
                <p><strong><a href="{{ route('esercizio.raccolta', [$anno, $raccolta['iniziativa_id']]) }}" class="link">{{ $raccolta['nome'] }}</a></strong>:
                    proventi {{ $euro($raccolta['proventi']['totale']) }} €, oneri {{ $euro($raccolta['oneri']['totale']) }} €, risultato {{ $euro($raccolta['risultato']) }} €</p>
                @if ($raccolta['motivazione']['richiesta'] && ! $raccolta['motivazione']['presente']) <p class="avviso" role="status">Manca la motivazione.</p> @endif
                @if ($raccolta['senzaRiga']['entrate']['numero'] + $raccolta['senzaRiga']['uscite']['numero'] > 0) <p class="avviso" role="status">Ci sono imputazioni senza riga dello schema.</p> @endif
                @php($mancano = collect($raccolta['relazione']['guida'])->where('fatto', false)->count())
                <p class="nota">Relazione: {{ $mancano === 0 ? 'completa' : $mancano.' '.($mancano === 1 ? 'campo da completare' : 'campi da completare') }}.</p>
            </li>
            @if ($loop->last) </ul> @endif
        @empty
            <p class="vuoto">Nessuna raccolta fondi occasionale nel {{ $anno }}: compare un’iniziativa quando ha imputazioni in area C con un’attività di raccolta occasionale.</p>
        @endforelse
    </section>
@endsection
