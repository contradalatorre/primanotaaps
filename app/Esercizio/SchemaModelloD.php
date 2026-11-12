<?php

namespace App\Esercizio;

/**
 * Lo schema del Modello D (rendiconto per cassa, DM 5 marzo 2020, allegato 1): le righe sono fisse e compaiono sempre,
 * anche a zero. I codici sono quelli di `voci_modello_d` («A1», «E5», «Imposte»); «A1» c'è sia tra le entrate sia tra le
 * uscite, quindi una riga si identifica con il lato: «entrate:A1».
 *
 * shortcut: le etichette delle righe non sono verificate su nessuna fonte scritta: sono scritte dalla memoria dello schema
 * del DM. Asso360 (schemi di bilancio ETS, 2022) conferma solo la struttura (cinque aree, investimenti e disinvestimenti,
 * avanzi, liquidità, prospetto dei figurativi), non le righe; la Gazzetta Ufficiale non era leggibile (#8). Da riscontrare
 * sul testo del DM 5/3/2020 prima del deposito. Meno certe: le righe dei costi figurativi (A, B, E) e le entrate di E.
 * Le righe degli investimenti («I1»…«I4») non hanno ancora tipologie che le usino: restano a zero finché non nascono.
 */
final class SchemaModelloD
{
    public const AREE = [
        'A' => 'Attività di interesse generale',
        'B' => 'Attività diverse',
        'C' => 'Attività di raccolta fondi',
        'D' => 'Attività finanziarie e patrimoniali',
        'E' => 'Attività di supporto generale',
    ];

    private const GESTIONE = ['1' => 'Materie prime, sussidiarie, di consumo e di merci', '2' => 'Servizi', '3' => 'Godimento beni di terzi', '4' => 'Personale'];

    /** @return array<string, array<string, string>> area → codice → etichetta, per lato */
    public static function voci(string $lato): array
    {
        return $lato === 'uscite' ? self::uscite() : self::entrate();
    }

    private static function uscite(): array
    {
        $gestione = fn (string $a, string $quinta) => self::numera($a, self::GESTIONE + ['5' => $quinta]);

        return [
            'A' => $gestione('A', 'Uscite diverse di gestione'),
            'B' => $gestione('B', 'Uscite diverse di gestione'),
            'C' => self::numera('C', ['1' => 'Uscite per raccolte fondi abituali', '2' => 'Uscite per raccolte fondi occasionali', '3' => 'Altre uscite']),
            'D' => self::numera('D', ['1' => 'Su rapporti bancari', '2' => 'Su investimenti finanziari', '3' => 'Su patrimonio edilizio', '4' => 'Su altri beni patrimoniali', '5' => 'Altre uscite']),
            'E' => $gestione('E', 'Altre uscite'),
        ];
    }

    private static function entrate(): array
    {
        return [
            'A' => self::numera('A', [
                '1' => 'Entrate da quote associative e apporti dei fondatori', '2' => 'Entrate dagli associati per attività mutuali',
                '3' => 'Entrate per prestazioni e cessioni ad associati e fondatori', '4' => 'Erogazioni liberali', '5' => 'Entrate del 5 per mille',
                '6' => 'Contributi da soggetti privati', '7' => 'Entrate per prestazioni e cessioni a terzi', '8' => 'Contributi da enti pubblici',
                '9' => 'Entrate da contratti con enti pubblici', '10' => 'Altre entrate',
            ]),
            'B' => self::numera('B', [
                '1' => 'Entrate per prestazioni e cessioni ad associati e fondatori', '2' => 'Contributi da soggetti privati',
                '3' => 'Entrate per prestazioni e cessioni a terzi', '4' => 'Contributi da enti pubblici', '5' => 'Entrate da contratti con enti pubblici', '6' => 'Altre entrate',
            ]),
            'C' => self::numera('C', ['1' => 'Entrate da raccolte fondi abituali', '2' => 'Entrate da raccolte fondi occasionali', '3' => 'Altre entrate']),
            'D' => self::numera('D', ['1' => 'Da rapporti bancari', '2' => 'Da altri investimenti finanziari', '3' => 'Da patrimonio edilizio', '4' => 'Da altri beni patrimoniali', '5' => 'Altre entrate']),
            'E' => self::numera('E', ['1' => 'Entrate da distacco del personale', '2' => 'Altre entrate di supporto generale']),
        ];
    }

    /** La voce è una riga del Modello D su quel lato: area A-E, investimenti, e per le uscite la riga «Imposte». */
    public static function conosce(string $lato, ?string $voce): bool
    {
        if ($voce === null) {
            return false;
        }
        if ($lato === 'uscite' && $voce === 'Imposte') {
            return true;
        }
        foreach ([...array_values(self::voci($lato)), self::investimenti($lato)] as $righe) {
            if (isset($righe[$voce])) {
                return true;
            }
        }

        return false;
    }

    /** Le righe «Uscite da investimenti…» e «Entrate da disinvestimenti…»: codici «I1»…«I4». */
    public static function investimenti(string $lato): array
    {
        return self::numera('I', $lato === 'uscite'
            ? ['1' => 'Investimenti in immobilizzazioni inerenti alle attività di interesse generale', '2' => 'Investimenti in immobilizzazioni inerenti alle attività diverse', '3' => 'Investimenti in attività finanziarie e patrimoniali', '4' => 'Rimborso di finanziamenti per quota capitale e di prestiti']
            : ['1' => 'Disinvestimenti di immobilizzazioni inerenti alle attività di interesse generale', '2' => 'Disinvestimenti di immobilizzazioni inerenti alle attività diverse', '3' => 'Disinvestimenti di attività finanziarie e patrimoniali', '4' => 'Ricevimento di finanziamenti e di prestiti']);
    }

    /** Righe del prospetto dei costi e proventi figurativi: area → etichetta. */
    public static function figurativi(string $tipo): array
    {
        return $tipo === 'costo'
            ? ['A' => 'Da attività di interesse generale', 'B' => 'Da attività diverse', 'E' => 'Di supporto generale']
            : ['A' => 'Da attività di interesse generale', 'B' => 'Da attività diverse'];
    }

    /** @param array<string, string> $righe numero → etichetta */
    private static function numera(string $prefisso, array $righe): array
    {
        $codici = [];
        foreach ($righe as $numero => $etichetta) {
            $codici[$prefisso.$numero] = $etichetta;
        }

        return $codici;
    }
}
