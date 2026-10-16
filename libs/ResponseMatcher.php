<?php

declare(strict_types=1);

/**
 * Ordnet eine empfangene Nachricht einem gesendeten Befehl zu, damit SendCommandWait nur auf die passende Antwort wartet.
 *
 * Tasmota antwortet auf einen Befehl mit stat/<Topic>/RESULT und dem Befehlsnamen als JSON-Schlüssel
 * (zum Beispiel {"PowerOnState":3}), mit SetOption4 unter stat/<Topic>/<BEFEHL>, und auf Status mit stat/<Topic>/STATUS<n>.
 */
final class TasmotaV2ResponseMatcher
{
    /**
     * @param string $kind   stat oder tele
     * @param string $suffix Topic-Rest nach dem Gerätetopic, zum Beispiel RESULT
     */
    public static function matches(string $command, string $kind, string $suffix, string $payload): bool
    {
        $command = strtolower(trim($command));
        if ($command === '' || $kind !== 'stat') {
            return false;
        }
        // Antworten sind JSON. Klartext wie stat/<Topic>/POWER ist eine Meldung des Zustands, nicht die Antwort.
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return false;
        }
        $suffix = strtolower($suffix);
        if (preg_match('/^status\d*$/', $command) === 1) {
            return preg_match('/^status\d*$/', $suffix) === 1;
        }
        if ($suffix === $command) {
            return true;
        }
        // Der Befehlsname, gegebenenfalls mit Nummer (Power → POWER1, Gpio → GPIO0), steht als Schlüssel in der Antwort
        $pattern = '/^' . preg_quote($command, '/') . '\d*$/i';
        foreach (array_keys($data) as $key) {
            if (preg_match($pattern, (string) $key) === 1) {
                return true;
            }
        }
        return false;
    }
}
