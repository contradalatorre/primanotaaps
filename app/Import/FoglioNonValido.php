<?php

namespace App\Import;

use RuntimeException;

/** Il file non è un foglio di calcolo che l'app sa leggere: il messaggio dice perché, in parole da mostrare. */
final class FoglioNonValido extends RuntimeException {}
