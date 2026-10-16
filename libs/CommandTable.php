<?php

declare(strict_types=1);

/**
 * Ordnet einer bedienbaren Variable (Ident) den Tasmota-Befehl zu.
 */
final class TasmotaV2CommandTable
{
    /**
     * @param int|float|null $dimmer aktueller Dimmerwert, damit eine Farbänderung die Helligkeit nicht verändert
     * @return array{command: string, payload: string}|null null für unbekannte Idents
     */
    public static function resolve(string $ident, mixed $value, int|float|null $dimmer = null): ?array
    {
        if (preg_match('/^Power(\d+)$/', $ident, $match) === 1) {
            return ['command' => 'Power' . $match[1], 'payload' => $value ? 'ON' : 'OFF'];
        }
        if (preg_match('/^Channel(\d)$/', $ident) === 1) {
            return ['command' => $ident, 'payload' => (string) self::percent($value)];
        }
        if (preg_match('/^Shutter(\d+)(Position|Tilt|Control|Lock)$/', $ident, $match) === 1) {
            $n = $match[1];
            if ($match[2] === 'Lock') {
                return ['command' => 'ShutterLock' . $n, 'payload' => $value ? '1' : '0'];
            }
            if ($match[2] === 'Position') {
                return ['command' => 'ShutterPosition' . $n, 'payload' => (string) self::percent($value)];
            }
            if ($match[2] === 'Tilt') {
                return ['command' => 'ShutterTilt' . $n, 'payload' => (string) (int) $value];
            }
            $command = match ((int) $value) {
                0       => 'ShutterOpen',
                1       => 'ShutterStop',
                2       => 'ShutterClose',
                default => null
            };
            return $command === null ? null : ['command' => $command . $n, 'payload' => ''];
        }

        switch ($ident) {
            case 'Dimmer':
            case 'White':
                return ['command' => $ident, 'payload' => (string) self::percent($value)];
            case 'CT':
                return ['command' => 'CT', 'payload' => (string) max(153, min(500, (int) $value))];
            case 'Speed':
                return ['command' => 'Speed', 'payload' => (string) max(1, min(40, (int) $value))];
            case 'Scheme':
                return ['command' => 'Scheme', 'payload' => (string) max(0, min(14, (int) $value))];
            case 'Fade':
                return ['command' => 'Fade', 'payload' => $value ? '1' : '0'];
            case 'Color':
                return ['command' => 'HSBColor', 'payload' => self::rgbToHsb((int) $value, $dimmer)];
        }
        return null;
    }

    private static function percent(mixed $value): int
    {
        return max(0, min(100, (int) $value));
    }

    /**
     * "Farbton,Sättigung,Helligkeit" für den Befehl HSBColor. Die Helligkeit bleibt die des Dimmers (Standard 100).
     */
    private static function rgbToHsb(int $rgb, int|float|null $dimmer): string
    {
        $r = (($rgb >> 16) & 0xFF) / 255;
        $g = (($rgb >> 8) & 0xFF) / 255;
        $b = ($rgb & 0xFF) / 255;
        $max = max($r, $g, $b);
        $delta = $max - min($r, $g, $b);

        if ($delta == 0.0) {
            $hue = 0.0;
        } elseif ($max === $r) {
            $hue = 60.0 * fmod(($g - $b) / $delta, 6.0);
        } elseif ($max === $g) {
            $hue = 60.0 * ((($b - $r) / $delta) + 2.0);
        } else {
            $hue = 60.0 * ((($r - $g) / $delta) + 4.0);
        }
        if ($hue < 0) {
            $hue += 360.0;
        }
        $saturation = $max == 0.0 ? 0.0 : $delta / $max;
        $brightness = $dimmer === null ? 100 : max(0, min(100, (int) $dimmer));

        return sprintf('%d,%d,%d', (int) round($hue), (int) round($saturation * 100), $brightness);
    }
}
