<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/TopicMapper.php';
require_once __DIR__ . '/../libs/DiscoveryModel.php';
require_once __DIR__ . '/../libs/MessageParser.php';
require_once __DIR__ . '/../libs/FeatureCatalog.php';
require_once __DIR__ . '/../libs/ResponseMatcher.php';
require_once __DIR__ . '/../libs/CommandTable.php';
require_once __DIR__ . '/../libs/MqttTransport.php';

class TasmotaV2 extends IPSModuleStrict
{
    use TasmotaV2MqttTransport;

    // Obergrenze für automatisch angelegte Variablen je Instanz (Schutz vor ausufernden Sensorpayloads)
    private const MAX_VARIABLES = 400;

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('Topic', '');
        $this->RegisterPropertyString('FullTopic', '%prefix%/%topic%/');
        $this->RegisterPropertyString('DiscoveryPrefix', 'tasmota/discovery');
        $this->RegisterPropertyBoolean('AutoSensors', true);
        $this->RegisterPropertyString('ExcludeSensors', '');
        $this->RegisterPropertyBoolean('SystemVariables', false);
        $this->RegisterPropertyBoolean('RetainCommands', false);
        $this->RegisterPropertyString('ExtraFeatures', '');
        // Namenslisten der Funktionen des Funktionskatalogs (Liste mit Id und Name)
        foreach ($this->catalog()->nameLists() as $list) {
            $this->RegisterPropertyString($list['property'], '[]');
        }

        // Zuletzt empfangene Discovery-Konfiguration und Signaturen der angelegten Variablen
        $this->RegisterAttributeString('Model', '');
        $this->RegisterAttributeString('Variables', '{}');
        // Aus der GPIO-Belegung erkannte Funktionen des Funktionskatalogs
        $this->RegisterAttributeString('Features', '[]');
        // Funktionen, deren Daten das Gerät geliefert hat (zum Beispiel ENERGY), dauerhaft gemerkt
        $this->RegisterAttributeString('SeenFeatures', '[]');

        // Einmalige, verzögerte Startabfrage: Beim Start von IP-Symcon und beim Neuladen des Moduls ist der Parent
        // oft noch nicht aktiv, die Abfrage in ApplyChanges() ginge dann verloren
        $this->RegisterTimer('InitialState', 0, 'TASV2_RequestInitialState($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        // Wird die Instanz erst nach der Konfiguration mit dem Parent verbunden (Anlegen über den Konfigurator),
        // meldet der Kernel das mit IM_CONNECT an die Instanz selbst
        $this->RegisterMessage($this->InstanceID, IM_CONNECT);
        $connection = (int) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($connection > 0) {
            $this->RegisterMessage($connection, IM_CHANGESTATUS);
        }

        $topic = trim($this->ReadPropertyString('Topic'));
        if ($topic === '') {
            // Kein Topic: nichts empfangen
            $this->SetReceiveDataFilter('.*TasmotaV2NoTopicConfigured.*');
            $this->SetTimerInterval('InitialState', 0);
            $this->SetStatus(104);
            return;
        }

        $this->applyReceiveFilter();
        $this->ensureRecords([$this->onlineRecord()]);
        $this->ensureRecords($this->parser()->skeleton());
        $this->refreshNames();
        $this->SetStatus(102);

        if (IPS_GetKernelRunlevel() === KR_READY) {
            if ($this->HasActiveParent()) {
                $this->RequestState();
            }
            $this->SetTimerInterval('InitialState', 8000);
        }
    }

