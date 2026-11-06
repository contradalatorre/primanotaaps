<?php

namespace App;

/** L'elenco fisso dei permessi (glossario: Permesso). Le righe sono quelle della tabella di #16. */
enum Permesso: string
{
    case Vedere = 'vedere';
    case InserireMovimentiCassa = 'inserire_movimenti_cassa';
    case ModificareMovimentiPropri = 'modificare_movimenti_propri';
    case ProporreClassificazioni = 'proporre_classificazioni';
    case ImportareEConfermare = 'importare_e_confermare';
    case EliminareMovimentoManuale = 'eliminare_movimento_manuale';
    case GestireRegole = 'gestire_regole';
    case ChiedereSuggerimentiAi = 'chiedere_suggerimenti_ai';
    case RiclassificareInBlocco = 'riclassificare_in_blocco';
    case GestireEsercizio = 'gestire_esercizio';
    case RiaprireEsercizio = 'riaprire_esercizio';
    case VedereRegistroModifiche = 'vedere_registro_modifiche';
    case VedereStoriaMovimento = 'vedere_storia_movimento';
    case Note = 'note';
    case OscurareNota = 'oscurare_nota';
    case GestireUtenti = 'gestire_utenti';
    case GestireImpostazioni = 'gestire_impostazioni';
    case GestirePassaggioConsegne = 'gestire_passaggio_consegne';

    public function etichetta(): string
    {
        return match ($this) {
            self::Vedere => 'Vedere movimenti, Modello D, test, iniziative',
            self::InserireMovimentiCassa => 'Inserire movimenti di cassa',
            self::ModificareMovimentiPropri => 'Modificare e ritirare i propri movimenti non confermati',
            self::ProporreClassificazioni => 'Proporre classificazioni',
            self::ImportareEConfermare => 'Importare estratti, confermare, classificare, abbinare giroconti',
            self::EliminareMovimentoManuale => 'Eliminare un movimento manuale, con motivazione',
            self::GestireRegole => 'Gestire regole, manuale, tipologie, iniziative, preventivi',
            self::ChiedereSuggerimentiAi => 'Chiedere suggerimenti all’AI',
            self::RiclassificareInBlocco => 'Riclassificare in blocco, modificare la tabella delle voci',
            self::GestireEsercizio => 'Dati dell’esercizio, approvazione, deposito',
            self::RiaprireEsercizio => 'Riaprire un esercizio',
            self::VedereRegistroModifiche => 'Vedere il registro completo delle modifiche',
            self::VedereStoriaMovimento => 'Vedere la storia del singolo movimento',
            self::Note => 'Leggere e scrivere note, menzionare',
            self::OscurareNota => 'Oscurare una nota',
            self::GestireUtenti => 'Gestire utenti, ruoli e assegnazioni',
            self::GestireImpostazioni => 'Impostazioni, automatismo AI, backup',
            self::GestirePassaggioConsegne => 'Gestire il passaggio di consegne',
        };
    }

    /** I permessi marcati «(ambito)» in #16: valgono solo sui conti dell'assegnazione. Gli altri valgono sempre. */
    public function haAmbito(): bool
    {
        return in_array($this, [self::InserireMovimentiCassa, self::ModificareMovimentiPropri, self::ProporreClassificazioni], true);
    }

    /**
     * Quello che un account esterno può avere, qualunque ruolo gli si dia: lettura, note (anche il Lettore
     * esterno di #16 le scrive) e il registro, che #16 concede a un esterno come il revisore «salvo permesso dato al suo ruolo».
     */
    public function valePerEsterni(): bool
    {
        return in_array($this, [self::Vedere, self::Note, self::VedereRegistroModifiche], true);
    }
}
