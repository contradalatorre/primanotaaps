<?php

namespace App\Backup;

use RuntimeException;

/** Un backup che non si è potuto fare per un motivo che conosciamo. Il messaggio è sicuro da mostrare e da mandare per email. */
final class BackupNonRiuscito extends RuntimeException {}
