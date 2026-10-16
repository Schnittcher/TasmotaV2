<?php

declare(strict_types=1);

require_once __DIR__ . '/DiscoveryModel.php';
require_once __DIR__ . '/Presentation.php';
require_once __DIR__ . '/FeatureCatalog.php';

/**
 * Übersetzt Tasmota-Nachrichten (stat/tele) in Variablendatensätze. Reine PHP-Logik ohne Symcon-Aufrufe.
 *
 * Ein Datensatz: ident, caption, type (TYPE_*), value (null = nur anlegen), presentation, action.
 * Es wird nach Schlüsseln der JSON-Nutzdaten verteilt, nicht nach Teilstrings des Topics.
 */
final class TasmotaV2MessageParser
{
    public const TYPE_BOOLEAN = 0;
    public const TYPE_INTEGER = 1;
    public const TYPE_FLOAT = 2;
    public const TYPE_STRING = 3;

    private const SHUTTER_DIRECTIONS = [-1 => 'Closing', 0 => 'Stopped', 1 => 'Opening'];

    // Lichtprogramme laut Tasmota-Doku: 0 bis 4 für jedes Licht, 5 bis 14 nur für adressierbare LEDs
    private const SCHEMES = [
        0  => 'Single color', 1 => 'Wake-up', 2 => 'Cycle up', 3 => 'Cycle down', 4 => 'Random',
        5  => 'Clock', 6 => 'Candlelight', 7 => 'RGB pattern', 8 => 'Christmas', 9 => 'Hanukkah',
        10 => 'Kwanzaa', 11 => 'Rainbow', 12 => 'Fire', 13 => 'Stairs', 14 => 'Clear'
    ];

    /** @var Closure(string):string */
    private Closure $translate;

    /**
     * @param array<string,mixed> $units         Inhalt von data/sensor_units.json
     * @param array<int,string>   $excludeSensors Sensornamen, die nicht angelegt werden
     */
    public function __construct(
        private TasmotaV2DiscoveryModel $model,
        private array $units,
        ?Closure $translate = null,
        private bool $systemVariables = false,
        private bool $autoSensors = true,
        private array $excludeSensors = [],
        private ?TasmotaV2FeatureCatalog $catalog = null,
        private array $features = [],
        private array $idNames = []
    ) {
        $this->translate = $translate ?? static fn (string $text): string => $text;
        $this->excludeSensors = array_map('strtolower', $excludeSensors);
    }

