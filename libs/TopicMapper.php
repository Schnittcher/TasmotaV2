<?php

declare(strict_types=1);

/**
 * Bildet das FullTopic-Muster eines Tasmota-Geräts auf konkrete MQTT-Topics ab.
 *
 * Tokens: %prefix% (cmnd, stat, tele), %topic%. Weitere Tokens (%hostname%, %id%) werden über $tokens aufgelöst,
 * bleiben sie unbekannt, passen sie im Empfang auf ein beliebiges Segment.
 */
final class TasmotaV2TopicMapper
{
    private string $template;

    /**
     * @param array<string,string> $tokens zusätzliche Tokens, zum Beispiel ['%hostname%' => 'tasmota-1234']
     * @param array<int,string>    $prefixes Befehl, Status, Telemetrie
     */
    public function __construct(
        string $fullTopic,
        private string $topic,
        private array $tokens = [],
        private array $prefixes = ['cmnd', 'stat', 'tele']
    ) {
        $template = trim($fullTopic, '/');
        if ($template === '') {
            $template = '%prefix%/%topic%';
        }
        // Ohne %prefix% kann Tasmota Befehl und Antwort nicht unterscheiden, das Muster wäre unbrauchbar
        if (!str_contains($template, '%prefix%')) {
            $template = '%prefix%/' . $template;
        }
        $this->template = $template;
    }

    public function isUsable(): bool
    {
        return $this->topic !== '' && str_contains($this->template, '%topic%');
    }

    public function commandTopic(string $command): string
    {
        return $this->fill($this->prefixes[0]) . '/' . $command;
    }

    /**
     * @return array{kind: string, suffix: string}|null kind ist "stat" oder "tele"
     */
    public function parse(string $topic): ?array
    {
        if (!$this->isUsable()) {
            return null;
        }
        if (preg_match('~^' . $this->bodyRegex(true) . '/(?<suffix>.+)$~', $topic, $match) !== 1) {
            return null;
        }
        return ['kind' => $match['kind'], 'suffix' => $match['suffix']];
    }

    /**
     * Teil für SetReceiveDataFilter. Der Filter wird gegen den JSON-String des Datenpakets geprüft,
     * das Topic steht dort im Klartext und ohne maskierte Schrägstriche.
     */
    public function dataFilterFragment(): string
    {
        // Ohne benannte Gruppe: Welche Syntax der Filter des Kernels versteht, ist nicht dokumentiert
        return '"Topic":"' . $this->bodyRegex(false) . '/[^"]+"';
    }

    private function bodyRegex(bool $named): string
    {
        $quoted = preg_quote($this->template, '~');
        $kinds = ($named ? '(?<kind>' : '(?:') . preg_quote($this->prefixes[1], '~') . '|' . preg_quote($this->prefixes[2], '~') . ')';
        $body = str_replace(['%prefix%', '%topic%'], [$kinds, preg_quote($this->topic, '~')], $quoted);
        foreach ($this->tokens as $token => $value) {
            $body = str_replace(preg_quote($token, '~'), preg_quote($value, '~'), $body);
        }
        // Nicht aufgelöste Tokens
        return (string) preg_replace('~%[a-z]+%~i', '[^/]+', $body);
    }

    private function fill(string $prefix): string
    {
        $result = str_replace(['%prefix%', '%topic%'], [$prefix, $this->topic], $this->template);
        return strtr($result, $this->tokens);
    }
}
