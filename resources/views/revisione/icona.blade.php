{{-- Le icone dicono lo stato insieme al testo: mai solo il colore. --}}
<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch ($nome)
        @case('ok') <path d="M4 12.5l5 5L20 6.5"/> @break
        @case('proposto') <path d="M4 20h4L19 9l-4-4L4 16z"/> @break
        @case('attenzione') <path d="M12 3l10 18H2zM12 10v5M12 18h.01"/> @break
        @default <circle cx="12" cy="12" r="8"/>
    @endswitch
</svg>