    /**
     * Datensätze ohne Wert für alles, was die Discovery-Nachricht ankündigt. Dadurch sind Bedienelemente
     * sichtbar, bevor das erste Telegramm eintrifft.
     *
     * @return array<int,array<string,mixed>>
     */
    public function skeleton(): array
    {
        $records = [];
        for ($n = 1; $n <= $this->model->powerCount(); $n++) {
            $records[] = $this->power($n, null);
        }
        if ($this->model->hasLight() && $this->model->lightSubtype() >= 1) {
            // Typ 0: einfache Lampe am Relais (zum Beispiel beim iFan), ohne Helligkeit
            $records[] = $this->light('Dimmer', null);
            $subtype = $this->model->lightSubtype();
            // Tasmota: 2 = Kalt/Warm, 3 = RGB, 4 = RGBW, 5 = RGBCW (aus dem Tasmota-Quelltext, nicht aus der Doku)
            if ($subtype >= 3) {
                $records[] = $this->light('Color', null);
            }
            if ($subtype === 2 || $subtype === 5) {
                $records[] = $this->light('CT', null);
            }
            if ($subtype === 4 || $subtype === 5) {
                $records[] = $this->light('White', null);
            }
        }
        for ($n = 1; $n <= $this->model->shutterCount(); $n++) {
            $records[] = $this->shutter($n, 'Position', null);
            $records[] = $this->shutter($n, 'Direction', null);
            $records[] = $this->shutter($n, 'Control', null);
            $records[] = $this->shutter($n, 'Lock', null);
        }
        foreach ($this->model->buttons() as $n) {
            $records[] = $this->button($n, null);
        }
        foreach (array_keys($this->model->switches()) as $n) {
            $records[] = $this->switchState($n, null);
        }
        if ($this->systemVariables && $this->model->moduleName() !== '') {
            // Name des Moduls oder Templates aus der Discovery
            $records[] = $this->system('Module', 'Module', self::TYPE_STRING, $this->model->moduleName());
        }
        if (in_array('ws2812', $this->features, true)) {
            // Effekte eines LED-Streifens
            foreach (['Fade', 'Speed', 'Scheme'] as $field) {
                $records[] = $this->light($field, null);
            }
        }
        if ($this->catalog !== null) {
            $records = array_merge($records, $this->catalog->skeleton($this->features, $this->translate));
        }
        return $records;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function parse(string $kind, string $suffix, string $payload): array
    {
        $suffixUpper = strtoupper($suffix);

        if ($suffixUpper === 'LWT') {
            $online = strcasecmp(trim($payload), $this->model->onlinePayload()) === 0;
            return [$this->online($online)];
        }

        if ($kind === 'stat' && preg_match('/^POWER(\d*)$/i', $suffix, $match) === 1) {
            $value = $this->powerValue($payload);
            return $value === null ? [] : [$this->power($match[1] === '' ? 1 : (int) $match[1], $value)];
        }

        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return [];
        }

        // Das Last-Will-Telegramm "Online" wird nur beim Verbinden mit dem Broker gesendet und kann
        // eine später angelegte Instanz verpassen. Telemetrie und Statusantworten belegen, dass das Gerät lebt.
        $alive = $kind === 'tele' && ($suffixUpper === 'STATE' || $suffixUpper === 'SENSOR');
        if (str_starts_with($suffixUpper, 'STATUS')) {
            return [$this->online(true), ...$this->status($data)];
        }
        if (preg_match('/^INFO\d$/', $suffixUpper) === 1) {
            return $this->info($data);
        }
        if ($suffixUpper === 'SENSOR') {
            return [...($alive ? [$this->online(true)] : []), ...$this->consume($data, true)];
        }
        // STATE, RESULT und, mit SetOption4, die nach dem Befehl benannten Antworten
        return [...($alive ? [$this->online(true)] : []), ...$this->consume($data, false)];
    }

