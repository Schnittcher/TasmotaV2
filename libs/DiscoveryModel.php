<?php

declare(strict_types=1);

/**
 * Fähigkeitsmodell eines Tasmota-Geräts aus der Discovery-Nachricht (tasmota/discovery/<MAC>/config).
 *
 * Schlüssel (Quelle: Tasmota xdrv_12_discovery.ino): ip, dn, fn[], hn, mac, md, ty, if, ofln, onln, state[4], sw, t, ft,
 * tp[3], rl[], swc[], swn[], btn[], so{}, lk, lt_st, sho[], sht[][], ver.
 */
final class TasmotaV2DiscoveryModel
{
    public const RELAY_NONE = 0;
    public const RELAY_RELAY = 1;
    public const RELAY_LIGHT = 2;
    public const RELAY_SHUTTER = 3;

    private function __construct(private array $config)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Liefert null bei ungültigem JSON, fehlenden Pflichtfeldern oder unbekannter Formatversion.
     */
    public static function fromJson(string $json): ?self
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['t'], $data['mac']) || !is_string($data['t']) || !is_string($data['mac'])) {
            return null;
        }
        if (($data['ver'] ?? 1) !== 1) {
            return null;
        }
        return new self($data);
    }

    public function isEmpty(): bool
    {
        return $this->config === [];
    }

    public function toJson(): string
    {
        return json_encode($this->config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function topic(): string
    {
        return (string) ($this->config['t'] ?? '');
    }

    public function fullTopic(): string
    {
        return (string) ($this->config['ft'] ?? '');
    }

    public function mac(): string
    {
        return (string) ($this->config['mac'] ?? '');
    }

    public function ip(): string
    {
        return (string) ($this->config['ip'] ?? '');
    }

    public function hostname(): string
    {
        return (string) ($this->config['hn'] ?? '');
    }

    public function deviceName(): string
    {
        return (string) ($this->config['dn'] ?? '');
    }

    public function moduleName(): string
    {
        return (string) ($this->config['md'] ?? '');
    }

    public function firmware(): string
    {
        return (string) ($this->config['sw'] ?? '');
    }

    /**
     * @return array<int,string>
     */
    public function friendlyNames(): array
    {
        $names = [];
        foreach (($this->config['fn'] ?? []) as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }
        return $names;
    }

    public function onlinePayload(): string
    {
        return (string) ($this->config['onln'] ?? 'Online');
    }

    public function offlinePayload(): string
    {
        return (string) ($this->config['ofln'] ?? 'Offline');
    }

    /**
     * Texte für die Zustände 0 bis 3 (OFF, ON, TOGGLE, HOLD).
     *
     * @return array<int,string>
     */
    public function stateTexts(): array
    {
        $texts = ['OFF', 'ON', 'TOGGLE', 'HOLD'];
        foreach (($this->config['state'] ?? []) as $index => $text) {
            if (is_string($text) && $text !== '' && isset($texts[$index])) {
                $texts[$index] = $text;
            }
        }
        return $texts;
    }

    /**
     * @return array<int,int> Relaytyp je Slot, siehe RELAY_*
     */
    public function relayTypes(): array
    {
        $types = [];
        foreach (($this->config['rl'] ?? []) as $type) {
            $types[] = (int) $type;
        }
        return $types;
    }

    /**
     * Anzahl der Relais, die als Power<n> bedient werden (Relais und Lichter, ohne Rollladenrelais).
     */
    public function powerCount(): int
    {
        $count = 0;
        foreach ($this->relayTypes() as $type) {
            if ($type === self::RELAY_RELAY || $type === self::RELAY_LIGHT) {
                $count++;
            }
        }
        return $count;
    }

    public function hasLight(): bool
    {
        return in_array(self::RELAY_LIGHT, $this->relayTypes(), true);
    }

    /**
     * Ein Rollladen belegt zwei Relais.
     */
    public function shutterCount(): int
    {
        $relays = count(array_filter($this->relayTypes(), static fn (int $type): bool => $type === self::RELAY_SHUTTER));
        return intdiv($relays, 2);
    }

    public function lightSubtype(): int
    {
        return (int) ($this->config['lt_st'] ?? 0);
    }

    /**
     * Nummern (ab 1) der Taster, die mit SetOption73 als MQTT-Taster gemeldet werden.
     *
     * @return array<int,int>
     */
    public function buttons(): array
    {
        $buttons = [];
        foreach (($this->config['btn'] ?? []) as $index => $flag) {
            if ((int) $flag === 1) {
                $buttons[] = (int) $index + 1;
            }
        }
        return $buttons;
    }

    /**
     * Nummern (ab 1) der Schalter, die mit SetOption114 als MQTT-Schalter gemeldet werden, mit ihrem Namen.
     *
     * @return array<int,string> Nummer => Name (leer, wenn der Standardname gilt)
     */
    public function switches(): array
    {
        $switches = [];
        $names = $this->config['swn'] ?? [];
        foreach (($this->config['swc'] ?? []) as $index => $mode) {
            if ((int) $mode !== -1) {
                $name = $names[$index] ?? '';
                $switches[(int) $index + 1] = is_string($name) ? $name : '';
            }
        }
        return $switches;
    }

    /**
     * Boolesche Kennzeichen der Discovery (zum Beispiel "if" für Sonoff iFan02/iFan03, "ty" für Tuya).
     */
    public function flag(string $key): bool
    {
        return (int) ($this->config[$key] ?? 0) === 1;
    }

    public function setOption(int $number): ?int
    {
        $options = $this->config['so'] ?? [];
        if (is_array($options) && array_key_exists((string) $number, $options)) {
            return (int) $options[(string) $number];
        }
        return null;
    }
}
