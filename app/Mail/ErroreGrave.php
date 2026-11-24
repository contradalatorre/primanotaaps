<?php

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Avviso agli Amministratori: un errore grave (un backup fallito, un compito periodico fermo, un errore dell'app) (#68). */
class ErroreGrave extends Mailable
{
    public function __construct(public string $origine, public string $messaggio, public int $volte, public CarbonInterface $quando) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Prima nota: errore grave ('.$this->origine.')');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.errore-grave');
    }
}
