<?php

namespace App\Backup;

use Carbon\CarbonInterface;

/**
 * La rotazione dei backup (#68): si tiene l'ultimo di ognuno degli ultimi 12 mesi che hanno un backup, più l'ultimo di
 * ogni anno degli ultimi 10. Vale per una serie sola: chi la chiama la applica a parte ai mensili e a quelli su richiesta (`Esecuzione::ruota`), così uno su richiesta non toglie mai un mensile. Nello stesso mese, in una serie, vince il più recente.
 */
final class Rotazione
{
    public const MESI = 12;

    public const ANNI = 10;

    /**
     * @param  array<int|string, CarbonInterface>  $backup  id => quando è stato fatto (solo quelli riusciti e ancora sul drive)
     * @return list<int|string> gli id da eliminare
     */
    public static function daEliminare(array $backup, CarbonInterface $adesso): array
    {
        arsort($backup); // dal più recente
        $ultimoDelMese = $ultimoDellAnno = [];
        foreach ($backup as $id => $quando) {
            $ultimoDelMese[$quando->format('Y-m')] ??= $id;
            $ultimoDellAnno[$quando->year] ??= $id;
        }

        $tenuti = array_slice($ultimoDelMese, 0, self::MESI); // già in ordine dal mese più recente
        foreach ($ultimoDellAnno as $anno => $id) {
            if ($anno > $adesso->year - self::ANNI) {
                $tenuti[] = $id;
            }
        }

        return array_values(array_diff(array_keys($backup), $tenuti));
    }
}
