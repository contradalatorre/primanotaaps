<?php

namespace App\Mail;

use App\Models\Menzione;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/** Le menzioni da vedere (#46): autore, data, importo, descrizione breve e link; mai il testo della nota. */
class MenzioniDaVedere extends Mailable
{
    /** @param  Collection<int, Menzione>  $menzioni  con `nota.autore` e `nota.oggetto` già caricati */
    public function __construct(public Collection $menzioni, public bool $immediata = false) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->immediata ? 'Prima nota: qualcuno ti ha chiamato in una nota' : 'Prima nota: menzioni da vedere');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.menzioni-da-vedere');
    }
}
