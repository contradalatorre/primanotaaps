<?php

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Avviso agli Amministratori: qualcuno è entrato con l'account di emergenza (#36). */
class AccessoDiEmergenza extends Mailable
{
    public function __construct(public ?string $ip, public CarbonInterface $quando) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Prima nota: accesso con l’account di emergenza');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.accesso-emergenza');
    }
}
