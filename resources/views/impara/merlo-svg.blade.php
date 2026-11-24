{{-- Merlo: un merlo nero con becco e contorno dell'occhio gialli, solo SVG in linea. La posa (saluta, indica, pensa, festeggia, preoccupato) è un attributo: le animazioni stanno in merlo.css. --}}
<svg class="merlo-svg" data-pose="{{ $pose }}" viewBox="0 0 120 120" role="img" aria-label="Merlo, {{ $pose }}">
    <g class="m-g m-tilt"><g class="m-g m-bird">
        <path d="M40 74 8 90l6 8 32-14z" fill="#111"/>
        <path d="M54 93v11m-5 0h10M68 93v11m-5 0h10" stroke="#ffd900" stroke-width="3" stroke-linecap="round" fill="none"/>
        <ellipse cx="60" cy="72" rx="30" ry="25" fill="#111"/>
        <g class="m-g m-head">
            <circle cx="74" cy="44" r="19" fill="#111"/>
            <path d="M89 40 111 47 89 54z" fill="#ffd900" stroke="#111" stroke-width="1.5" stroke-linejoin="round"/>
            <path d="M89 47h14" stroke="#111" stroke-width="1.2"/>
            <circle cx="79" cy="39" r="6.5" fill="#ffd900"/>
            <g class="m-g m-eye"><circle cx="80" cy="39" r="3.4" fill="#111"/><circle cx="81.2" cy="37.8" r="1.1" fill="#fff"/></g>
            <path class="m-brow" d="M71 28.5 85 32" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/>
        </g>
        <path class="m-g m-wing" d="M42 66c8-11 30-10 35 3-6 13-26 15-37 9z" fill="#2c2c2a" stroke="#fff" stroke-opacity=".25"/>
    </g></g>
    <g class="m-dots" fill="#fff" stroke="#111" stroke-width="1.5"><circle cx="97" cy="24" r="2.6"/><circle cx="104" cy="14" r="3.4"/><circle cx="113" cy="3" r="4.4"/></g>
    <path class="m-drop" d="M52 20c4 6 4 10 0 11-4-1-4-5 0-11z" fill="#fff" stroke="#111" stroke-width="1.5"/>
    <g class="m-confetti" stroke="#111" stroke-width=".8"><rect x="18" y="10" width="6" height="3" fill="#ffd900"/><rect x="34" y="2" width="5" height="3" fill="#fff"/><rect x="96" y="6" width="6" height="3" fill="#ffd900"/><rect x="108" y="20" width="5" height="3" fill="#111"/><rect x="8" y="30" width="5" height="3" fill="#fff"/><rect x="58" y="0" width="6" height="3" fill="#ffd900"/></g>
</svg>
