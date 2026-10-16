<?php

declare(strict_types=1);

/**
 * Senden von MQTT-Paketen an den MQTT-Parent (IPSModuleStrict).
 *
 * Bei IPSModuleStrict ist der Datenfluss hex-kodiert: "Payload" wird mit bin2hex() gesendet und mit hex2bin()
 * gelesen, das "Topic" bleibt Klartext (Symcon-Doku, Unterschiede IPSModule/IPSModuleStrict).
 */
trait TasmotaV2MqttTransport
{
    protected function publishMQTT(string $topic, string $payload, bool $retain = false): bool
    {
        $packet = [
            'DataID'           => '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}',
            'PacketType'       => 3,
            'QualityOfService' => 0,
            'Retain'           => $retain,
            'Topic'            => $topic,
            'Payload'          => bin2hex($payload)
        ];
        $json = json_encode($packet, JSON_UNESCAPED_SLASHES);
        $this->SendDebug(__FUNCTION__, $json, 0);
        if (!$this->HasActiveParent()) {
            $this->SendDebug(__FUNCTION__, 'Parent nicht aktiv', 0);
            return false;
        }
        return @$this->SendDataToParent($json) !== false;
    }

    /**
     * @return array{Topic: string, Payload: string}|null
     */
    protected function decodeMQTT(string $json): ?array
    {
        $packet = json_decode($json, true);
        if (!is_array($packet) || !isset($packet['Topic']) || !is_string($packet['Topic'])) {
            return null;
        }
        $payload = hex2bin((string) ($packet['Payload'] ?? ''));
        return ['Topic' => $packet['Topic'], 'Payload' => $payload === false ? '' : $payload];
    }
}
