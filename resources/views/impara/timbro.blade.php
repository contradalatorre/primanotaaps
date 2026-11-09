{{-- Il timbro di una missione (la torre su fondo giallo) o il sigillo di un percorso (doppio anello). Solo SVG. --}}
<svg class="timbro {{ $sigillo ? 'sigillo' : '' }}" width="96" height="96" viewBox="0 0 132 132" role="img" aria-label="{{ $sigillo ? '' : 'Timbro ' }}{{ $etichetta }}">
    <circle cx="66" cy="66" r="62" fill="none" stroke="#111" stroke-width="3"/>
    @if ($sigillo)<circle cx="66" cy="66" r="58" fill="none" stroke="#111" stroke-width="1" stroke-dasharray="3 3"/>@endif
    <circle cx="66" cy="66" r="54" fill="#ffd900" stroke="#111" stroke-width="1.5"/>
    <path d="M52 92V58h-4v-10h6v5h5v-5h14v5h5v-5h6v10h-4v34z" fill="#111"/>
    <rect x="62" y="74" width="8" height="18" rx="4" fill="#ffd900"/>
    <path id="arco-{{ $id }}" d="M24 66a42 42 0 0 1 84 0" fill="none"/>
    <text font-family="ui-monospace, monospace" font-size="10" font-weight="600" letter-spacing="1.2" fill="#111"><textPath href="#arco-{{ $id }}" startOffset="50%" text-anchor="middle">{{ mb_strtoupper(mb_substr($etichetta, 0, 22)) }}</textPath></text>
</svg>
