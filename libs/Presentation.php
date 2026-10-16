<?php

declare(strict_types=1);

/**
 * Darstellungen (Presentations) für IP-Symcon 9. Die GUID-Konstanten stellt IP-Symcon bereit.
 * Parameter nach den Presentation-Definitionen des Kernels (IPS_GetPresentations).
 */
final class TasmotaV2Presentation
{
    /**
     * Darstellung aus einer Beschreibung im Funktionskatalog (libs/data/features.json).
     *
     * @param array<string,mixed> $spec kind: text, number, input, slider, toggle, color
     */
    public static function fromSpec(array $spec): array
    {
        return match ($spec['kind'] ?? 'text') {
            'number' => self::number((int) ($spec['digits'] ?? 0), (string) ($spec['unit'] ?? '')),
            'input'  => self::input((string) ($spec['suffix'] ?? '')),
            'slider' => self::slider((int) ($spec['min'] ?? 0), (int) ($spec['max'] ?? 100), (int) ($spec['step'] ?? 1), (string) ($spec['suffix'] ?? '')),
            'toggle' => self::toggle(),
            'color'  => self::color(),
            default  => self::text()
        };
    }

    public static function toggle(): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH];
    }

    public static function slider(int $min, int $max, int $step = 1, string $suffix = ''): array
    {
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'MIN'          => $min,
            'MAX'          => $max,
            'STEP_SIZE'    => $step,
            'DIGITS'       => 0,
            'SUFFIX'       => $suffix
        ];
    }

    public static function number(int $digits, string $unit = ''): array
    {
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'DIGITS'       => $digits,
            'SUFFIX'       => $unit === '' ? '' : ' ' . $unit
        ];
    }

    public static function text(): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
    }

    public static function input(string $suffix = ''): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_INPUT, 'SUFFIX' => $suffix];
    }

    public static function color(): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_COLOR];
    }

    /**
     * Rollladen: Tasmota meldet 100 für offen und 0 für geschlossen (ohne ShutterInvert).
     */
    public static function shutter(): array
    {
        return [
            'PRESENTATION'       => VARIABLE_PRESENTATION_SHUTTER,
            'OPEN_OUTSIDE_VALUE' => 100,
            'CLOSE_INSIDE_VALUE' => 0
        ];
    }

    /**
     * Anzeige für einen Wahrheitswert mit eigenen Beschriftungen, nur lesend.
     */
    public static function status(string $off, string $on): array
    {
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS'      => json_encode([
                [
                    'Value'              => false,
                    'Caption'            => $off,
                    'IconValue'          => '',
                    'IconActive'         => false,
                    'ColorActive'        => true,
                    'ColorValue'         => 16077123,
                    'ContentColorActive' => false
                ],
                [
                    'Value'              => true,
                    'Caption'            => $on,
                    'IconValue'          => '',
                    'IconActive'         => false,
                    'ColorActive'        => true,
                    'ColorValue'         => 1692672,
                    'ContentColorActive' => false
                ]
            ])
        ];
    }

    /**
     * @param array<int,array{0:int|string,1:string}> $options Wert, Beschriftung
     */
    public static function enumeration(array $options): array
    {
        $list = [];
        foreach ($options as [$value, $caption]) {
            $list[] = [
                'Value'      => $value,
                'Caption'    => $caption,
                'IconValue'  => '',
                'IconActive' => false,
                'Color'      => -1
            ];
        }
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => json_encode($list)
        ];
    }
}
