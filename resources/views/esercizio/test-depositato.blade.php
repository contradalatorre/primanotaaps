{{-- I test di un esercizio depositato (#60): solo gli esiti salvati nell'istantanea del deposito, senza ricalcolo. Vuole $anno, $esiti (`Differenze::impronta`, gruppo `esiti`). --}}
@extends('layouts.app')

@section('titolo', 'Test dell’esercizio '.$anno)

@section('contenuto')
    <h1>Test dell’esercizio {{ $anno }}</h1>
    @include('esercizio.stato-prospetto', ['merlo' => 'test-depositato', 'proposte' => false, 'aggiornatoAl' => now()])
    <p class="nota">
        <a href="{{ route('modello-d', $anno) }}" class="link">Modello D {{ $anno }}</a> ·
        <a href="{{ route('esercizio.approvazione', $anno) }}" class="link">Approvazione, deposito e versioni</a>
    </p>
    <section data-merlo="test-esiti-depositati">
        <h2>Esiti al deposito</h2>
        <div class="tabella-scorrevole">
            <table class="tabella">
                <thead><tr><th>Test</th><th>Esito</th></tr></thead>
                <tbody>
                    @foreach ($esiti as $esito)
                        <tr><td>{{ $esito['etichetta'] }}</td><td>{{ \App\Esercizio\Secondarieta::ETICHETTE[$esito['esito']] ?? $esito['esito'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endsection