    /**
     * Antwort auf "Status 0" (Topic STATUS0) und die einzelnen Status-Abschnitte.
     *
     * @param array<string,mixed> $data
     * @return array<int,array<string,mixed>>
     */
    private function status(array $data): array
    {
        $records = [];
        foreach ($data as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            switch ($key) {
                case 'StatusSTS':
                    $records = array_merge($records, $this->consume($value, false));
                    break;
                case 'StatusSNS':
                    $records = array_merge($records, $this->consume($value, true));
                    break;
                case 'StatusFWR':
                    if ($this->systemVariables && isset($value['Version']) && is_string($value['Version'])) {
                        $records[] = $this->system('Version', 'Version', self::TYPE_STRING, $value['Version']);
                    }
                    break;
                case 'StatusPRM':
                    if ($this->systemVariables && isset($value['GroupTopic']) && is_string($value['GroupTopic'])) {
                        $records[] = $this->system('GroupTopic', 'Group topic', self::TYPE_STRING, $value['GroupTopic']);
                    }
                    break;
                case 'StatusNET':
                    if ($this->systemVariables) {
                        foreach (['Hostname' => 'Hostname', 'IPAddress' => 'IP address'] as $field => $caption) {
                            if (isset($value[$field]) && is_string($value[$field])) {
                                $records[] = $this->system($field, $caption, self::TYPE_STRING, $value[$field]);
                            }
                        }
                    }
                    break;
            }
        }
        // Werte der Funktionen aus dem Abschnitt "Status" (zum Beispiel PowerOnState)
        if ($this->catalog !== null) {
            $records = array_merge($records, $this->catalog->results($this->features, $data, $this->translate, $this->idNames));
        }
        return $records;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<int,array<string,mixed>>
     */
    private function info(array $data): array
    {
        if (!$this->systemVariables) {
            return [];
        }
        $records = [];
        $info1 = $data['Info1'] ?? [];
        if (is_array($info1) && isset($info1['Version']) && is_string($info1['Version'])) {
            $records[] = $this->system('Version', 'Version', self::TYPE_STRING, $info1['Version']);
        }
        $info2 = $data['Info2'] ?? [];
        if (is_array($info2)) {
            foreach (['Hostname' => 'Hostname', 'IPAddress' => 'IP address'] as $field => $caption) {
                if (isset($info2[$field]) && is_string($info2[$field])) {
                    $records[] = $this->system($field, $caption, self::TYPE_STRING, $info2[$field]);
                }
            }
        }
        return $records;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<int,array<string,mixed>>
     */
    private function consume(array $data, bool $sensorContext): array
    {
        $records = [];
        $meta = array_flip($this->units['meta'] ?? []);
        $unitSymbols = $this->unitSymbols($data);
        $switchNames = array_flip(array_filter($this->model->switches(), static fn (string $name): bool => $name !== ''));
        $hasHsb = isset($data['HSBColor']) && is_string($data['HSBColor']);
        // Schlüssel, die eine Funktion aus dem Funktionskatalog auswertet, gehören nicht in den Sensorbaum

        foreach ($data as $key => $value) {
            $key = (string) $key;
            if (isset($meta[$key]) || ($this->catalog !== null && $this->catalog->handlesKey($this->features, $key))) {
                continue;
            }

            if (preg_match('/^POWER(\d*)$/', $key, $match) === 1 && !is_array($value)) {
                $state = $this->powerValue((string) $value);
                $number = $match[1] === '' ? 1 : (int) $match[1];
                // Beim iFan sind die weiteren Relais die Lüfterstufen, die der Lüfter über FanSpeed bedient wird
                if ($state !== null && !($number > 1 && $this->model->flag('if'))) {
                    $records[] = $this->power($number, $state);
                }
                continue;
            }

            switch ($key) {
                case 'Dimmer':
                case 'White':
                case 'CT':
                case 'Speed':
                case 'Scheme':
                    if (is_numeric($value) && !is_array($value)) {
                        $records[] = $this->light($key, (int) $value);
                    }
                    continue 2;
                case 'Fade':
                    $state = $this->powerValue((string) $value);
                    if ($state !== null) {
                        $records[] = $this->light('Fade', $state);
                    }
                    continue 2;
                case 'HSBColor':
                    $rgb = is_string($value) ? $this->hsbToRgb($value) : null;
                    if ($rgb !== null) {
                        $records[] = $this->light('Color', $rgb);
                    }
                    continue 2;
                case 'Color':
                    if (!$hasHsb && is_string($value)) {
                        $rgb = $this->colorToRgb($value);
                        if ($rgb !== null) {
                            $records[] = $this->light('Color', $rgb);
                        }
                    }
                    continue 2;
                case 'Wifi':
                    if (is_array($value)) {
                        $records = array_merge($records, $this->wifi($value));
                    }
                    continue 2;
                case 'ENERGY':
                    if (is_array($value)) {
                        $records = array_merge($records, $this->energy($value));
                    }
                    continue 2;
            }

            if (preg_match('/^Channel(\d)$/', $key, $match) === 1 && is_numeric($value)) {
                $records[] = $this->light($key, (int) $value);
                continue;
            }
            if (preg_match('/^Shutter(\d+)$/', $key, $match) === 1 && is_array($value)) {
                $records = array_merge($records, $this->shutterObject((int) $match[1], $value));
                continue;
            }
            if (preg_match('/^ShutterLock(\d+)$/', $key, $match) === 1 && !is_array($value)) {
                $state = $this->powerValue((string) $value);
                if ($state !== null) {
                    $records[] = $this->shutter((int) $match[1], 'Lock', $state);
                }
                continue;
            }
            if (preg_match('/^ShutterPosition(\d+)$/', $key, $match) === 1 && is_numeric($value)) {
                $records[] = $this->shutter((int) $match[1], 'Position', (int) $value);
                continue;
            }
            if (preg_match('/^Button(\d+)$/', $key, $match) === 1) {
                $action = is_array($value) ? ($value['Action'] ?? null) : null;
                if (is_string($action)) {
                    $records[] = $this->button((int) $match[1], $action);
                }
                continue;
            }
            $switchNumber = null;
            if (preg_match('/^Switch(\d+)$/', $key, $match) === 1) {
                $switchNumber = (int) $match[1];
            } elseif (isset($switchNames[$key])) {
                $switchNumber = (int) $switchNames[$key];
            }
            if ($switchNumber !== null) {
                $action = is_array($value) ? ($value['Action'] ?? null) : $value;
                if (is_string($action)) {
                    $records[] = $this->switchState($switchNumber, $action);
                }
                continue;
            }

            if ($this->systemVariables && !$sensorContext) {
                $system = $this->systemValue($key, $value);
                if ($system !== null) {
                    $records[] = $system;
                    continue;
                }
            }

            if ($sensorContext && $this->autoSensors && is_array($value)) {
                if (in_array(strtolower($key), $this->excludeSensors, true)) {
                    continue;
                }
                $records = array_merge($records, $this->sensorTree([$key], $value, $unitSymbols));
            }
        }
        if ($this->catalog !== null) {
            $records = array_merge($records, $this->catalog->results($this->features, $data, $this->translate, $this->idNames));
        }
        return $records;
    }

    /**
     * @param array<int,string>   $path
     * @param array<mixed>        $node
     * @param array<string,string> $unitSymbols
     * @return array<int,array<string,mixed>>
     */
    private function sensorTree(array $path, array $node, array $unitSymbols): array
    {
        $records = [];
        foreach ($node as $key => $value) {
            $key = (string) $key;
            if ($value === null) {
                continue;
            }
            $childPath = [...$path, $key];
            if (is_array($value)) {
                // Listen (Mehrphasen und mehrere Werte) werden durchnummeriert ab 1
                if (array_is_list($value)) {
                    foreach ($value as $index => $item) {
                        if (is_scalar($item)) {
                            $records[] = $this->sensorValue([...$childPath, (string) ($index + 1)], $key, $item, $unitSymbols);
                        }
                    }
                    continue;
                }
                $records = array_merge($records, $this->sensorTree($childPath, $value, $unitSymbols));
                continue;
            }
            if (is_scalar($value)) {
                $records[] = $this->sensorValue($childPath, $key, $value, $unitSymbols);
            }
        }
        return $records;
    }

    /**
     * @param array<int,string>    $path
     * @param array<string,string> $unitSymbols
     * @return array<string,mixed>
     */
    private function sensorValue(array $path, string $key, mixed $value, array $unitSymbols): array
    {
        $definition = $this->units['sensor'][$key] ?? null;
        $caption = ($this->translate)(is_array($definition) ? (string) $definition['caption'] : $key);
        // Der Sensorname steht vor dem Wert, verschachtelte Ebenen davor
        $name = implode(' ', array_slice($path, 0, -1));
        $fullCaption = trim($name . ' ' . $caption);
        $ident = $this->ident('Sensor_' . implode('_', $path));

        if (is_bool($value)) {
            return $this->record($ident, $fullCaption, self::TYPE_BOOLEAN, $value, TasmotaV2Presentation::status('Off', 'On'));
        }
        if (is_int($value) || is_float($value)) {
            $unit = '';
            $digits = 2;
            if (is_array($definition)) {
                $digits = (int) ($definition['digits'] ?? 2);
                $unit = $this->resolveUnit((string) ($definition['unit'] ?? ''), $unitSymbols);
            }
            return $this->record($ident, $fullCaption, self::TYPE_FLOAT, (float) $value, TasmotaV2Presentation::number($digits, $unit));
        }
        return $this->record($ident, $fullCaption, self::TYPE_STRING, (string) $value, TasmotaV2Presentation::text());
    }

    /**
     * @param array<string,mixed> $energy
     * @return array<int,array<string,mixed>>
     */
    private function energy(array $energy): array
    {
        $records = [];
        foreach ($energy as $key => $value) {
            $key = (string) $key;
            $definition = $this->units['energy'][$key] ?? null;
            $caption = ($this->translate)(is_array($definition) ? (string) $definition['caption'] : $key);
            $digits = is_array($definition) ? (int) ($definition['digits'] ?? 2) : 2;
            $unit = is_array($definition) ? (string) ($definition['unit'] ?? '') : '';
            $presentation = TasmotaV2Presentation::number($digits, $unit);

            // Mehrphasige Geräte melden Listen, die ab 1 durchnummeriert werden
            $isList = is_array($value) && array_is_list($value);
            $values = $isList ? $value : [$value];
            foreach ($values as $index => $item) {
                if (!is_scalar($item)) {
                    continue;
                }
                $ident = $this->ident('Energy_' . $key . ($isList ? '_' . ($index + 1) : ''));
                $label = $caption . ($isList ? ' ' . ($index + 1) : '');
                if (is_string($item)) {
                    $records[] = $this->record($ident, $label, self::TYPE_STRING, $item, TasmotaV2Presentation::text());
                } elseif (is_numeric($item)) {
                    $records[] = $this->record($ident, $label, self::TYPE_FLOAT, (float) $item, $presentation);
                }
            }
        }
        return $records;
    }

    /**
     * @param array<string,mixed> $wifi
     * @return array<int,array<string,mixed>>
     */
    private function wifi(array $wifi): array
    {
        $records = [];
        // Tasmota: RSSI ist die Qualität in Prozent, Signal die Feldstärke in dBm
        if (isset($wifi['RSSI']) && is_numeric($wifi['RSSI'])) {
            $records[] = $this->record('WifiRSSI', ($this->translate)('Wi-Fi quality'), self::TYPE_INTEGER, (int) $wifi['RSSI'], TasmotaV2Presentation::number(0, '%'));
        }
        if (isset($wifi['Signal']) && is_numeric($wifi['Signal'])) {
            $records[] = $this->record('WifiSignal', ($this->translate)('Wi-Fi signal'), self::TYPE_INTEGER, (int) $wifi['Signal'], TasmotaV2Presentation::number(0, 'dBm'));
        }
        if ($this->systemVariables) {
            foreach (['SSId' => 'SSID', 'BSSId' => 'BSSID', 'Mode' => 'Wi-Fi mode'] as $field => $caption) {
                if (isset($wifi[$field]) && is_string($wifi[$field])) {
                    $records[] = $this->system('Wifi' . $field, $caption, self::TYPE_STRING, $wifi[$field]);
                }
            }
            if (isset($wifi['Channel']) && is_numeric($wifi['Channel'])) {
                $records[] = $this->system('WifiChannel', 'Wi-Fi channel', self::TYPE_INTEGER, (int) $wifi['Channel']);
            }
        }
        return $records;
    }

    private function systemValue(string $key, mixed $value): ?array
    {
        switch ($key) {
            case 'Uptime':
                return is_string($value) ? $this->system('Uptime', 'Uptime', self::TYPE_STRING, $value) : null;
            case 'UptimeSec':
                return is_numeric($value) ? $this->system('UptimeSec', 'Uptime (seconds)', self::TYPE_INTEGER, (int) $value, 's') : null;
            case 'Heap':
                return is_numeric($value) ? $this->system('Heap', 'Free heap', self::TYPE_INTEGER, (int) $value, 'kB') : null;
            case 'LoadAvg':
                return is_numeric($value) ? $this->system('LoadAvg', 'Load average', self::TYPE_INTEGER, (int) $value) : null;
            case 'Vcc':
                return is_numeric($value) ? $this->system('Vcc', 'Supply voltage', self::TYPE_FLOAT, (float) $value, 'V') : null;
            case 'SleepMode':
                return is_string($value) ? $this->system('SleepMode', 'Sleep mode', self::TYPE_STRING, $value) : null;
            case 'MqttCount':
                return is_numeric($value) ? $this->system('MqttCount', 'MQTT connections', self::TYPE_INTEGER, (int) $value) : null;
        }
        return null;
    }

    /**
     * @param array<string,mixed> $shutter
     * @return array<int,array<string,mixed>>
     */
    private function shutterObject(int $n, array $shutter): array
    {
        $records = [];
        foreach (['Position', 'Direction', 'Tilt'] as $field) {
            if (isset($shutter[$field]) && is_numeric($shutter[$field])) {
                $records[] = $this->shutter($n, $field, (int) $shutter[$field]);
            }
        }
        return $records;
    }

    private function online(bool $online): array
    {
        return $this->record('Online', ($this->translate)('Reachable'), self::TYPE_BOOLEAN, $online, TasmotaV2Presentation::status(($this->translate)('Not reachable'), ($this->translate)('Reachable')));
    }

    private function power(int $n, ?bool $value): array
    {
        return $this->record('Power' . $n, sprintf(($this->translate)('Power %d'), $n), self::TYPE_BOOLEAN, $value, TasmotaV2Presentation::toggle(), true);
    }

    private function light(string $field, int|bool|null $value): array
    {
        switch ($field) {
            case 'Dimmer':
                return $this->record('Dimmer', ($this->translate)('Dimmer'), self::TYPE_INTEGER, $value, TasmotaV2Presentation::slider(0, 100, 1, ' %'), true);
            case 'White':
                return $this->record('White', ($this->translate)('White'), self::TYPE_INTEGER, $value, TasmotaV2Presentation::slider(0, 100, 1, ' %'), true);
            case 'CT':
                return $this->record('CT', ($this->translate)('Color temperature'), self::TYPE_INTEGER, $value, TasmotaV2Presentation::slider(153, 500, 1, ' mired'), true);
            case 'Speed':
                return $this->record('Speed', ($this->translate)('Fade speed'), self::TYPE_INTEGER, $value, TasmotaV2Presentation::slider(1, 40, 1), true);
            case 'Scheme':
                return $this->record('Scheme', ($this->translate)('Scheme'), self::TYPE_INTEGER, $value, $this->schemePresentation(), true);
            case 'Fade':
                return $this->record('Fade', ($this->translate)('Fade'), self::TYPE_BOOLEAN, $value, TasmotaV2Presentation::toggle(), true);
            case 'Color':
                return $this->record('Color', ($this->translate)('Color'), self::TYPE_INTEGER, $value, TasmotaV2Presentation::color(), true);
        }
        // Channel1 bis Channel5
        $n = (int) substr($field, 7);
        return $this->record($field, sprintf(($this->translate)('Channel %d'), $n), self::TYPE_INTEGER, $value, TasmotaV2Presentation::slider(0, 100, 1, ' %'), true);
    }

    /**
     * Auswahl der Lichtprogramme. Die Programme 5 bis 14 gibt es nur bei adressierbaren LEDs (WS2812).
     */
    private function schemePresentation(): array
    {
        $limit = in_array('ws2812', $this->features, true) ? 14 : 4;
        $options = [];
        foreach (self::SCHEMES as $number => $caption) {
            if ($number <= $limit) {
                $options[] = [$number, ($this->translate)($caption)];
            }
        }
        return TasmotaV2Presentation::enumeration($options);
    }

    private function shutter(int $n, string $field, int|bool|null $value): array
    {
        switch ($field) {
            case 'Lock':
                // Gesperrt: Taster und Befehle bewegen den Rollladen nicht
                return $this->record('Shutter' . $n . 'Lock', sprintf(($this->translate)('Shutter %d lock'), $n), self::TYPE_BOOLEAN, $value, TasmotaV2Presentation::toggle(), true);
            case 'Position':
                return $this->record('Shutter' . $n . 'Position', sprintf(($this->translate)('Shutter %d position'), $n), self::TYPE_INTEGER, $value, TasmotaV2Presentation::shutter(), true);
            case 'Direction':
                $options = [];
                foreach (self::SHUTTER_DIRECTIONS as $direction => $label) {
                    $options[] = [$direction, ($this->translate)($label)];
                }
                return $this->record('Shutter' . $n . 'Direction', sprintf(($this->translate)('Shutter %d direction'), $n), self::TYPE_INTEGER, $value, TasmotaV2Presentation::enumeration($options));
            case 'Tilt':
                return $this->record('Shutter' . $n . 'Tilt', sprintf(($this->translate)('Shutter %d tilt'), $n), self::TYPE_INTEGER, $value, TasmotaV2Presentation::slider(-90, 90, 1, ' °'), true);
        }
        // Control: Auf, Stopp, Zu. Der Wert wird nie gesetzt, die Variable dient nur als Bedienelement.
        return $this->record(
            'Shutter' . $n . 'Control',
            sprintf(($this->translate)('Shutter %d control'), $n),
            self::TYPE_INTEGER,
            $value,
            TasmotaV2Presentation::enumeration([[0, ($this->translate)('Open')], [1, ($this->translate)('Stop')], [2, ($this->translate)('Close')]]),
            true
        );
    }

    private function button(int $n, ?string $action): array
    {
        return $this->record('Button' . $n, sprintf(($this->translate)('Button %d'), $n), self::TYPE_STRING, $action, TasmotaV2Presentation::text());
    }

    private function switchState(int $n, ?string $action): array
    {
        return $this->record('Switch' . $n, sprintf(($this->translate)('Switch %d'), $n), self::TYPE_STRING, $action, TasmotaV2Presentation::text());
    }

    private function system(string $ident, string $caption, int $type, mixed $value, string $unit = ''): array
    {
        $presentation = $type === self::TYPE_STRING ? TasmotaV2Presentation::text() : TasmotaV2Presentation::number($type === self::TYPE_FLOAT ? 2 : 0, $unit);
        return $this->record('System_' . $ident, ($this->translate)($caption), $type, $value, $presentation);
    }

    /**
     * @param array<string,mixed> $presentation
     * @return array<string,mixed>
     */
    private function record(string $ident, string $caption, int $type, mixed $value, array $presentation, bool $action = false): array
    {
        return [
            'ident'        => $ident,
            'caption'      => $caption,
            'type'         => $type,
            'value'        => $value,
            'presentation' => $presentation,
            'action'       => $action
        ];
    }

    /**
     * Ident darf nur Buchstaben, Zahlen und Unterstriche enthalten.
     */
    private function ident(string $raw): string
    {
        $ident = (string) preg_replace('/[^A-Za-z0-9_]/', '_', $raw);
        if (strlen($ident) > 60) {
            $ident = substr($ident, 0, 50) . '_' . substr(md5($raw), 0, 8);
        }
        return $ident;
    }

    private function powerValue(string $text): ?bool
    {
        $text = trim($text);
        $states = $this->model->stateTexts();
        if (strcasecmp($text, $states[1]) === 0 || strcasecmp($text, 'ON') === 0 || $text === '1' || strcasecmp($text, 'true') === 0) {
            return true;
        }
        if (strcasecmp($text, $states[0]) === 0 || strcasecmp($text, 'OFF') === 0 || $text === '0' || strcasecmp($text, 'false') === 0) {
            return false;
        }
        return null;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,string> Einheitsnamen: temp, pressure, speed
     */
    private function unitSymbols(array $data): array
    {
        $symbols = $this->units['unitSymbols'] ?? [];
        return [
            'temp'     => (string) ($symbols['temp'][$data['TempUnit'] ?? 'C'] ?? '°C'),
            'pressure' => (string) ($symbols['pressure'][$data['PressureUnit'] ?? 'hPa'] ?? 'hPa'),
            'speed'    => (string) ($symbols['speed'][$data['SpeedUnit'] ?? 'm/s'] ?? 'm/s')
        ];
    }

    /**
     * @param array<string,string> $unitSymbols
     */
    private function resolveUnit(string $unit, array $unitSymbols): string
    {
        if ($unit !== '' && $unit[0] === '@') {
            return $unitSymbols[substr($unit, 1)] ?? '';
        }
        return $unit;
    }

    /**
     * HSBColor "Farbton,Sättigung,Helligkeit". Die Helligkeit steckt im Dimmer, deshalb wird die Farbe bei voller Helligkeit berechnet.
     */
    private function hsbToRgb(string $hsb): ?int
    {
        $parts = explode(',', $hsb);
        if (count($parts) < 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
            return null;
        }
        $hue = fmod((float) $parts[0], 360.0) / 60.0;
        $saturation = max(0.0, min(100.0, (float) $parts[1])) / 100.0;
        $sector = (int) floor($hue);
        $fraction = $hue - $sector;
        $p = 1.0 - $saturation;
        $q = 1.0 - $saturation * $fraction;
        $t = 1.0 - $saturation * (1.0 - $fraction);
        [$r, $g, $b] = match ($sector % 6) {
            0       => [1.0, $t, $p],
            1       => [$q, 1.0, $p],
            2       => [$p, 1.0, $t],
            3       => [$p, $q, 1.0],
            4       => [$t, $p, 1.0],
            default => [1.0, $p, $q]
        };
        return ((int) round($r * 255) << 16) | ((int) round($g * 255) << 8) | (int) round($b * 255);
    }

    /**
     * Color als Hex ("RRGGBB", bei RGBW/RGBCW länger) oder, mit SetOption17, als Dezimalliste.
     */
    private function colorToRgb(string $color): ?int
    {
        if (str_contains($color, ',')) {
            $parts = array_map('intval', explode(',', $color));
            if (count($parts) < 3) {
                return null;
            }
            return (max(0, min(255, $parts[0])) << 16) | (max(0, min(255, $parts[1])) << 8) | max(0, min(255, $parts[2]));
        }
        if (preg_match('/^[0-9A-Fa-f]{6,}$/', $color) !== 1) {
            return null;
        }
        return (int) hexdec(substr($color, 0, 6));
    }
}
