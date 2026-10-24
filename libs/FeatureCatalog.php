<?php

declare(strict_types=1);

require_once __DIR__ . '/Presentation.php';
require_once __DIR__ . '/DiscoveryModel.php';

/**
 * Funktionskatalog für Hardware, die die Discovery nicht beschreibt (Fingerabdrucksensor und Ähnliches).
 *
 * Ein Eintrag in data/features.json erkennt die Funktion an den GPIO-Komponenten des Geräts (Befehl "Gpio"),
 * legt Variablen an, wertet Ergebnisse (stat/RESULT, tele/STATE) aus und ordnet bedienbaren Variablen einen Befehl zu.
 * Neue Hardware braucht einen neuen Eintrag, keinen neuen Code.
 *
 * Eintrag: caption, detect.gpio[], detect.discovery[] (Kennzeichen der Discovery wie "if") oder detect.always (für jedes Gerät), detect.data[] (JSON-Pfade, die in einer empfangenen Nachricht vorkommen, zum Beispiel ENERGY), init[] {command, payload}, variables{ident: {caption, type, presentation, action}},
 * results[] {path, variable, scalarOnly, join (Liste mit diesem Trennzeichen zu Text zusammenfügen)}, dynamic[] (Variablen, die erst entstehen, wenn Daten da sind, zum Beispiel je Pin), names {property, idVariable, nameVariable}. type: boolean, integer, float, string. presentation.kind: text, number, input, slider,
 * toggle, color, enumeration (options: [[Wert, Beschriftung], ...]). action: {command, payload, min, max, pattern} mit dem Platzhalter {value}; min und max sind optional
 * und verwerfen Werte außerhalb des Bereichs, pattern (regulärer Ausdruck, geprüft ohne Leerzeichen) verwirft ungültigen Text.
 */
final class TasmotaV2FeatureCatalog
{
    private const TYPES = ['boolean' => 0, 'integer' => 1, 'float' => 2, 'string' => 3];

    /**
     * @param array<string,array<string,mixed>> $features
     */
    public function __construct(private array $features)
    {
    }

    public static function fromFile(string $path): self
    {
        $data = json_decode((string) file_get_contents($path), true);
        return new self(is_array($data) ? $data : []);
    }

    /**
     * @return array<int,string>
     */
    public function ids(): array
    {
        return array_keys($this->features);
    }

    public function caption(string $id): string
    {
        return (string) ($this->features[$id]['caption'] ?? $id);
    }

    /**
     * Nur bekannte Funktionen, ohne Doppelte.
     *
     * @param array<int,string> $ids
     * @return array<int,string>
     */
    public function normalize(array $ids): array
    {
        $known = [];
        foreach ($ids as $id) {
            $id = strtolower(trim($id));
            if (isset($this->features[$id]) && !in_array($id, $known, true)) {
                $known[] = $id;
            }
        }
        return $known;
    }

    /**
     * GPIO-Komponenten aus der Antwort auf "Gpio" ({"GPIO0":{"32":"Button1"}, ...}), null wenn es keine solche Antwort ist.
     *
     * @param array<string,mixed> $data
     * @return array<int,string>|null
     */
    public static function componentsFromResult(array $data): ?array
    {
        $components = null;
        foreach ($data as $key => $value) {
            if (preg_match('/^GPIO\d+$/', (string) $key) === 1 && is_array($value)) {
                $components ??= [];
                // Der Name steht je nach Firmware als Wert ({"32":"Button1"}, 12.x) oder als Schlüssel ({"Button1":32}, 15.x)
                foreach ($value as $code => $name) {
                    $component = is_string($name) ? $name : (string) $code;
                    if ($component !== '' && !is_numeric($component)) {
                        $components[] = $component;
                    }
                }
            }
        }
        return $components;
    }

    /**
     * @param array<int,string> $components
     * @return array<int,string> erkannte Funktionen
     */
    public function detect(array $components): array
    {
        $lower = array_map('strtolower', $components);
        $detected = [];
        foreach ($this->features as $id => $feature) {
            foreach (($feature['detect']['gpio'] ?? []) as $name) {
                if (in_array(strtolower((string) $name), $lower, true)) {
                    $detected[] = (string) $id;
                    break;
                }
            }
        }
        return $detected;
    }

