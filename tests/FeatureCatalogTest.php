<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FeatureCatalogTest extends TestCase
{
    public function testComponentsFromGpioResult(): void
    {
        $components = TasmotaV2FeatureCatalog::componentsFromResult(json_decode('{"GPIO0":{"32":"Button1"},"GPIO1":{"5536":"AS608 Rx"},"GPIO2":{"0":"None"},"GPIO5":{"224":"Relay1"}}', true));
        $this->assertSame(['Button1', 'AS608 Rx', 'None', 'Relay1'], $components);
    }

    public function testComponentsFromGpioResultOfNewerFirmwareWithNameAsKey(): void
    {
        $components = TasmotaV2FeatureCatalog::componentsFromResult(json_decode('{"GPIO0":{"Button1":32},"GPIO1":{"AS608 Rx":5536},"GPIO3":{"AS608 Tx":5504},"GPIO4":{"None":0},"GPIO12":{"Relay1":224}}', true));
        $this->assertSame(['Button1', 'AS608 Rx', 'AS608 Tx', 'None', 'Relay1'], $components);
        $this->assertSame(['fingerprint'], $this->catalog()->detect($components));
    }

    public function testNoGpioKeysIsNoGpioResult(): void
    {
        $this->assertNull(TasmotaV2FeatureCatalog::componentsFromResult(['POWER' => 'ON']));
        $this->assertNull(TasmotaV2FeatureCatalog::componentsFromResult([]));
    }

    public function testDetectFingerprintFromGpioNames(): void
    {
        $this->assertSame(['fingerprint'], $this->catalog()->detect(['Button1', 'AS608 Rx', 'AS608 Tx', 'Relay1']));
        $this->assertSame(['fingerprint'], $this->catalog()->detect(['as608 tx']), 'Groß-/Kleinschreibung egal, ein Treffer genügt');
        $this->assertSame([], $this->catalog()->detect(['Button1', 'Relay1', 'None']));
    }

    public function testNormalizeKeepsKnownFeaturesOnly(): void
    {
        $this->assertSame(['fingerprint'], $this->catalog()->normalize([' Fingerprint ', 'unknown', 'fingerprint', '']));
    }

    public function testSkeletonCreatesVariablesWithoutValues(): void
    {
        $records = $this->catalog()->skeleton(['fingerprint'], $this->translate());
        $idents = array_column($records, 'ident');
        foreach (['FingerprintId', 'FingerprintConfidence', 'FingerprintCount', 'FingerprintStatus', 'FingerprintEnroll', 'FingerprintDelete'] as $ident) {
            $this->assertContains($ident, $idents);
        }
        foreach ($records as $record) {
            $this->assertNull($record['value']);
        }
        $this->assertSame([], $this->catalog()->skeleton([], $this->translate()));
        $this->assertSame([], $this->catalog()->skeleton(['unknown'], $this->translate()));
    }

    public function testActionVariablesAreMarkedAsControllable(): void
    {
        $byIdent = [];
        foreach ($this->catalog()->skeleton(['fingerprint'], $this->translate()) as $record) {
            $byIdent[$record['ident']] = $record;
        }
        $this->assertTrue($byIdent['FingerprintEnroll']['action']);
        $this->assertTrue($byIdent['FingerprintDelete']['action']);
        $this->assertFalse($byIdent['FingerprintId']['action']);
        $this->assertSame(TasmotaV2MessageParser::TYPE_INTEGER, $byIdent['FingerprintEnroll']['type']);
        $this->assertSame(TasmotaV2MessageParser::TYPE_STRING, $byIdent['FingerprintStatus']['type']);
    }

    public function testResultsFromFingerprintMessages(): void
    {
        $values = [];
        foreach ($this->catalog()->results(['fingerprint'], ['FPrint' => ['Id' => 3, 'Confidence' => 98], 'FpCount' => 25], $this->translate()) as $record) {
            $values[$record['ident']] = $record['value'];
        }
        $this->assertEquals(['FingerprintId' => 3, 'FingerprintConfidence' => 98, 'FingerprintName' => 'Unknown (ID 3)', 'FingerprintCount' => 25], $values, 'Reihenfolge ist unerheblich');
    }

    public function testStatusTextFromEnrollAndDeleteStringOrObject(): void
    {
        $result = $this->catalog()->results(['fingerprint'], ['FpEnroll' => 'Enrolled #3'], $this->translate());
        $this->assertSame('Enrolled #3', $result[0]['value']);
        $result = $this->catalog()->results(['fingerprint'], ['FpDelete' => ['Id' => 3]], $this->translate());
        $this->assertSame('{"Id":3}', $result[0]['value']);
    }

    public function testResultsIgnoredForInactiveFeaturesAndMissingKeys(): void
    {
        $this->assertSame([], $this->catalog()->results([], ['FpCount' => 25], $this->translate()));
        $this->assertSame([], $this->catalog()->results(['fingerprint'], ['FPrint' => ['Id' => 'x']], $this->translate()), 'Id muss numerisch sein');
        $this->assertSame([], $this->catalog()->results(['fingerprint'], ['Other' => 1], $this->translate()));
    }

    public function testCommandsFromActionVariables(): void
    {
        $this->assertSame(['command' => 'FpEnroll', 'payload' => '7'], $this->catalog()->command('FingerprintEnroll', 7, ['fingerprint']));
        $this->assertSame(['command' => 'FpDelete', 'payload' => '12'], $this->catalog()->command('FingerprintDelete', '12', ['fingerprint']));
        $this->assertNull($this->catalog()->command('FingerprintEnroll', 7, []), 'Funktion nicht aktiv');
        $this->assertNull($this->catalog()->command('FingerprintId', 7, ['fingerprint']), 'nur lesend');
    }

    public function testActionValuesOutsideTheRangeAreRejected(): void
    {
        $this->assertNull($this->catalog()->command('FingerprintDelete', 0, ['fingerprint']), 'FpDelete 0 würde die ganze Datenbank leeren');
        $this->assertNull($this->catalog()->command('FingerprintDelete', 129, ['fingerprint']));
        $this->assertNull($this->catalog()->command('FingerprintDelete', -1, ['fingerprint']));
        $this->assertSame('1', $this->catalog()->command('FingerprintDelete', 1, ['fingerprint'])['payload']);
        $this->assertSame('128', $this->catalog()->command('FingerprintDelete', 128, ['fingerprint'])['payload']);
        $this->assertSame('0', $this->catalog()->command('FingerprintEnroll', 0, ['fingerprint'])['payload'], '0 bricht ein laufendes Anlernen ab');
        $this->assertNull($this->catalog()->command('FingerprintEnroll', 200, ['fingerprint']));
    }

    public function testInitCommandsAndResultKeys(): void
    {
        $this->assertSame([['command' => 'FpCount', 'payload' => '']], $this->catalog()->initCommands(['fingerprint']));
        $this->assertSame([], $this->catalog()->initCommands([]));
        $keys = $this->catalog()->resultKeys(['fingerprint']);
        sort($keys);
        $this->assertSame(['FPrint', 'FpCount', 'FpDelete', 'FpEnroll'], $keys);
    }

    public function testNameForRecognizedIdFromConfiguredList(): void
    {
        $values = [];
        $records = $this->catalog()->results(['fingerprint'], ['FPrint' => ['Id' => 7, 'Confidence' => 90]], $this->translate(), ['fingerprint' => [7 => 'Kai', 3 => 'Gast']]);
        foreach ($records as $record) {
            $values[$record['ident']] = $record['value'];
        }
        $this->assertSame('Kai', $values['FingerprintName']);
        $this->assertSame(7, $values['FingerprintId']);
    }

    public function testUnknownIdGetsPlaceholderName(): void
    {
        $this->assertSame('Unknown (ID 9)', $this->catalog()->resolveName('fingerprint', 9, ['fingerprint' => [7 => 'Kai']], $this->translate()));
        $this->assertSame('Unknown (ID 9)', $this->catalog()->resolveName('fingerprint', 9, [], $this->translate()));
        $this->assertSame('Unknown (ID 9)', $this->catalog()->resolveName('fingerprint', 9, ['fingerprint' => [9 => '']], $this->translate()), 'leerer Name zählt als unbenannt');
    }

    public function testNoMatchGoesToStatusAndDoesNotChangeTheName(): void
    {
        $values = [];
        foreach ($this->catalog()->results(['fingerprint'], ['FPrint' => 'NOMATCH'], $this->translate()) as $record) {
            $values[$record['ident']] = $record['value'];
        }
        $this->assertSame(['FingerprintStatus' => 'NOMATCH'], $values);
    }

    public function testMatchObjectDoesNotFillTheStatusText(): void
    {
        $idents = array_column($this->catalog()->results(['fingerprint'], ['FPrint' => ['Id' => 3, 'Confidence' => 98]], $this->translate()), 'ident');
        $this->assertNotContains('FingerprintStatus', $idents);
    }

    public function testNameListsAreDeclaredByTheCatalog(): void
    {
        $lists = $this->catalog()->nameLists();
        $this->assertSame('FingerprintNames', $lists['fingerprint']['property']);
        $this->assertSame('FingerprintId', $lists['fingerprint']['idVariable']);
        $this->assertSame('FingerprintName', $lists['fingerprint']['nameVariable']);
    }

    public function testParserPassesConfiguredNamesToTheCatalog(): void
    {
        $units = json_decode((string) file_get_contents(__DIR__ . '/../libs/data/sensor_units.json'), true);
        $model = TasmotaV2DiscoveryModel::fromJson('{"t":"x","mac":"AA","rl":[1],"ver":1}');
        $parser = new TasmotaV2MessageParser($model, $units, null, false, true, [], $this->catalog(), ['fingerprint'], ['fingerprint' => [3 => 'Kai']]);
        $values = [];
        foreach ($parser->parse('stat', 'RESULT', '{"FPrint":{"Id":3,"Confidence":99}}') as $record) {
            $values[$record['ident']] = $record['value'];
        }
        $this->assertSame('Kai', $values['FingerprintName']);
    }

    public function testIfanIsAnnouncedByTheDiscoveryFlag(): void
    {
        $fan = TasmotaV2DiscoveryModel::fromJson('{"t":"x","mac":"AA","if":1,"rl":[2,0,0,0],"lt_st":0,"ver":1}');
        $other = TasmotaV2DiscoveryModel::fromJson('{"t":"x","mac":"AA","if":0,"rl":[1],"ver":1}');
        $this->assertSame(['ifan'], $this->catalog()->detectFromDiscovery($fan));
        $this->assertSame([], $this->catalog()->detectFromDiscovery($other));
        $this->assertSame([], $this->catalog()->detect(['Relay1', 'Relay2']), 'der iFan wird nicht über GPIO erkannt');
    }

    public function testFanSpeedVariableIsAnEnumerationWithTranslatedOptions(): void
    {
        $translate = static fn (string $text): string => strtoupper($text);
        $record = $this->catalog()->skeleton(['ifan'], $translate)[0];
        $this->assertSame('FanSpeed', $record['ident']);
        $this->assertTrue($record['action']);
        $options = json_decode($record['presentation']['OPTIONS'], true);
        $this->assertSame([0, 1, 2, 3], array_column($options, 'Value'));
        $this->assertSame('FAN OFF', $options[0]['Caption']);
        $this->assertSame('FAN SPEED 3', $options[3]['Caption']);
    }

    public function testFanSpeedResultCommandAndRange(): void
    {
        $result = $this->catalog()->results(['ifan'], ['FanSpeed' => 2], $this->translate());
        $this->assertSame(2, $result[0]['value']);
        $this->assertSame(['command' => 'FanSpeed', 'payload' => '3'], $this->catalog()->command('FanSpeed', 3, ['ifan']));
        $this->assertSame(['command' => 'FanSpeed', 'payload' => '0'], $this->catalog()->command('FanSpeed', 0, ['ifan']));
        $this->assertNull($this->catalog()->command('FanSpeed', 4, ['ifan']));
        $this->assertSame([['command' => 'FanSpeed', 'payload' => '']], $this->catalog()->initCommands(['ifan']));
    }

    public function testIfanHidesFanRelaysAndHasNoDimmerForPlainLamp(): void
    {
        $units = json_decode((string) file_get_contents(__DIR__ . '/../libs/data/sensor_units.json'), true);
        $model = TasmotaV2DiscoveryModel::fromJson('{"t":"x","mac":"AA","if":1,"rl":[2,0,0,0],"lt_st":0,"ver":1}');
        $parser = new TasmotaV2MessageParser($model, $units, null, false, true, [], $this->catalog(), ['ifan']);
        $idents = array_column($parser->skeleton(), 'ident');
        $this->assertSame(['Power1', 'FanSpeed'], $idents, 'Lampe als Schalter, Lüfter als Auswahl, kein Dimmer');
        $values = [];
        foreach ($parser->parse('tele', 'STATE', '{"POWER1":"ON","POWER2":"ON","POWER3":"OFF","FanSpeed":2}') as $record) {
            $values[$record['ident']] = $record['value'];
        }
        $this->assertTrue($values['Power1']);
        $this->assertSame(2, $values['FanSpeed']);
        $this->assertArrayNotHasKey('Power2', $values, 'Lüfterrelais sind keine Schalter');
        $this->assertArrayNotHasKey('Power3', $values);
    }

    public function testPowerOnStateIsActiveForEveryDevice(): void
    {
        $this->assertSame(['poweronstate'], $this->catalog()->alwaysActive());
        $this->assertSame([], $this->catalog()->detect(['Relay1']), 'wird nicht über GPIO erkannt, sondern gilt immer');
    }

    public function testPowerOnStateOptionsRangeAndResults(): void
    {
        $record = $this->catalog()->skeleton(['poweronstate'], $this->translate())[0];
        $this->assertTrue($record['action']);
        $options = json_decode($record['presentation']['OPTIONS'], true);
        $this->assertSame([0, 1, 2, 3, 4, 5], array_column($options, 'Value'));
        $this->assertSame(['command' => 'PowerOnState', 'payload' => '1'], $this->catalog()->command('PowerOnState', 1, ['poweronstate']));
        $this->assertSame('4', $this->catalog()->command('PowerOnState', 4, ['poweronstate'])['payload']);
        $this->assertSame('5', $this->catalog()->command('PowerOnState', 5, ['poweronstate'])['payload']);
        $this->assertNull($this->catalog()->command('PowerOnState', 6, ['poweronstate']));
        $this->assertNull($this->catalog()->command('PowerOnState', -1, ['poweronstate']));
        $this->assertSame(3, $this->catalog()->results(['poweronstate'], ['PowerOnState' => 3], $this->translate())[0]['value']);
        $this->assertSame(0, $this->catalog()->results(['poweronstate'], ['Status' => ['PowerOnState' => 0, 'Module' => 18]], $this->translate())[0]['value'], 'Antwort auf Status 0');
    }

    public function testStatusZeroDeliversPowerOnState(): void
    {
        $units = json_decode((string) file_get_contents(__DIR__ . '/../libs/data/sensor_units.json'), true);
        $model = TasmotaV2DiscoveryModel::fromJson('{"t":"x","mac":"AA","rl":[1],"ver":1}');
        $parser = new TasmotaV2MessageParser($model, $units, null, false, true, [], $this->catalog(), ['poweronstate']);
        $values = [];
        foreach ($parser->parse('stat', 'STATUS0', '{"Status":{"Module":18,"PowerOnState":3},"StatusSTS":{"POWER":"ON"}}') as $record) {
            $values[$record['ident']] = $record['value'];
        }
        $this->assertSame(3, $values['PowerOnState']);
        $this->assertTrue($values['Power1']);
    }

    public function testPowerLimitsAreDetectedFromEnergyData(): void
    {
        $this->assertSame(['powerlimit'], $this->catalog()->detectFromData(['Time' => 'x', 'ENERGY' => ['Power' => 0]]));
        $this->assertSame(['powerlimit'], $this->catalog()->detectFromData(['StatusSNS' => ['ENERGY' => ['Power' => 0]]]), 'Antwort auf Status 0');
        $this->assertSame([], $this->catalog()->detectFromData(['BME280' => ['Temperature' => 21]]));
        $this->assertSame([], $this->catalog()->detectFromData(['POWER' => 'ON']));
    }

    public function testPowerLimitVariablesResultsAndRange(): void
    {
        $idents = array_column($this->catalog()->skeleton(['powerlimit'], $this->translate()), 'ident');
        $this->assertSame(['MaxPower', 'MaxPowerHold', 'MaxPowerWindow'], $idents);
        $values = [];
        foreach ($this->catalog()->results(['powerlimit'], ['StatusPTH' => ['MaxPower' => 2500, 'MaxPowerHold' => 10, 'MaxPowerWindow' => 30, 'PowerLow' => 0]], $this->translate()) as $record) {
            $values[$record['ident']] = $record['value'];
        }
        $this->assertSame(['MaxPower' => 2500, 'MaxPowerHold' => 10, 'MaxPowerWindow' => 30], $values);
        $this->assertSame(['command' => 'MaxPower', 'payload' => '3680'], $this->catalog()->command('MaxPower', 3680, ['powerlimit']));
        $this->assertSame(['command' => 'MaxPowerHold', 'payload' => '0'], $this->catalog()->command('MaxPowerHold', 0, ['powerlimit']));
        $this->assertNull($this->catalog()->command('MaxPower', -1, ['powerlimit']));
        $this->assertNull($this->catalog()->command('MaxPowerWindow', 70000, ['powerlimit']));
        $this->assertCount(3, $this->catalog()->initCommands(['powerlimit']));
    }

    public function testResultAfterSettingALimitUpdatesTheVariable(): void
    {
        $records = $this->catalog()->results(['powerlimit'], ['MaxPower' => 1000], $this->translate());
        $this->assertSame(1000, $records[0]['value']);
    }

    public function testIrReceiverIsDetectedFromGpioInBothFirmwareFormats(): void
    {
        $old = TasmotaV2FeatureCatalog::componentsFromResult(json_decode('{"GPIO14":{"51":"IRrecv"},"GPIO4":{"8":"IRsend"}}', true));
        $new = TasmotaV2FeatureCatalog::componentsFromResult(json_decode('{"GPIO14":{"IRrecv":51},"GPIO4":{"IRsend":8}}', true));
        $this->assertSame(['irreceive'], $this->catalog()->detect($old));
        $this->assertSame(['irreceive'], $this->catalog()->detect($new));
        $this->assertSame([], $this->catalog()->detect(['IRsend']), 'ein reiner IR-Sender liefert keine Empfangswerte');
    }

    public function testIrReceivedMessageFillsTheVariables(): void
    {
        $payload = ['Time' => '2019-01-01T00:00:00', 'IrReceived' => ['Protocol' => 'NEC', 'Bits' => 32, 'Data' => '0x00FF00FF']];
        $values = [];
        foreach ($this->catalog()->results(['irreceive'], $payload, $this->translate()) as $record) {
            $values[$record['ident']] = $record['value'];
        }
        $this->assertSame(['IRProtocol' => 'NEC', 'IRBits' => 32, 'IRData' => '0x00FF00FF'], $values, 'ohne Hash in der Nachricht keine Hash-Variable');
        $this->assertSame(['IrReceived'], $this->catalog()->resultKeys(['irreceive']));
        $this->assertSame([], $this->catalog()->initCommands(['irreceive']));
    }

    public function testWs2812IsDetectedFromGpioInBothFirmwareFormats(): void
    {
        $old = TasmotaV2FeatureCatalog::componentsFromResult(json_decode('{"GPIO5":{"7":"WS2812"}}', true));
        $new = TasmotaV2FeatureCatalog::componentsFromResult(json_decode('{"GPIO5":{"WS2812":7}}', true));
        $this->assertSame(['ws2812'], $this->catalog()->detect($old));
        $this->assertSame(['ws2812'], $this->catalog()->detect($new));
    }

    public function testPixelsVariableResultAndRange(): void
    {
        $this->assertSame(['Pixels'], array_column($this->catalog()->skeleton(['ws2812'], $this->translate()), 'ident'));
        $this->assertSame(30, $this->catalog()->results(['ws2812'], ['Pixels' => 30], $this->translate())[0]['value']);
        $this->assertSame(['command' => 'Pixels', 'payload' => '512'], $this->catalog()->command('Pixels', 512, ['ws2812']));
        $this->assertNull($this->catalog()->command('Pixels', 0, ['ws2812']));
        $this->assertNull($this->catalog()->command('Pixels', 513, ['ws2812']));
        $this->assertSame([['command' => 'Pixels', 'payload' => '']], $this->catalog()->initCommands(['ws2812']));
    }

    public function testLedStripAddsEffectsAndAllSchemes(): void
    {
        $units = json_decode((string) file_get_contents(__DIR__ . '/../libs/data/sensor_units.json'), true);
        $model = TasmotaV2DiscoveryModel::fromJson('{"t":"x","mac":"AA","rl":[2],"lt_st":3,"ver":1}');
        $strip = new TasmotaV2MessageParser($model, $units, null, false, true, [], $this->catalog(), ['ws2812']);
        $plain = new TasmotaV2MessageParser($model, $units, null, false, true, [], $this->catalog(), []);
        $stripIdents = array_column($strip->skeleton(), 'ident');
        foreach (['Fade', 'Speed', 'Scheme', 'Pixels', 'Dimmer', 'Color'] as $ident) {
            $this->assertContains($ident, $stripIdents);
        }
        $this->assertNotContains('Scheme', array_column($plain->skeleton(), 'ident'), 'ohne LED-Streifen keine Effekte im Voraus');
        $scheme = static function (TasmotaV2MessageParser $parser): array
        {
            foreach ($parser->parse('tele', 'STATE', '{"Scheme":2}') as $record) {
                if ($record['ident'] === 'Scheme') {
                    return array_column(json_decode($record['presentation']['OPTIONS'], true), 'Value');
                }
            }
            return [];
        };
        $this->assertSame(range(0, 14), $scheme($strip));
        $this->assertSame(range(0, 4), $scheme($plain), 'Programme 5 bis 14 gibt es nur bei adressierbaren LEDs');
    }

    public function testPortExpandersAreDetectedFromTheirData(): void
    {
        $this->assertSame(['mcp230xx'], $this->catalog()->detectFromData(['MCP230XX' => ['D0' => 0]]));
        $this->assertSame(['mcp230xx'], $this->catalog()->detectFromData(['MCP230XX_INT' => ['D0' => 1, 'MS' => 300]]));
        $this->assertSame(['mcp230xx'], $this->catalog()->detectFromData(['S29cmnd_D4' => ['COMMAND' => 'TOGGLE', 'STATE' => 'ON']]));
        $this->assertSame(['pcf8574'], $this->catalog()->detectFromData(['PCF8574-1' => ['D0' => 1]]));
        $this->assertSame(['pcf8574'], $this->catalog()->detectFromData(['PCF8574-2_INP' => ['D0' => 0]]));
        $this->assertSame(['pca9685'], $this->catalog()->detectFromData(['PCA9685' => ['PIN' => 3, 'PWM' => 100]]));
        $this->assertSame([], $this->catalog()->detectFromData(['BME280' => ['Temperature' => 21]]));
    }

    public function testMcp230xxPinsInterruptsAndOutputs(): void
    {
        $this->assertSame(['MCP230XX_D0' => false, 'MCP230XX_D15' => true], $this->resultValues(['mcp230xx'], ['MCP230XX' => ['D0' => 0, 'D15' => 1]]));
        $this->assertEquals(['MCP230XX_D3' => true, 'MCP230XXIntervalMs' => 301], $this->resultValues(['mcp230xx'], ['MCP230XX_INT' => ['D3' => 1, 'MS' => 301]]));
        $this->assertSame(['MCP230XX_Out4' => true], $this->resultValues(['mcp230xx'], ['S29cmnd_D4' => ['COMMAND' => 'TOGGLE', 'STATE' => 'ON']]));
        $this->assertSame(['MCP230XX_Out4' => false], $this->resultValues(['mcp230xx'], ['MCP230_OUT' => ['OUT_D4' => 'OFF', 'END' => 1]]));
    }

    public function testMcp230xxOutputCommandsUseThePinFromTheIdent(): void
    {
        $this->assertSame(['command' => 'Sensor29', 'payload' => '4,ON'], $this->catalog()->command('MCP230XX_Out4', true, ['mcp230xx']));
        $this->assertSame(['command' => 'Sensor29', 'payload' => '12,OFF'], $this->catalog()->command('MCP230XX_Out12', false, ['mcp230xx']));
        $this->assertNull($this->catalog()->command('MCP230XX_D4', true, ['mcp230xx']), 'Eingänge sind nur lesbar');
        $this->assertNull($this->catalog()->command('MCP230XX_Out4', true, []), 'Funktion nicht aktiv');
    }

    public function testMcp230xxOutputVariablesAreControllable(): void
    {
        $records = $this->catalog()->results(['mcp230xx'], ['S29cmnd_D4' => ['STATE' => 'ON']], $this->translate());
        $this->assertTrue($records[0]['action']);
        $this->assertSame('MCP230XX output D4', $records[0]['caption']);
    }

    public function testPcf8574ChipsAndPinsAreSeparated(): void
    {
        $values = $this->resultValues(['pcf8574'], ['PCF8574-1' => ['D0' => 1, 'D7' => 0], 'PCF8574-2_INP' => ['D3' => 0], 'Time' => 'x']);
        $this->assertSame(['PCF8574_1_D0' => true, 'PCF8574_1_D7' => false, 'PCF8574_2_D3' => false], $values);
        $this->assertNull($this->catalog()->command('PCF8574_1_D0', true, ['pcf8574']), 'nur lesbar, die Ausgänge sind Power-Schalter');
    }

    public function testPca9685PwmPerPin(): void
    {
        $this->assertSame(['PCA9685_PWM3' => 2048], $this->resultValues(['pca9685'], ['PCA9685' => ['PIN' => 3, 'PWM' => 2048]]));
        $this->assertSame(['command' => 'driver15', 'payload' => 'pwm,3,100'], $this->catalog()->command('PCA9685_PWM3', 100, ['pca9685']));
        $this->assertSame(['command' => 'driver15', 'payload' => 'pwm,15,4096'], $this->catalog()->command('PCA9685_PWM15', 4096, ['pca9685']));
        $this->assertNull($this->catalog()->command('PCA9685_PWM3', 4097, ['pca9685']));
        $this->assertNull($this->catalog()->command('PCA9685_PWM3', -1, ['pca9685']));
        $this->assertSame([], $this->resultValues(['pca9685'], ['PCA9685' => ['PWM' => 5]]), 'ohne Pin keine Variable');
    }

    public function testKeysOfActiveFeaturesAreNotSensors(): void
    {
        $this->assertTrue($this->catalog()->handlesKey(['pcf8574'], 'PCF8574-1'));
        $this->assertTrue($this->catalog()->handlesKey(['pcf8574'], 'PCF8574-12_INP'));
        $this->assertFalse($this->catalog()->handlesKey(['pcf8574'], 'BME280'));
        $this->assertFalse($this->catalog()->handlesKey([], 'PCF8574-1'), 'ohne aktive Funktion bleibt es ein Sensor');
        $units = json_decode((string) file_get_contents(__DIR__ . '/../libs/data/sensor_units.json'), true);
        $model = TasmotaV2DiscoveryModel::fromJson('{"t":"x","mac":"AA","rl":[1],"ver":1}');
        $active = new TasmotaV2MessageParser($model, $units, null, false, true, [], $this->catalog(), ['mcp230xx']);
        $idents = array_column($active->parse('tele', 'SENSOR', '{"MCP230XX":{"D0":1},"BME280":{"Temperature":21}}'), 'ident');
        $this->assertContains('MCP230XX_D0', $idents);
        $this->assertNotContains('Sensor_MCP230XX_D0', $idents);
        $this->assertContains('Sensor_BME280_Temperature', $idents);
    }

    public function testLd2410IsDetectedFromGpioAndFromItsTelemetry(): void
    {
        $old = TasmotaV2FeatureCatalog::componentsFromResult(json_decode('{"GPIO16":{"3552":"LD2410 Tx"},"GPIO17":{"3584":"LD2410 Rx"},"GPIO4":{"544":"Switch1"}}', true));
        $new = TasmotaV2FeatureCatalog::componentsFromResult(json_decode('{"GPIO16":{"LD2410 Tx":3552},"GPIO17":{"LD2410 Rx":3584}}', true));
        $this->assertSame(['ld2410'], $this->catalog()->detect($old));
        $this->assertSame(['ld2410'], $this->catalog()->detect($new));
        $this->assertSame(['ld2410'], $this->catalog()->detectFromData(['Time' => 'x', 'LD2410' => ['Distance' => [125.0, 0.0, 0.0], 'Energy' => [0, 100]]]));
    }

    public function testLd2410TelemetryFillsNamedVariables(): void
    {
        $values = $this->resultValues(['ld2410'], ['Time' => '2022-11-26T10:48:16', 'Switch1' => 'ON', 'LD2410' => ['Distance' => [125.5, 0.0, 99.0], 'Energy' => [13, 100]]]);
        $this->assertEquals([
            'LD2410MovingDistance' => 125.5, 'LD2410StaticDistance' => 0.0, 'LD2410DetectDistance' => 99.0,
            'LD2410MovingEnergy'   => 13, 'LD2410StaticEnergy' => 100
        ], $values);
    }

    public function testLd2410GetResultAndGateEnergies(): void
    {
        $payload = ['LD2410' => ['Moving energy' => [13, 12, 10, 9, 9, 8, 1, 4, 4], 'Static energy' => [0, 0, 100, 100, 45, 32, 147, 15, 5], 'Light' => 103, 'Out_pin' => 1]];
        $values = $this->resultValues(['ld2410'], $payload);
        $this->assertSame(103, $values['LD2410Light']);
        $this->assertTrue($values['LD2410OutPin']);
        $this->assertSame(13, $values['LD2410MovingGate0']);
        $this->assertSame(4, $values['LD2410MovingGate8']);
        $this->assertSame(147, $values['LD2410StaticGate6']);
        $this->assertCount(2 + 18, $values);
    }

    public function testLd2410SettingsReplyBecomesDurationAndSensitivityLists(): void
    {
        $reply = ['LD2410' => ['Duration' => 5, 'Moving' => ['Gates' => 8, 'Sensitivity' => [50, 50, 40, 30, 20, 15, 15, 15, 15]], 'Static' => ['Gates' => 8, 'Sensitivity' => [0, 0, 40, 40, 30, 30, 20, 20, 20]]]];
        $values = $this->resultValues(['ld2410'], $reply);
        $this->assertSame(5, $values['LD2410Duration']);
        $this->assertSame('50,50,40,30,20,15,15,15,15', $values['LD2410MovingSens']);
        $this->assertSame('0,0,40,40,30,30,20,20,20', $values['LD2410StaticSens']);
    }

    public function testLd2410CommandsAreValidated(): void
    {
        $this->assertSame(['command' => 'LD2410Duration', 'payload' => '30'], $this->catalog()->command('LD2410Duration', 30, ['ld2410']));
        $this->assertNull($this->catalog()->command('LD2410Duration', 0, ['ld2410']), '0 würde den Sensor auf Werkseinstellung setzen');
        $this->assertNull($this->catalog()->command('LD2410Duration', 70000, ['ld2410']));
        $this->assertSame('50,50,40,30,20,15,15,15,15', $this->catalog()->command('LD2410MovingSens', '50, 50, 40,30,20,15,15,15,15', ['ld2410'])['payload'], 'Leerzeichen werden entfernt');
        $this->assertSame('100', $this->catalog()->command('LD2410StaticSens', '100', ['ld2410'])['payload']);
        $this->assertSame('0,100', $this->catalog()->command('LD2410MovingSens', '0,100', ['ld2410'])['payload']);
        foreach (['', 'abc', '101', '50,,40', '1,2,3,4,5,6,7,8,9,10', '50;40', '-5', '5.5'] as $invalid) {
            $this->assertNull($this->catalog()->command('LD2410MovingSens', $invalid, ['ld2410']), 'ungültig: ' . $invalid);
        }
    }

    public function testLd2410InitQueryIsHarmless(): void
    {
        $this->assertSame([['command' => 'LD2410MovingSens', 'payload' => '']], $this->catalog()->initCommands(['ld2410']));
    }

    public function testCatalogFileIsConsistent(): void
    {
        $file = json_decode((string) file_get_contents(__DIR__ . '/../libs/data/features.json'), true);
        foreach ($file as $id => $feature) {
            $this->assertNotEmpty(array_merge($feature['detect']['gpio'] ?? [], $feature['detect']['discovery'] ?? [], empty($feature['detect']['always']) ? [] : [true], $feature['detect']['data'] ?? []), $id . ': detect.gpio oder detect.discovery fehlt');
            foreach ($feature['results'] ?? [] as $result) {
                $this->assertArrayHasKey($result['variable'], $feature['variables'] ?? [], $id . ': Ergebnis verweist auf unbekannte Variable');
            }
            foreach ($feature['dynamic'] ?? [] as $rule) {
                $this->assertArrayHasKey('ident', $rule, $id . ': dynamische Regel ohne ident');
                $this->assertContains($rule['type'], ['boolean', 'integer', 'float', 'string'], $id . ': dynamische Regel');
                $this->assertMatchesRegularExpression('/^[A-Za-z0-9_{}]+$/', $rule['ident'], $id . ': Ident mit Platzhaltern');
            }
            foreach (['idVariable', 'nameVariable'] as $key) {
                if (isset($feature['names'])) {
                    $this->assertArrayHasKey($feature['names'][$key], $feature['variables'], $id . ': names.' . $key);
                }
            }
            foreach ($feature['variables'] ?? [] as $ident => $variable) {
                $this->assertContains($variable['type'], ['boolean', 'integer', 'float', 'string'], $ident);
                $this->assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/', $ident);
                $this->assertArrayHasKey('caption', $variable);
            }
        }
    }

    public function testParserAppliesFeaturesAndKeepsThemOutOfSensorTree(): void
    {
        $units = json_decode((string) file_get_contents(__DIR__ . '/../libs/data/sensor_units.json'), true);
        $model = TasmotaV2DiscoveryModel::fromJson('{"t":"x","mac":"AA","rl":[1],"ver":1}');
        $parser = new TasmotaV2MessageParser($model, $units, null, false, true, [], $this->catalog(), ['fingerprint']);
        $values = [];
        foreach ($parser->parse('stat', 'RESULT', '{"FPrint":{"Id":3,"Confidence":98}}') as $record) {
            $values[$record['ident']] = $record['value'];
        }
        $this->assertSame(3, $values['FingerprintId']);
        // In der Telemetrie darf ein Schlüssel der Funktion keinen Sensorbaum erzeugen
        $idents = array_column($parser->parse('tele', 'SENSOR', '{"FPrint":{"Id":3},"BME280":{"Temperature":1}}'), 'ident');
        $this->assertNotContains('Sensor_FPrint_Id', $idents);
        $this->assertContains('Sensor_BME280_Temperature', $idents);
        // Ohne aktive Funktion bleibt FPrint ein gewöhnliches Ergebnis ohne Variable
        $plain = new TasmotaV2MessageParser($model, $units);
        $this->assertSame([], $plain->parse('stat', 'RESULT', '{"FPrint":{"Id":3}}'));
        $this->assertContains('FingerprintId', array_column($parser->skeleton(), 'ident'));
        $this->assertNotContains('FingerprintId', array_column($plain->skeleton(), 'ident'));
    }

    /**
     * @param array<int,string> $ids
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function resultValues(array $ids, array $data): array
    {
        $values = [];
        foreach ($this->catalog()->results($ids, $data, $this->translate()) as $record) {
            $values[$record['ident']] = $record['value'];
        }
        return $values;
    }
    private function catalog(): TasmotaV2FeatureCatalog
    {
        return TasmotaV2FeatureCatalog::fromFile(__DIR__ . '/../libs/data/features.json');
    }

    private function translate(): Closure
    {
        return static fn (string $text): string => $text;
    }
}
