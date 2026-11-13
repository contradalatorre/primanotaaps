{{-- Il rimando alle forzature dell'esercizio, con quante sono da rivedere (#52). --}}
@php($forzate = \App\Forzature::dell($anno))
@if ($forzate->isNotEmpty())
    @php($daRivedere = $forzate->filter(fn ($i) => $i->forzaturaDaRivedere() !== [])->count())
    <p data-merlo="forzature-link"><a href="{{ route('esercizio.forzature', $anno) }}" class="link">{{ $forzate->count() }} {{ $forzate->count() === 1 ? 'forzatura' : 'forzature' }} della natura commerciale</a>@if ($daRivedere > 0) · <span class="etichetta">{{ $daRivedere }} da rivedere</span>@endif</p>
@endif