    /**
     * Funktionen, deren Daten in einer empfangenen Nachricht vorkommen (zum Beispiel ENERGY bei Geräten mit Energiemessung).
     *
     * @param array<string,mixed> $data JSON-Objekt einer Nachricht
     * @return array<int,string>
     */
    public function detectFromData(array $data): array
    {
        $detected = [];
        foreach ($this->features as $id => $feature) {
            foreach (($feature['detect']['data'] ?? []) as $path) {
                if ($this->expand($data, explode('.', (string) $path)) !== []) {
                    $detected[] = (string) $id;
                    break;
                }
            }
        }
        return $detected;
    }

    /**
     * Funktionen, die für jedes Gerät gelten (detect.always), zum Beispiel PowerOnState.
     *
     * @return array<int,string>
     */
    public function alwaysActive(): array
    {
        $ids = [];
        foreach ($this->features as $id => $feature) {
            if (!empty($feature['detect']['always'])) {
                $ids[] = (string) $id;
            }
        }
        return $ids;
    }

    /**
     * Funktionen, die die Discovery-Nachricht des Geräts über ein Kennzeichen ankündigt (zum Beispiel "if" beim iFan).
     *
     * @return array<int,string>
     */
    public function detectFromDiscovery(TasmotaV2DiscoveryModel $model): array
    {
        $detected = [];
        foreach ($this->features as $id => $feature) {
            foreach (($feature['detect']['discovery'] ?? []) as $flag) {
                if ($model->flag((string) $flag)) {
                    $detected[] = (string) $id;
                    break;
                }
            }
        }
        return $detected;
    }

    /**
     * Variablen der Funktionen ohne Wert.
     *
     * @param array<int,string> $ids
     * @return array<int,array<string,mixed>>
     */
    public function skeleton(array $ids, Closure $translate): array
    {
        $records = [];
        foreach ($this->normalize($ids) as $id) {
            foreach (($this->features[$id]['variables'] ?? []) as $ident => $variable) {
                $records[] = $this->record((string) $ident, $variable, null, $translate);
            }
        }
        return $records;
    }

    /**
     * Oberste JSON-Schlüssel, die die Funktionen auswerten. Sie sind für den Sensorbaum tabu.
     *
     * @param array<int,string> $ids
     * @return array<int,string>
     */
    public function resultKeys(array $ids): array
    {
        $keys = [];
        foreach ($this->normalize($ids) as $id) {
            foreach (array_merge($this->features[$id]['results'] ?? [], $this->features[$id]['dynamic'] ?? []) as $result) {
                $keys[] = explode('.', (string) $result['path'])[0];
            }
        }
        return array_values(array_unique($keys));
    }

