<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/TopicMapper.php';
require_once __DIR__ . '/../libs/DiscoveryModel.php';
require_once __DIR__ . '/../libs/MqttTransport.php';

class TasmotaConfiguratorV2 extends IPSModuleStrict
{
    use TasmotaV2MqttTransport;

    private const DEVICE_MODULE = '{E89467A8-281F-4B39-90E8-2803D9F03711}';

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('DiscoveryPrefix', 'tasmota/discovery');
        $this->RegisterPropertyString('GroupTopic', 'tasmotas');

        // MAC => Discovery-Konfiguration
        $this->RegisterAttributeString('Devices', '{}');
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        $prefix = trim($this->ReadPropertyString('DiscoveryPrefix'), '/');
        if ($prefix === '') {
            $this->SetReceiveDataFilter('.*TasmotaV2NoDiscoveryPrefix.*');
            $this->SetStatus(104);
            return;
        }
        $this->SetReceiveDataFilter('.*"Topic":"' . preg_quote($prefix, '~') . '/[^/]+/config".*');
        $this->SetStatus(102);
    }

    public function ReceiveData(string $JSONString): string
    {
        $message = $this->decodeMQTT($JSONString);
        if ($message === null) {
            return '';
        }
        $model = TasmotaV2DiscoveryModel::fromJson($message['Payload']);
        if ($model === null) {
            return '';
        }
        $devices = $this->devices();
        $devices[$model->mac()] = json_decode($model->toJson(), true);
        $this->WriteAttributeString('Devices', json_encode($devices));
        $this->UpdateFormField('Configurator', 'values', json_encode($this->formValues()));
        return '';
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        if (!is_array($form)) {
            return '{}';
        }
        foreach ($form['actions'] as &$element) {
            if (($element['name'] ?? '') === 'Configurator') {
                $element['values'] = $this->formValues();
            }
        }
        unset($element);
        return json_encode($form);
    }

    /**
     * Veranlasst alle Geräte der Gruppe, ihre Discovery-Nachricht neu zu senden (Befehl Discover an das GroupTopic).
     * Geräte mit eigenem Group-Topic müssen einzeln über die Instanz angefragt werden.
     */
    public function Search(): bool
    {
        $group = trim($this->ReadPropertyString('GroupTopic'));
        if ($group === '') {
            return false;
        }
        $mapper = new TasmotaV2TopicMapper('%prefix%/%topic%/', $group);
        return $this->publishMQTT($mapper->commandTopic('Discover'), '1');
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function devices(): array
    {
        $devices = json_decode($this->ReadAttributeString('Devices'), true);
        return is_array($devices) ? $devices : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function formValues(): array
    {
        $existing = [];
        foreach (IPS_GetInstanceListByModuleID(self::DEVICE_MODULE) as $instanceID) {
            $existing[trim((string) IPS_GetProperty($instanceID, 'Topic'))] = $instanceID;
        }

        $values = [];
        foreach ($this->devices() as $config) {
            $model = TasmotaV2DiscoveryModel::fromJson(json_encode($config));
            if ($model === null) {
                continue;
            }
            $names = $model->friendlyNames();
            $name = $names[0] ?? ($model->deviceName() !== '' ? $model->deviceName() : $model->topic());
            $values[] = [
                'Topic'      => $model->topic(),
                'Name'       => $name,
                'IP'         => $model->ip(),
                'Model'      => $model->moduleName(),
                'Firmware'   => $model->firmware(),
                'instanceID' => $existing[$model->topic()] ?? 0,
                'create'     => [
                    'moduleID'      => self::DEVICE_MODULE,
                    'name'          => $name,
                    'configuration' => [
                        'Topic'           => $model->topic(),
                        'FullTopic'       => $model->fullTopic() !== '' ? $model->fullTopic() : '%prefix%/%topic%/',
                        'DiscoveryPrefix' => trim($this->ReadPropertyString('DiscoveryPrefix'), '/')
                    ]
                ]
            ];
        }
        return $values;
    }
}