    /**
     * Statisches Formular aus form.json. Nur die Listen der Funktionen aus dem Funktionskatalog werden ein-
     * oder ausgeblendet, damit ein Gerät ohne die jeweilige Hardware sie nicht sieht, und die erkannten
     * Funktionen angezeigt.
     */
    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        if (!is_array($form)) {
            return '{}';
        }
        $active = $this->activeFeatures();
        $hidden = [];
        // Funktionen, die für jedes Gerät gelten, zählen nicht zu den erkannten Funktionen
        $shown = array_values(array_diff($active, $this->catalog()->alwaysActive()));
        foreach ($this->catalog()->nameLists() as $feature => $list) {
            if (!in_array($feature, $active, true)) {
                $hidden[$list['property']] = true;
            }
        }
        foreach ($form['elements'] as $index => $element) {
            if (isset($hidden[$element['name'] ?? ''])) {
                $form['elements'][$index]['visible'] = false;
            }
            if (($element['name'] ?? '') === 'DiscoveryInfo') {
                $model = $this->model();
                $form['elements'][$index]['caption'] = $model->isEmpty()
                    ? $this->Translate('Discovery: not received yet. "Request discovery" asks the device to send it.')
                    : sprintf($this->Translate('Discovery: received (%s, firmware %s)'), $model->moduleName() !== '' ? $model->moduleName() : '-', $model->firmware() !== '' ? $model->firmware() : '-');
            }
            if (($element['name'] ?? '') === 'DetectedFeatures') {
                $captions = array_map(fn (string $id): string => $this->Translate($this->catalog()->caption($id)), $shown);
                $form['elements'][$index]['caption'] = $this->Translate('Detected features') . ': ' . ($captions === [] ? $this->Translate('none') : implode(', ', $captions));
            }
        }
        return json_encode($form);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        $connection = (int) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($Message === IM_CONNECT && $SenderID === $this->InstanceID && $connection > 0) {
            // Neuer Parent: dessen Statuswechsel beobachten, damit der Startzustand auch bei einer später aktiven Verbindung abgefragt wird
            $this->RegisterMessage($connection, IM_CHANGESTATUS);
        }
        $isKernelReady = $Message === IPS_KERNELMESSAGE && ($Data[0] ?? 0) === KR_READY;
        $isConnected = $Message === IM_CONNECT && $SenderID === $this->InstanceID && $this->HasActiveParent();
        $isParentActive = $Message === IM_CHANGESTATUS && ($Data[0] ?? 0) === 102 && $SenderID === $connection;
        if (($isKernelReady || $isConnected || $isParentActive) && trim($this->ReadPropertyString('Topic')) !== '') {
            $this->RequestState();
        }
    }

    public function ReceiveData(string $JSONString): string
    {
        $message = $this->decodeMQTT($JSONString);
        if ($message === null) {
            return '';
        }
        $this->SendDebug('Receive ' . $message['Topic'], $message['Payload'], 0);

        $discoveryPrefix = trim($this->ReadPropertyString('DiscoveryPrefix'), '/');
        if ($discoveryPrefix !== '' && str_starts_with($message['Topic'], $discoveryPrefix . '/')) {
            if (str_ends_with($message['Topic'], '/config')) {
                $this->handleDiscovery($message['Payload']);
            } elseif (str_ends_with($message['Topic'], '/sensors')) {
                $this->handleDiscoverySensors($message['Topic'], $message['Payload']);
            }
            return '';
        }

        $parsed = $this->mapper()->parse($message['Topic']);
        if ($parsed === null) {
            return '';
        }
        $this->offerResponse($parsed['kind'], $parsed['suffix'], $message['Payload']);
        $decoded = json_decode($message['Payload'], true);
        if (is_array($decoded)) {
            $this->updateSeenFeatures($this->catalog()->detectFromData($decoded));
        }
        if (is_array($decoded) && isset($decoded['SetOption19']) && $this->model()->isEmpty()) {
            // SetOption19 OFF heißt: Discovery ist am Gerät eingeschaltet. Sie erneut senden zu lassen ändert dann nichts am Gerät.
            // Ein bewusst abgeschaltetes Gerät (ON) wird nicht verändert.
            if (strcasecmp((string) $decoded['SetOption19'], 'OFF') === 0) {
                $this->RequestDiscovery();
            }
            return '';
        }
        $components = is_array($decoded) ? TasmotaV2FeatureCatalog::componentsFromResult($decoded) : null;
        if ($components !== null) {
            $this->updateFeatures($components);
            return '';
        }
        $this->ensureRecords($this->parser()->parse($parsed['kind'], $parsed['suffix'], $message['Payload']), true);
        return '';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        $dimmer = null;
        if (@$this->GetIDForIdent('Dimmer') !== false) {
            $dimmer = $this->GetValue('Dimmer');
        }
        $command = TasmotaV2CommandTable::resolve($Ident, $Value, is_numeric($dimmer) ? $dimmer : null)
            ?? $this->catalog()->command($Ident, $Value, $this->activeFeatures());
        if ($command === null) {
            $this->SendDebug(__FUNCTION__, 'Unbekannter Ident: ' . $Ident, 0);
            return;
        }
        $this->SendCommand($command['command'], $command['payload']);
    }

    /**
     * Sendet einen beliebigen Tasmota-Befehl an das Gerät (cmnd/<Topic>/<Befehl>). Die Antwort kommt asynchron.
     */
    public function SendCommand(string $Command, string $Payload): bool
    {
        $mapper = $this->mapper();
        if (!$mapper->isUsable() || trim($Command) === '') {
            return false;
        }
        return $this->publishMQTT($mapper->commandTopic(trim($Command)), $Payload, $this->ReadPropertyBoolean('RetainCommands'));
    }

    /**
     * Sendet einen Tasmota-Befehl und wartet auf die Antwort des Geräts.
     *
     * Für Skripte, die das Ergebnis sofort brauchen: eine Einstellung lesen, bevor man sie ändert, Befehle prüfen
     * oder Abfragen, für die es keine Variable gibt (zum Beispiel "Status 2", "Template", "Gpio").
     * Der Aufruf blockiert, bis die passende Antwort eintrifft oder die Zeit abgelaufen ist.
     *
     * @param int $Timeout Wartezeit in Millisekunden (100 bis 30000)
     * @return string Antwort des Geräts (JSON-Text), leer bei Zeitüberschreitung, ohne Antwort oder wenn der Parent nicht aktiv ist
     */
    public function SendCommandWait(string $Command, string $Payload, int $Timeout): string
    {
        $Command = trim($Command);
        if ($Command === '' || !$this->HasActiveParent()) {
            return '';
        }
        $timeout = max(100, min(30000, $Timeout));
        // Der Eintrag wird vor dem Senden angelegt, damit auch eine sehr schnelle Antwort nicht verloren geht
        $id = uniqid('w', true);
        $this->changeWaiting(static function (array $waiting) use ($id, $Command): array
        {
            $waiting[$id] = ['command' => $Command, 'response' => null];
            return $waiting;
        });

        $response = '';
        if ($this->SendCommand($Command, $Payload)) {
            $deadline = microtime(true) + $timeout / 1000;
            do {
                IPS_Sleep(25);
                $entry = $this->waiting()[$id] ?? null;
                if (is_array($entry) && is_string($entry['response'])) {
                    $response = $entry['response'];
                    break;
                }
            } while (microtime(true) < $deadline);
        }
        $this->changeWaiting(static function (array $waiting) use ($id): array
        {
            unset($waiting[$id]);
            return $waiting;
        });
        if ($response === '') {
            $this->SendDebug(__FUNCTION__, 'Keine Antwort auf ' . $Command, 0);
        }
        return $response;
    }

    /**
     * Fragt den gesamten Zustand ab (Status 0): Relais, Licht, Rollläden, Sensoren, WLAN.
     */
    public function RequestState(): bool
    {
        // Status 0 liefert den Zustand, Gpio die Hardware für den Funktionskatalog
        $ok = $this->SendCommand('Status', '0');
        $ok = $this->SendCommand('Gpio', '') && $ok;
        for ($n = 1; $n <= $this->model()->shutterCount(); $n++) {
            // Die Sperre je Rollladen steht nicht in Status 0
            $ok = $this->SendCommand('ShutterLock' . $n, '') && $ok;
        }
        if ($this->model()->isEmpty()) {
            // Ohne Discovery-Modell fehlen Taster, Schalter und Lichttyp. SetOption19 zeigt, ob das Gerät die Discovery sendet
            $ok = $this->SendCommand('SetOption19', '') && $ok;
        }
        return $this->sendInitCommands() && $ok;
    }

    /**
     * Wird vom Timer einmal kurz nach dem Start aufgerufen und fragt den Zustand ab, falls der Parent inzwischen aktiv ist.
     */
    public function RequestInitialState(): void
    {
        $this->SetTimerInterval('InitialState', 0);
        if (trim($this->ReadPropertyString('Topic')) !== '' && $this->HasActiveParent()) {
            $this->RequestState();
        }
    }

    /**
     * Veranlasst das Gerät, seine Discovery-Nachricht neu zu senden.
     */
    public function RequestDiscovery(): bool
    {
        return $this->SendCommand('Discover', '1');
    }

    /**
     * @return array<string,array{command: string, response: string|null}> wartende Aufrufe von SendCommandWait
     */
    private function waiting(): array
    {
        $waiting = json_decode($this->GetBuffer('Waiting'), true);
        return is_array($waiting) ? $waiting : [];
    }

    /**
     * Ändert die Liste der wartenden Aufrufe. ReceiveData und die wartenden Aufrufe laufen in verschiedenen Threads.
     */
    private function changeWaiting(callable $change): void
    {
        $key = 'TASV2Wait' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($key, 1000)) {
            return;
        }
        $this->SetBuffer('Waiting', json_encode($change($this->waiting())));
        IPS_SemaphoreLeave($key);
    }

    /**
     * Gibt eine empfangene Nachricht an wartende Aufrufe weiter, wenn sie zur Antwort auf deren Befehl passt.
     */
    private function offerResponse(string $kind, string $suffix, string $payload): void
    {
        $waiting = $this->waiting();
        if ($waiting === []) {
            return;
        }
        $matching = [];
        foreach ($waiting as $id => $entry) {
            if ($entry['response'] === null && TasmotaV2ResponseMatcher::matches($entry['command'], $kind, $suffix, $payload)) {
                $matching[] = $id;
            }
        }
        if ($matching === []) {
            return;
        }
        $this->changeWaiting(static function (array $waiting) use ($matching, $payload): array
        {
            foreach ($matching as $id) {
                if (isset($waiting[$id]) && $waiting[$id]['response'] === null) {
                    $waiting[$id]['response'] = $payload;
                }
            }
            return $waiting;
        });
    }

    /**
     * Funktionen des Funktionskatalogs: aus der GPIO-Belegung erkannte und von Hand ergänzte.
     *
     * @return array<int,string>
     */
    private function activeFeatures(): array
    {
        $detected = json_decode($this->ReadAttributeString('Features'), true);
        $extra = explode(',', $this->ReadPropertyString('ExtraFeatures'));
        $seen = json_decode($this->ReadAttributeString('SeenFeatures'), true);
        // Funktionen, die die Discovery über ein Kennzeichen ankündigt (zum Beispiel iFan), werden nicht gespeichert
        $announced = $this->catalog()->detectFromDiscovery($this->model());
        return $this->catalog()->normalize(array_merge($this->catalog()->alwaysActive(), is_array($detected) ? $detected : [], is_array($seen) ? $seen : [], $announced, $extra));
    }

    /**
     * Namen zu IDs aus den Listen der Konfiguration: Funktion => ID => Name.
     *
     * @return array<string,array<int,string>>
     */
    private function idNames(): array
    {
        $names = [];
        foreach ($this->catalog()->nameLists() as $feature => $list) {
            $rows = json_decode($this->ReadPropertyString($list['property']), true);
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (isset($row['Id'], $row['Name']) && is_numeric($row['Id']) && trim((string) $row['Name']) !== '') {
                    $names[$feature][(int) $row['Id']] = trim((string) $row['Name']);
                }
            }
        }
        return $names;
    }

    /**
     * Setzt die Namensvariablen neu, wenn sich die Liste in der Konfiguration geändert hat.
     */
    private function refreshNames(): void
    {
        $names = $this->idNames();
        $translate = fn (string $text): string => $this->Translate($text);
        foreach ($this->catalog()->nameLists() as $feature => $list) {
            if (!in_array($feature, $this->activeFeatures(), true) || @$this->GetIDForIdent($list['idVariable']) === false || @$this->GetIDForIdent($list['nameVariable']) === false) {
                continue;
            }
            $id = (int) $this->GetValue($list['idVariable']);
            if ($id > 0) {
                $this->SetValue($list['nameVariable'], $this->catalog()->resolveName($feature, $id, $names, $translate));
            }
        }
    }

    private function catalog(): TasmotaV2FeatureCatalog
    {
        return TasmotaV2FeatureCatalog::fromFile(__DIR__ . '/../libs/data/features.json');
    }

    /**
     * Merkt sich Funktionen, deren Daten das Gerät geliefert hat, und legt ihre Variablen an. Gebraucht werden sie nur dann,
     * zum Beispiel die Leistungsgrenzen nur bei Geräten mit Energiemessung.
     *
     * @param array<int,string> $ids
     */
    private function updateSeenFeatures(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $seen = json_decode($this->ReadAttributeString('SeenFeatures'), true);
        $seen = is_array($seen) ? $seen : [];
        $merged = array_values(array_unique(array_merge($seen, $ids)));
        if ($merged === $seen) {
            return;
        }
        $this->SendDebug('Funktionen aus Daten erkannt', implode(', ', $ids), 0);
        $this->WriteAttributeString('SeenFeatures', json_encode($merged));
        $this->ensureRecords($this->parser()->skeleton());
        $this->sendInitCommands();
    }

    /**
     * @param array<int,string> $components GPIO-Komponenten aus der Antwort auf "Gpio"
     */
    private function updateFeatures(array $components): void
    {
        $detected = $this->catalog()->detect($components);
        $previous = json_decode($this->ReadAttributeString('Features'), true);
        if (is_array($previous) && $previous === $detected) {
            return;
        }
        $this->SendDebug('Funktionen erkannt', implode(', ', $detected), 0);
        $this->WriteAttributeString('Features', json_encode($detected));
        $this->ensureRecords($this->parser()->skeleton());
        $this->sendInitCommands();
    }

    private function sendInitCommands(): bool
    {
        $ok = true;
        foreach ($this->catalog()->initCommands($this->activeFeatures()) as $command) {
            $ok = $this->SendCommand($command['command'], $command['payload']) && $ok;
        }
        return $ok;
    }

    private function mapper(): TasmotaV2TopicMapper
    {
        $model = $this->model();
        $tokens = [];
        if ($model->hostname() !== '') {
            $tokens['%hostname%'] = $model->hostname();
        }
        return new TasmotaV2TopicMapper($this->ReadPropertyString('FullTopic'), trim($this->ReadPropertyString('Topic')), $tokens);
    }

    private function model(): TasmotaV2DiscoveryModel
    {
        return TasmotaV2DiscoveryModel::fromJson($this->ReadAttributeString('Model')) ?? TasmotaV2DiscoveryModel::empty();
    }

    private function parser(): TasmotaV2MessageParser
    {
        $units = json_decode((string) file_get_contents(__DIR__ . '/../libs/data/sensor_units.json'), true) ?: [];
        $exclude = array_filter(array_map('trim', explode(',', $this->ReadPropertyString('ExcludeSensors'))));
        return new TasmotaV2MessageParser(
            $this->model(),
            $units,
            fn (string $text): string => $this->Translate($text),
            $this->ReadPropertyBoolean('SystemVariables'),
            $this->ReadPropertyBoolean('AutoSensors'),
            array_values($exclude),
            $this->catalog(),
            $this->activeFeatures(),
            $this->idNames()
        );
    }

    private function applyReceiveFilter(): void
    {
        $fragments = [$this->mapper()->dataFilterFragment()];
        $prefix = trim($this->ReadPropertyString('DiscoveryPrefix'), '/');
        if ($prefix !== '') {
            $mac = $this->model()->mac();
            $fragments[] = '"Topic":"' . preg_quote($prefix, '~') . '/' . ($mac !== '' ? preg_quote($mac, '~') : '[^/]+') . '/(?:config|sensors)"';
        }
        $filter = '.*(' . implode('|', $fragments) . ').*';
        $this->SendDebug('Filter', $filter, 0);
        $this->SetReceiveDataFilter($filter);
    }

    /**
     * Die Discovery-Nachricht "sensors" ({"sn": <Sensordaten>, "ver": 1}) zeigt, welche Sensoren das Gerät hat. Sie ist retained und
     * kann alt sein, deshalb werden aus ihr nur die Funktionen erkannt und die Variablen angelegt, aber keine Werte übernommen.
     */
    private function handleDiscoverySensors(string $topic, string $payload): void
    {
        $model = $this->model();
        $parts = explode('/', $topic);
        // <Präfix>/<MAC>/sensors: erst nach der config-Nachricht, die die MAC des Geräts liefert
        if ($model->isEmpty() || count($parts) < 3 || strcasecmp($parts[count($parts) - 2], $model->mac()) !== 0) {
            return;
        }
        $decoded = json_decode($payload, true);
        $sensors = is_array($decoded) ? ($decoded['sn'] ?? null) : null;
        if (!is_array($sensors)) {
            return;
        }
        $this->updateSeenFeatures($this->catalog()->detectFromData($sensors));
        if ($this->ReadPropertyBoolean('AutoSensors')) {
            $this->ensureRecords($this->parser()->parse('tele', 'SENSOR', json_encode($sensors)), false);
        }
    }

    private function handleDiscovery(string $payload): void
    {
        // Eine leere Nachricht löscht die retained Discovery am Broker (Discovery am Gerät ausgeschaltet), das Modell bleibt
        $model = TasmotaV2DiscoveryModel::fromJson($payload);
        if ($model === null || $model->topic() !== trim($this->ReadPropertyString('Topic'))) {
            return;
        }
        $previousMac = $this->model()->mac();
        $previousFeatures = $this->activeFeatures();
        $this->WriteAttributeString('Model', $model->toJson());
        if ($previousMac !== $model->mac()) {
            $this->applyReceiveFilter();
        }
        $this->ensureRecords($this->parser()->skeleton());
        // Kündigt die Discovery eine Funktion neu an (zum Beispiel den iFan-Lüfter), deren Startwerte abfragen
        if ($this->activeFeatures() !== $previousFeatures) {
            $this->sendInitCommands();
        }
    }

    /**
     * Legt Variablen an (nur wenn neu oder geändert) und setzt Werte.
     *
     * @param array<int,array<string,mixed>> $records
     */
    private function ensureRecords(array $records, bool $setValues = true): void
    {
        $known = json_decode($this->ReadAttributeString('Variables'), true);
        $known = is_array($known) ? $known : [];
        $changed = false;

        foreach ($records as $record) {
            $ident = (string) $record['ident'];
            $signature = md5(json_encode([$record['caption'], $record['type'], $record['presentation'], $record['action']]));
            $exists = @$this->GetIDForIdent($ident) !== false;

            if (!$exists || ($known[$ident] ?? '') !== $signature) {
                if (!$exists && count($known) >= self::MAX_VARIABLES) {
                    $this->SendDebug('Variablenlimit', $ident . ' wird nicht angelegt', 0);
                    continue;
                }
                $created = $this->MaintainVariable($ident, (string) $record['caption'], (int) $record['type'], $record['presentation'], $this->positionFor($ident), true);
                if ($record['action']) {
                    $this->EnableAction($ident);
                }
                if ($created && str_ends_with($ident, 'Control')) {
                    // Bedienelement für Rollläden, Ausgangsstellung "Stopp"
                    $this->SetValue($ident, 1);
                }
                $known[$ident] = $signature;
                $changed = true;
            }

            if ($setValues && $record['value'] !== null) {
                $this->SetValue($ident, $this->coerce((int) $record['type'], $record['value']));
            }
        }

        if ($changed) {
            $this->WriteAttributeString('Variables', json_encode($known));
        }
    }

    private function coerce(int $type, mixed $value): mixed
    {
        return match ($type) {
            TasmotaV2MessageParser::TYPE_BOOLEAN => (bool) $value,
            TasmotaV2MessageParser::TYPE_INTEGER => (int) $value,
            TasmotaV2MessageParser::TYPE_FLOAT   => (float) $value,
            default                              => (string) $value
        };
    }

    private function onlineRecord(): array
    {
        return [
            'ident'        => 'Online',
            'caption'      => $this->Translate('Reachable'),
            'type'         => TasmotaV2MessageParser::TYPE_BOOLEAN,
            'value'        => null,
            'presentation' => TasmotaV2Presentation::status($this->Translate('Not reachable'), $this->Translate('Reachable')),
            'action'       => false
        ];
    }

    private function positionFor(string $ident): int
    {
        $light = ['Dimmer', 'Color', 'CT', 'White', 'Fade', 'Speed', 'Scheme'];
        return match (true) {
            $ident === 'Online'                                                  => 0,
            str_starts_with($ident, 'Power')                                     => 10,
            in_array($ident, $light, true), str_starts_with($ident, 'Channel')   => 20,
            str_starts_with($ident, 'Shutter')                                   => 30,
            str_starts_with($ident, 'Button'), str_starts_with($ident, 'Switch') => 40,
            str_starts_with($ident, 'Wifi')                                      => 50,
            str_starts_with($ident, 'Energy')                                    => 100,
            str_starts_with($ident, 'Sensor')                                    => 200,
            default                                                              => 300
        };
    }
}
