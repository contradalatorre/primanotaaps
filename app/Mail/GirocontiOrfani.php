<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/** Le metà di giroconto senza corrispondenza (#47): solo importo, data, conto e link; mai descrizione o causale. */
class GirocontiOrfani extends Mailable
{
    /** @param  Collection<int, array<string,mixed>>  $orfane  come `Giroconti::orfane`, con `movimento.conto` */
    public function __construct(public Collection $orfane) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Prima nota: giroconti senza corrispondenza');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.giroconti-orfani');
    }
}