    /**
     * Gehört der oberste JSON-Schlüssel zu einer aktiven Funktion (auch mit Platzhalter wie PCF8574-*)? Dann gehört er nicht in den Sensorbaum.
     *
     * @param array<int,string> $ids
     */
    public function handlesKey(array $ids, string $key): bool
    {
        foreach ($this->resultKeys($ids) as $pattern) {
            if (preg_match($this->segmentRegex($pattern), $key) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int,string> $ids
     * @param array<string,mixed> $data JSON-Objekt einer Nachricht
     * @return array<int,array<string,mixed>>
     */
    public function results(array $ids, array $data, Closure $translate, array $idNames = []): array
    {
        $records = [];
        foreach ($this->normalize($ids) as $id) {
            $variables = $this->features[$id]['variables'] ?? [];
            foreach (($this->features[$id]['results'] ?? []) as $result) {
                $ident = (string) $result['variable'];
                $value = $this->lookup($data, (string) $result['path']);
                if (is_array($value) && isset($result['join'])) {
                    // Liste (zum Beispiel 9 Empfindlichkeiten) als ein Text
                    $value = implode((string) $result['join'], array_map('strval', array_filter($value, 'is_scalar')));
                }
                if ($value === null || !isset($variables[$ident]) || (!empty($result['scalarOnly']) && is_array($value))) {
                    continue;
                }
                $coerced = $this->coerce((string) $variables[$ident]['type'], $value);
                if ($coerced !== null) {
                    $records[] = $this->record($ident, $variables[$ident], $coerced, $translate);
                }
            }
            foreach ($this->features[$id]['dynamic'] ?? [] as $rule) {
                foreach ($this->dynamicRecords($rule, $data, $translate) as $record) {
                    $records[] = $record;
                }
            }
            // Namen zu erkannten IDs
            $names = $this->features[$id]['names'] ?? null;
            if (is_array($names)) {
                foreach ($records as $record) {
                    if ($record['ident'] === ($names['idVariable'] ?? '') && is_int($record['value'])) {
                        $nameVariable = (string) $names['nameVariable'];
                        $records[] = $this->record($nameVariable, $variables[$nameVariable], $this->resolveName($id, $record['value'], $idNames, $translate), $translate);
                        break;
                    }
                }
            }
        }
        return $records;
    }

    /**
     * Name zu einer ID, sonst "Unbekannt (ID n)".
     *
     * @param array<string,array<int,string>> $idNames Funktion => ID => Name
     */
    public function resolveName(string $feature, int $id, array $idNames, Closure $translate): string
    {
        $name = $idNames[$feature][$id] ?? '';
        return $name !== '' ? $name : sprintf($translate('Unknown (ID %d)'), $id);
    }

    /**
     * Funktionen mit Namensliste: Funktion => Name der Property, Variable der ID, Variable des Namens.
     *
     * @return array<string,array{property: string, idVariable: string, nameVariable: string}>
     */
    public function nameLists(): array
    {
        $lists = [];
        foreach ($this->features as $id => $feature) {
            if (isset($feature['names']['property'], $feature['names']['idVariable'], $feature['names']['nameVariable'])) {
                $lists[(string) $id] = $feature['names'];
            }
        }
        return $lists;
    }

    /**
     * @param array<int,string> $ids
     * @return array{command: string, payload: string}|null
     */
    public function command(string $ident, mixed $value, array $ids): ?array
    {
        foreach ($this->normalize($ids) as $id) {
            $action = $this->features[$id]['variables'][$ident]['action'] ?? null;
            if (is_array($action)) {
                if (isset($action['pattern'])) {
                    // Text in der erwarteten Form, zum Beispiel eine Liste von Zahlen; Leerzeichen werden entfernt
                    $value = (string) preg_replace('/\s+/', '', (string) $value);
                    if (preg_match((string) $action['pattern'], $value) !== 1) {
                        return null;
                    }
                }
                // Wertebereich der Aktion: schützt vor Werten mit Nebenwirkung (zum Beispiel FpDelete 0 leert die Datenbank)
                if (is_numeric($value) && ((isset($action['min']) && $value < $action['min']) || (isset($action['max']) && $value > $action['max']))) {
                    return null;
                }
                $text = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
                return ['command' => (string) $action['command'], 'payload' => str_replace('{value}', $text, (string) ($action['payload'] ?? ''))];
            }
            foreach ($this->features[$id]['dynamic'] ?? [] as $rule) {
                $command = $this->dynamicCommand($rule, $ident, $value);
                if ($command !== null) {
                    return $command;
                }
            }
        }
        return null;
    }

    /**
     * Befehle, die beim Start einer Funktion den Zustand abfragen.
     *
     * @param array<int,string> $ids
     * @return array<int,array{command: string, payload: string}>
     */
    public function initCommands(array $ids): array
    {
        $commands = [];
        foreach ($this->normalize($ids) as $id) {
            foreach (($this->features[$id]['init'] ?? []) as $command) {
                $commands[] = ['command' => (string) $command['command'], 'payload' => (string) ($command['payload'] ?? '')];
            }
        }
        return $commands;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function lookup(array $data, string $path): mixed
    {
        $node = $data;
        foreach (explode('.', $path) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }
        return $node;
    }

    /**
     * Alle Treffer eines Pfades mit Platzhaltern: ein "*" in einem Segment steht für beliebigen Text, der als Treffer
     * ({1}, {2}, ...) zurückgegeben wird.
     *
     * @param array<int,string> $segments
     * @param array<int,string> $captures
     * @return array<int,array{0: array<int,string>, 1: mixed}>
     */
    private function expand(mixed $node, array $segments, array $captures = []): array
    {
        if ($segments === []) {
            return $node === null ? [] : [[$captures, $node]];
        }
        if (!is_array($node)) {
            return [];
        }
        $segment = array_shift($segments);
        if (!str_contains($segment, '*') && !str_contains($segment, '#')) {
            return array_key_exists($segment, $node) ? $this->expand($node[$segment], $segments, $captures) : [];
        }
        $matches = [];
        foreach ($node as $key => $child) {
            if (preg_match($this->segmentRegex($segment), (string) $key, $match) === 1) {
                $matches = array_merge($matches, $this->expand($child, $segments, array_merge($captures, array_slice($match, 1))));
            }
        }
        return $matches;
    }

    private function segmentRegex(string $segment): string
    {
        // "*" steht für beliebigen Text, "#" für eine Zahl
        return '/^' . str_replace(['\*', '\#'], ['(.+?)', '(\d+)'], preg_quote($segment, '/')) . '$/';
    }

    /**
     * @param array<int,string> $captures
     */
    private function fill(string $text, array $captures, bool $asIdent): string
    {
        foreach ($captures as $index => $capture) {
            $text = str_replace('{' . ($index + 1) . '}', $asIdent ? (string) preg_replace('/[^A-Za-z0-9_]/', '_', $capture) : $capture, $text);
        }
        return $text;
    }

    /**
     * Variablen einer dynamischen Regel für alle Treffer in den Daten.
     *
     * Regel: path (mit "*"), ident und caption (mit {1}, {2}), type, presentation, optional action. Mit keyField und valueField
     * wird aus einem Objekt wie {"PIN":3,"PWM":100} der Pin als {1} und der Wert gelesen.
     *
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $data
     * @return array<int,array<string,mixed>>
     */
    private function dynamicRecords(array $rule, array $data, Closure $translate): array
    {
        $matches = $this->expand($data, explode('.', (string) $rule['path']));
        if (isset($rule['keyField'], $rule['valueField'])) {
            $pairs = [];
            foreach ($matches as [$captures, $object]) {
                if (is_array($object) && isset($object[$rule['keyField']]) && array_key_exists($rule['valueField'], $object)) {
                    $pairs[] = [[(string) $object[$rule['keyField']]], $object[$rule['valueField']]];
                }
            }
            $matches = $pairs;
        }
        $records = [];
        foreach ($matches as [$captures, $value]) {
            if (is_array($value)) {
                continue;
            }
            $coerced = $this->coerce((string) $rule['type'], $value);
            if ($coerced === null) {
                continue;
            }
            $record = $this->record($this->fill((string) $rule['ident'], $captures, true), $rule, $coerced, $translate);
            $record['caption'] = $this->fill($record['caption'], $captures, false);
            $records[] = $record;
        }
        return $records;
    }

    /**
     * Befehl für eine Variable, die eine dynamische Regel angelegt hat. Der Ident liefert die Treffer ({1}, {2}) zurück.
     *
     * @param array<string,mixed> $rule
     * @return array{command: string, payload: string}|null
     */
    private function dynamicCommand(array $rule, string $ident, mixed $value): ?array
    {
        $action = $rule['action'] ?? null;
        if (!is_array($action)) {
            return null;
        }
        $regex = '/^' . (string) preg_replace('/\\\\\{\d+\\\\\}/', '([A-Za-z0-9_]+?)', preg_quote((string) $rule['ident'], '/')) . '$/';
        if (preg_match($regex, $ident, $match) !== 1) {
            return null;
        }
        if (is_numeric($value) && ((isset($action['min']) && $value < $action['min']) || (isset($action['max']) && $value > $action['max']))) {
            return null;
        }
        if (is_bool($value) && isset($action['boolean'])) {
            $text = (string) $action['boolean'][$value ? 1 : 0];
        } else {
            $text = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }
        $payload = $this->fill((string) ($action['payload'] ?? ''), array_slice($match, 1), false);
        return ['command' => (string) $action['command'], 'payload' => str_replace('{value}', $text, $payload)];
    }

    private function coerce(string $type, mixed $value): mixed
    {
        return match ($type) {
            'boolean' => is_array($value) ? null : in_array(strtolower((string) $value), ['1', 'on', 'true'], true),
            'integer' => is_numeric($value) ? (int) $value : null,
            'float'   => is_numeric($value) ? (float) $value : null,
            default   => is_array($value) ? json_encode($value) : (string) $value
        };
    }

    /**
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     */
    private function presentation(array $spec, Closure $translate): array
    {
        if (($spec['kind'] ?? '') === 'enumeration') {
            $options = [];
            foreach (($spec['options'] ?? []) as [$value, $caption]) {
                $options[] = [$value, $translate((string) $caption)];
            }
            return TasmotaV2Presentation::enumeration($options);
        }
        if (($spec['kind'] ?? '') === 'status') {
            return TasmotaV2Presentation::status($translate((string) ($spec['off'] ?? 'Off')), $translate((string) ($spec['on'] ?? 'On')));
        }
        return TasmotaV2Presentation::fromSpec($spec);
    }

    /**
     * @param array<string,mixed> $variable
     * @return array<string,mixed>
     */
    private function record(string $ident, array $variable, mixed $value, Closure $translate): array
    {
        return [
            'ident'        => $ident,
            'caption'      => $translate((string) $variable['caption']),
            'type'         => self::TYPES[(string) $variable['type']] ?? 3,
            'value'        => $value,
            'presentation' => $this->presentation((array) ($variable['presentation'] ?? []), $translate),
            'action'       => isset($variable['action'])
        ];
    }
}
