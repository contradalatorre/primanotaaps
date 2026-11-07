{{-- Verde confermato, blu proposto, grigio da classificare: mai il rosso. --}}
@if ($movimento->confermato())
    <span class="pill pill-confermato">@include('revisione.icona', ['nome' => 'ok'])Confermato</span>
@elseif ($movimento->stato === 'proposto')
    <span class="pill pill-proposto">@include('revisione.icona', ['nome' => 'proposto'])Proposto</span>
@else
    <span class="pill pill-da-classificare">@include('revisione.icona', ['nome' => 'vuoto'])Da classificare</span>
@endif
