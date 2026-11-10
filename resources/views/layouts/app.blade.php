@extends('layouts.base')

@section('corpo')
@include('impara.merlo')
<div class="shell">
    <aside class="menu" aria-label="Menu principale" data-merlo="menu">
        <div class="menu-testa">
            <span class="marchio">Prima nota</span>
            <button type="button" class="menu-comprimi" data-menu-toggle aria-expanded="true" aria-label="Comprimi il menu">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
            </button>
        </div>
        <nav class="menu-voci">
            <a href="{{ route('home') }}" class="voce" @if (request()->routeIs('home')) aria-current="page" @endif>
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 11l9-8 9 8M5 10v10h14V10"/></svg>
                <span class="voce-testo">Home</span>
            </a>
            @can('vedere')
                <a href="{{ route('conti') }}" class="voce" @if (request()->routeIs('conti*')) aria-current="page" @endif>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 7h18v12H3zM3 7l3-4h12l3 4M16 13h2"/></svg>
                    <span class="voce-testo">Conti</span>
                </a>
                <a href="{{ route('movimenti') }}" class="voce" @if (request()->routeIs('movimenti*')) aria-current="page" @endif>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M7 4v16M7 20l-3-3M7 20l3-3M17 20V4M17 4l-3 3M17 4l3 3"/></svg>
                    <span class="voce-testo">Movimenti</span>
                </a>
                <a href="{{ route('classificazione') }}" class="voce" @if (request()->routeIs('classificazione*')) aria-current="page" @endif>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 12l9-9h9v9l-9 9zM16 8h.01"/></svg>
                    <span class="voce-testo">Classificazione</span>
                </a>
                <a href="{{ route('manuale') }}" class="voce" @if (request()->routeIs('manuale*')) aria-current="page" @endif>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 4h7a3 3 0 013 3v13a2 2 0 00-2-2H4zM20 4h-6M20 4v14h-6"/></svg>
                    <span class="voce-testo">Manuale</span>
                </a>
            @endcan
            <a href="{{ route('impara') }}" class="voce" data-merlo="menu-impara" @if (request()->routeIs('impara*')) aria-current="page" @endif>
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M7 21V9H5V5h3v2h2V5h4v2h2V5h3v4h-2v12zM12 21v-5"/></svg>
                <span class="voce-testo">Impara</span>
            </a>
            @can('gestire_regole')
                <a href="{{ route('regole') }}" class="voce" @if (request()->routeIs('regole*')) aria-current="page" @endif>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h16M4 12h10M4 18h6M18 14l3 3-3 3"/></svg>
                    <span class="voce-testo">Regole</span>
                </a>
            @endcan
            @can('vedere_registro_modifiche')
                <a href="{{ route('registro') }}" class="voce" @if (request()->routeIs('registro*')) aria-current="page" @endif>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 3h12v18H6zM9 8h6M9 12h6M9 16h4"/></svg>
                    <span class="voce-testo">Registro</span>
                </a>
            @endcan
            @can('gestire_impostazioni')
                <a href="{{ route('sistema') }}" class="voce" @if (request()->routeIs('sistema*')) aria-current="page" @endif>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 12h4l3-8 4 16 3-8h4"/></svg>
                    <span class="voce-testo">Sistema</span>
                </a>
            @endcan
            @can('gestire_utenti')
                <a href="{{ route('utenti') }}" class="voce" @if (request()->routeIs('utenti*')) aria-current="page" @endif>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M16 20v-2a4 4 0 00-4-4H7a4 4 0 00-4 4v2M9.5 10a3.5 3.5 0 100-7 3.5 3.5 0 000 7zM21 20v-2a4 4 0 00-3-3.9M16 3.1a3.5 3.5 0 010 6.8"/></svg>
                    <span class="voce-testo">Utenti</span>
                </a>
                <a href="{{ route('ruoli') }}" class="voce" @if (request()->routeIs('ruoli*')) aria-current="page" @endif>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/></svg>
                    <span class="voce-testo">Ruoli</span>
                </a>
                <a href="{{ route('migrazioni') }}" class="voce" @if (request()->routeIs('migrazioni*')) aria-current="page" @endif>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/></svg>
                    <span class="voce-testo">Migrazioni</span>
                </a>
            @endcan
        </nav>
        <form method="POST" action="{{ route('esci') }}" class="menu-utente">
            @csrf
            <span class="utente-nome">{{ auth()->user()->name }}</span>
            <a href="{{ route('preferenze') }}" class="voce" @if (request()->routeIs('preferenze*')) aria-current="page" @endif>
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h10M18 6h2M4 12h2M10 12h10M4 18h12M20 18h0M14 4v4M8 10v4M18 16v4"/></svg>
                <span class="voce-testo">Preferenze</span>
            </a>
            <button type="submit" class="voce">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10"/></svg>
                <span class="voce-testo">Esci</span>
            </button>
        </form>
    </aside>
    <main class="contenuto">
        @can('gestire_utenti')
            @php($stabili = \App\Models\User::amministratoriAttivi(true)->count())
            @if ($stabili <= 1)
                <p class="avviso" role="status">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l10 18H2zM12 10v5M12 18h.01"/></svg>
                    <span>{{ $stabili === 1 ? 'C’è un solo Amministratore senza scadenza: se perde l’accesso' : 'Nessun Amministratore è senza scadenza: quando le loro assegnazioni scadranno' }} nessuno potrà più gestire utenti e ruoli. Assegna il ruolo, senza scadenza, a un’altra persona.</span>
                </p>
            @endif
            @php($senzaConti = \App\Models\User::senzaConti()->orderBy('name')->pluck('name'))
            @if ($senzaConti->isNotEmpty())
                <p class="avviso" role="status">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l10 18H2zM12 10v5M12 18h.01"/></svg>
                    <span>Restano senza conti, perché i loro conti si sono chiusi, e possono solo leggere: {{ $senzaConti->join(', ') }}. Dalla pagina Utenti puoi dare loro un conto nuovo.</span>
                </p>
            @endif
        @endcan
        @if (session('avviso'))
            <p class="avviso" role="status">{{ session('avviso') }}</p>
        @endif
        @if (session('ok'))
            <p class="avviso" role="status">{{ session('ok') }}</p>
        @endif
        @if ($errors->any())
            <div class="avviso" role="alert">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l10 18H2zM12 10v5M12 18h.01"/></svg>
                <ul>@foreach ($errors->all() as $errore)<li>{{ $errore }}</li>@endforeach</ul>
            </div>
        @endif
        @yield('contenuto')
    </main>
</div>
@endsection
