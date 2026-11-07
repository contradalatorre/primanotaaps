<?php

namespace App;

/** Le cinque sezioni del Modello D (glossario: Area). Le fissa il DM 5 marzo 2020, quindi non stanno in una tabella. */
enum Area: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';
    case E = 'E';

    public function etichetta(): string
    {
        return match ($this) {
            self::A => 'A · Attività di interesse generale',
            self::B => 'B · Attività diverse',
            self::C => 'C · Raccolta fondi',
            self::D => 'D · Attività finanziarie e patrimoniali',
            self::E => 'E · Supporto generale',
        };
    }
}
