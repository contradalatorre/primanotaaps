@extends('layouts.base')

@section('titolo', 'Accesso')

@section('corpo')
<main class="accesso">
    <h1>Prima nota</h1>
    <p>Contabilità di Contrada La Torre APS.</p>

    @if (session('errore'))
        <p class="avviso" role="alert">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l10 18H2zM12 10v5M12 18h.01"/></svg>
            <span>{{ session('errore') }}</span>
        </p>
    @endif

    <a href="{{ route('accesso.google') }}" class="bottone">Entra con Google</a>
    @if ($dominio)
        <p class="nota">Serve l’account {{ '@'.$dominio }} del Workspace dell’associazione.</p>
    @endif
    <p class="nota">Sei un consulente esterno (commercialista, revisore)? <a href="{{ route('accesso.google', ['esterno' => 1]) }}" class="link">Entra con il tuo account Google</a>: l’Amministratore deve averti già aggiunto.</p>
    @if ($emergenza)
        <p class="nota">Google non funziona o non c’è più nessun Amministratore? <a href="{{ route('accesso.emergenza') }}" class="link">Accesso di emergenza</a>.</p>
    @endif
</main>
@endsection
