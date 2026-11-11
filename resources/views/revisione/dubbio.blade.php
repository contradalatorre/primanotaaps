{{-- Il ? di un movimento (#20): se è in dubbio lo toglie senza confermare, altrimenti apre una nota dubbio nel dettaglio.
     Aprirla richiede di poter scrivere note; toglierlo no, basta poter confermare (come nella rotta). --}}
@if ($movimento->inDubbio())
    <form method="POST" action="{{ route('movimenti.dubbio.chiudi', $movimento) }}">
        @csrf
        @foreach ($movimento->note()->dubbioAperto()->orderBy('id')->pluck('id') as $id)<input type="hidden" name="dubbi_visti[]" value="{{ $id }}">@endforeach
        <button type="submit" class="spunta-conferma spunta-dubbio" data-dubbio aria-pressed="true" aria-label="Togli il dubbio: {{ $movimento->descrizione }}">?</button>
    </form>
@else
    @can('note')
        <a href="{{ $url(['movimento' => $movimento->id, 'dubbio' => 1]) }}#nuova-nota" class="spunta-conferma spunta-dubbio" data-dubbio aria-pressed="false" aria-label="Segna il dubbio: {{ $movimento->descrizione }}">?</a>
    @endcan
@endif
