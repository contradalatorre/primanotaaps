<?php

namespace App\Import;

use RuntimeException;

/** Il file non è un estratto che l'app sa leggere (o non torna): il messaggio è per il Tesoriere. */
class EstrattoNonValido extends RuntimeException {}
