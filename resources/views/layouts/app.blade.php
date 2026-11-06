@extends('layouts.base')

@section('corpo')
<div class="shell">
    <aside class="menu" aria-label="Menu principale">
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
            @can('avviare-migrazioni')
                <a href="{{ route('migrazioni') }}" class="voce" @if (request()->routeIs('migrazioni*')) aria-current="page" @endif>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/></svg>
                    <span class="voce-testo">Migrazioni</span>
                </a>
            @endcan
        </nav>
        <form method="POST" action="{{ route('esci') }}" class="menu-utente">
            @csrf
            <span class="utente-nome">{{ auth()->user()->name }}</span>
            <button type="submit" class="voce">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10"/></svg>
                <span class="voce-testo">Esci</span>
            </button>
        </form>
    </aside>
    <main class="contenuto">
        @yield('contenuto')
    </main>
</div>
@endsection
