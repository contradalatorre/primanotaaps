@extends('layouts.base')

@section('titolo', 'Accesso di emergenza')

@section('corpo')
<main class="accesso">
    <h1>Accesso di emergenza</h1>
    <p>Solo se Google non funziona o non è rimasto nessun Amministratore. Ogni uso viene registrato e comunicato agli Amministratori.</p>

    @if (session('errore'))
        <p class="avviso" role="alert">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l10 18H2zM12 10v5M12 18h.01"/></svg>
            <span>{{ session('errore') }}</span>
        </p>
    @endif

    <form method="POST" action="{{ route('accesso.emergenza') }}" class="modulo">
        @csrf
        <label>Password <input type="password" name="password" required autocomplete="off"></label>
        <label>Codice dell’app di autenticazione <input type="text" name="codice" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"></label>
        <button type="submit" class="bottone">Entra</button>
    </form>
    <p class="nota"><a href="{{ route('accesso') }}" class="link">Torna all’accesso con Google</a></p>
</main>
@endsection
