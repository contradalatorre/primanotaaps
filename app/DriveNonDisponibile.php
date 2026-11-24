<?php

namespace App;

use RuntimeException;

/**
 * Il drive condiviso non ha fatto quello che gli si chiedeva. Il messaggio è sicuro da mostrare, registrare e mandare
 * per email: mai il corpo della risposta di Google né le credenziali, e l'eccezione non ne porta con sé nessuna.
 */
final class DriveNonDisponibile extends RuntimeException {}
