<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MessageParserTest extends TestCase
{
    private const DISCOVERY = [
        'ip'    => '192.0.2.10', 'dn' => 'Test', 'fn' => ['Licht', 'Relais', null, null], 'hn' => 'tas-test',
        'mac'   => 'AABBCC112233', 'md' => 'Test', 'ofln' => 'Offline', 'onln' => 'Online',
        'state' => ['OFF', 'ON', 'TOGGLE', 'HOLD'], 'sw' => '14.0.0', 't' => 'test', 'ft' => '%prefix%/%topic%/',
        'tp'    => ['cmnd', 'stat', 'tele'], 'rl' => [2, 1, 3, 3], 'swc' => [-1, 0], 'swn' => [null, 'Fenster'],
        'btn'   => [1, 0], 'so' => ['4' => 0, '17' => 0], 'lk' => 1, 'lt_st' => 5, 'sho' => [0], 'sht' => [[0, 0, 0]], 'ver' => 1
    ];

    public function testLastWillMapsToOnlineVariable(): void
    {
        $this->assertSame(['Online' => true], $this->values($this->parser()->parse('tele', 'LWT', 'Online'), true));
        $this->assertSame(['Online' => false], $this->values($this->parser()->parse('tele', 'LWT', 'Offline'), true));
    }

    public function testTelemetryAndStatusRepliesMarkDeviceOnline(): void
    {
        foreach ([['tele', 'STATE', '{"POWER1":"ON"}'], ['tele', 'SENSOR', '{"BME280":{"Temperature":1}}'], ['stat', 'STATUS0', '{"StatusSTS":{}}']] as [$kind, $suffix, $payload]) {
            $this->assertTrue($this->values($this->parser()->parse($kind, $suffix, $payload), true)['Online'], $kind . '/' . $suffix);
        }
        // Eine Antwort auf einen Befehl kann retained sein und beweist keine Erreichbarkeit
        $this->assertArrayNotHasKey('Online', $this->values($this->parser()->parse('stat', 'RESULT', '{"POWER1":"ON"}'), true));
    }

    public function testCustomLastWillTextFromDiscovery(): void
    {
        $parser = $this->parser(['onln' => 'up', 'ofln' => 'down']);
        $this->assertSame(['Online' => true], $this->values($parser->parse('tele', 'LWT', 'up'), true));
        $this->assertSame(['Online' => false], $this->values($parser->parse('tele', 'LWT', 'Online'), true));
    }

    public function testPowerFromPlainTextTopicAndFromResult(): void
    {
        $this->assertSame(['Power2' => true], $this->values($this->parser()->parse('stat', 'POWER2', 'ON')));
        $this->assertSame(['Power1' => true], $this->values($this->parser()->parse('stat', 'POWER', 'ON')));
        $this->assertSame(['Power1' => false, 'Power2' => true], $this->values($this->parser()->parse('stat', 'RESULT', '{"POWER1":"OFF","POWER2":"ON"}')));
    }

    public function testSingleRelayKeyWithoutNumberIsRelayOne(): void
    {
        $this->assertSame(['Power1' => true], $this->values($this->parser()->parse('tele', 'STATE', '{"POWER":"ON"}')));
    }

    public function testCustomStateTextsFromDiscovery(): void
    {
        $parser = $this->parser(['state' => ['AUS', 'EIN', 'WECHSEL', 'HALTEN']]);
        $this->assertSame(['Power1' => true], $this->values($parser->parse('stat', 'RESULT', '{"POWER1":"EIN"}')));
        $this->assertSame(['Power1' => false], $this->values($parser->parse('stat', 'RESULT', '{"POWER1":"AUS"}')));
    }

    public function testUnknownPowerTextIsIgnored(): void
    {
        $this->assertSame([], $this->parser()->parse('stat', 'POWER1', 'TOGGLE'));
    }

    public function testNonJsonPayloadYieldsNothing(): void
    {
        $this->assertSame([], $this->parser()->parse('stat', 'RESULT', 'not json'));
        $this->assertSame([], $this->parser()->parse('stat', 'RESULT', '42'));
    }

    public function testLightValuesFromState(): void
    {
        $records = $this->parser()->parse('tele', 'STATE', '{"Dimmer":42,"White":10,"CT":300,"Fade":"ON","Speed":2,"Scheme":0,"HSBColor":"0,100,42"}');
        $values = $this->values($records);
        $this->assertSame(42, $values['Dimmer']);
        $this->assertSame(10, $values['White']);
        $this->assertSame(300, $values['CT']);
        $this->assertTrue($values['Fade']);
        $this->assertSame(2, $values['Speed']);
        $this->assertSame(0xFF0000, $values['Color'], 'Rot bei voller Helligkeit, die Helligkeit steckt im Dimmer');
    }

    public function testColorFromHexAndDecimalWhenNoHsbColor(): void
    {
        $this->assertSame(0x6B0000, $this->values($this->parser()->parse('tele', 'STATE', '{"Color":"6B0000"}'))['Color']);
        $this->assertSame(0x6B0000, $this->values($this->parser()->parse('tele', 'STATE', '{"Color":"107,0,0"}'))['Color']);
        $this->assertSame(0x112233, $this->values($this->parser()->parse('tele', 'STATE', '{"Color":"11223344AA"}'))['Color'], 'RGBCW: nur die ersten drei Bytes');
    }

    public function testWifiQualityIsPercentAndSignalIsDbm(): void
    {
        $values = $this->values($this->parser()->parse('tele', 'STATE', '{"Wifi":{"RSSI":88,"Signal":-56,"SSId":"x"}}'));
        $this->assertSame(88, $values['WifiRSSI']);
        $this->assertSame(-56, $values['WifiSignal']);
        $this->assertArrayNotHasKey('System_WifiSSId', $values);
    }

    public function testSystemVariablesOnlyWhenEnabled(): void
    {
        $payload = '{"Uptime":"1T02:03:04","UptimeSec":93784,"Heap":24,"Wifi":{"SSId":"x","Channel":6}}';
        $this->assertArrayNotHasKey('System_Uptime', $this->values($this->parser()->parse('tele', 'STATE', $payload)));
        $values = $this->values($this->parser([], true)->parse('tele', 'STATE', $payload));
        $this->assertSame('1T02:03:04', $values['System_Uptime']);
        $this->assertSame(93784, $values['System_UptimeSec']);
        $this->assertSame('x', $values['System_WifiSSId']);
        $this->assertSame(6, $values['System_WifiChannel']);
    }

    public function testSensorValuesBecomeFloatsWithUnits(): void
    {
        $records = $this->parser()->parse('tele', 'SENSOR', '{"Time":"x","BME280":{"Temperature":21,"Humidity":40.2,"Pressure":1013.2},"PressureUnit":"hPa","TempUnit":"C"}');
        $temperature = $this->record($records, 'Sensor_BME280_Temperature');
        $this->assertSame(21.0, $temperature['value']);
        $this->assertSame(TasmotaV2MessageParser::TYPE_FLOAT, $temperature['type']);
        $this->assertSame(' °C', $temperature['presentation']['SUFFIX']);
        $this->assertSame(' hPa', $this->record($records, 'Sensor_BME280_Pressure')['presentation']['SUFFIX']);
    }

    public function testFahrenheitUnitFollowsTempUnit(): void
    {
        $records = $this->parser()->parse('tele', 'SENSOR', '{"DS18B20":{"Temperature":66.2},"TempUnit":"F"}');
        $this->assertSame(' °F', $this->record($records, 'Sensor_DS18B20_Temperature')['presentation']['SUFFIX']);
    }

    public function testUnknownSensorValueIsCreatedWithoutUnit(): void
    {
        $records = $this->parser()->parse('tele', 'SENSOR', '{"NEWSENSOR":{"Foo-Bar.1":3}}');
        $record = $this->record($records, 'Sensor_NEWSENSOR_Foo_Bar_1');
        $this->assertSame(3.0, $record['value']);
        $this->assertSame('', $record['presentation']['SUFFIX']);
    }

    public function testSensorStringsAndNestedObjects(): void
    {
        $values = $this->values($this->parser()->parse('tele', 'SENSOR', '{"DS18B20":{"Id":"0000A1","Temperature":19.5},"Outer":{"Inner":{"Value":1}}}'));
        $this->assertSame('0000A1', $values['Sensor_DS18B20_Id']);
        $this->assertSame(1.0, $values['Sensor_Outer_Inner_Value']);
    }

    public function testAutoSensorsCanBeDisabledAndSensorsExcluded(): void
    {
        $payload = '{"BME280":{"Temperature":21.5},"ANALOG":{"A0":12}}';
        $this->assertSame([], $this->values($this->parser([], false, false)->parse('tele', 'SENSOR', $payload)));
        $values = $this->values($this->parser([], false, true, ['analog'])->parse('tele', 'SENSOR', $payload));
        $this->assertArrayHasKey('Sensor_BME280_Temperature', $values);
        $this->assertArrayNotHasKey('Sensor_ANALOG_A0', $values);
    }

    public function testUnknownObjectsInStateAreNotSensors(): void
    {
        $this->assertSame([], $this->values($this->parser()->parse('tele', 'STATE', '{"SomethingNew":{"Value":1}}')));
    }

    public function testEnergyValuesWithUnitsAndPhaseLists(): void
    {
        $records = $this->parser()->parse('tele', 'SENSOR', '{"ENERGY":{"TotalStartTime":"2025-01-01T00:00:00","Total":12.5,"Power":[100,200],"Voltage":230,"Factor":0.9}}');
        $values = $this->values($records);
        $this->assertSame(12.5, $values['Energy_Total']);
        $this->assertSame(' kWh', $this->record($records, 'Energy_Total')['presentation']['SUFFIX']);
        $this->assertSame(100.0, $values['Energy_Power_1']);
        $this->assertSame(200.0, $values['Energy_Power_2']);
        $this->assertSame('2025-01-01T00:00:00', $values['Energy_TotalStartTime']);
        $this->assertArrayNotHasKey('Sensor_ENERGY_Total', $values, 'ENERGY ist kein gewöhnlicher Sensor');
    }

    public function testShutterObjectAndResult(): void
    {
        $values = $this->values($this->parser()->parse('tele', 'STATE', '{"Shutter1":{"Position":60,"Direction":-1,"Target":0,"Tilt":30}}'));
        $this->assertSame(60, $values['Shutter1Position']);
        $this->assertSame(-1, $values['Shutter1Direction']);
        $this->assertSame(30, $values['Shutter1Tilt']);
        $this->assertSame(['Shutter2Position' => 10], $this->values($this->parser()->parse('stat', 'RESULT', '{"ShutterPosition2":10}')));
    }

    public function testShutterLockFromResultAndAnnouncedBySkeleton(): void
    {
        $this->assertSame(['Shutter1Lock' => true], $this->values($this->parser()->parse('stat', 'RESULT', '{"ShutterLock1":1}')));
        $this->assertSame(['Shutter2Lock' => false], $this->values($this->parser()->parse('stat', 'RESULT', '{"ShutterLock2":0}')));
        $this->assertSame(['Shutter1Lock' => true], $this->values($this->parser()->parse('stat', 'RESULT', '{"ShutterLock1":"ON"}')));
        $records = $this->parser()->skeleton();
        $this->assertContains('Shutter1Lock', array_column($records, 'ident'));
        $this->assertTrue($this->record($records, 'Shutter1Lock')['action']);
        $this->assertSame(TasmotaV2MessageParser::TYPE_BOOLEAN, $this->record($records, 'Shutter1Lock')['type']);
    }

    public function testModuleNameFromDiscoveryOnlyWithSystemVariables(): void
    {
        $values = $this->values($this->parser(['md' => 'Inline Plug'], true)->skeleton());
        $this->assertSame('Inline Plug', $values['System_Module']);
        $this->assertArrayNotHasKey('System_Module', $this->values($this->parser(['md' => 'Inline Plug'])->skeleton()));
        $this->assertArrayNotHasKey('System_Module', $this->values($this->parser(['md' => ''], true)->skeleton()), 'ohne Namen keine Variable');
    }

    public function testGroupTopicFromStatusZeroOnlyWithSystemVariables(): void
    {
        $payload = '{"StatusPRM":{"GroupTopic":"tasmotas","Uptime":"1T01:00:00"},"StatusSTS":{"POWER":"ON"}}';
        $this->assertSame('tasmotas', $this->values($this->parser([], true)->parse('stat', 'STATUS0', $payload))['System_GroupTopic']);
        $this->assertArrayNotHasKey('System_GroupTopic', $this->values($this->parser()->parse('stat', 'STATUS0', $payload)));
    }

    public function testButtonActionFromResult(): void
    {
        $this->assertSame(['Button1' => 'DOUBLE'], $this->values($this->parser()->parse('stat', 'RESULT', '{"Button1":{"Action":"DOUBLE"}}')));
    }

    public function testSwitchByNumberByCustomNameAndAction(): void
    {
        $this->assertSame(['Switch2' => 'OFF'], $this->values($this->parser()->parse('stat', 'RESULT', '{"Switch2":{"Action":"OFF"}}')));
        $this->assertSame(['Switch2' => 'ON'], $this->values($this->parser()->parse('tele', 'SENSOR', '{"Fenster":"ON"}')));
        $this->assertSame(['Switch1' => 'ON'], $this->values($this->parser()->parse('tele', 'SENSOR', '{"Switch1":"ON"}')));
    }

    public function testCommandNamedReplyWithSetOption4(): void
    {
        $this->assertSame(['Dimmer' => 10], $this->values($this->parser()->parse('stat', 'DIMMER', '{"Dimmer":10}')));
    }

    public function testStatusZeroDistributesSections(): void
    {
        $payload = '{"StatusSTS":{"POWER1":"OFF","Wifi":{"RSSI":50,"Signal":-70}},"StatusSNS":{"Time":"x","TempUnit":"F","DS18B20":{"Temperature":66.2}}}';
        $records = $this->parser()->parse('stat', 'STATUS0', $payload);
        $values = $this->values($records);
        $this->assertFalse($values['Power1']);
        $this->assertSame(50, $values['WifiRSSI']);
        $this->assertSame(66.2, $values['Sensor_DS18B20_Temperature']);
        $this->assertSame(' °F', $this->record($records, 'Sensor_DS18B20_Temperature')['presentation']['SUFFIX']);
    }

    public function testSkeletonAnnouncesControlsFromDiscovery(): void
    {
        $idents = array_column($this->parser()->skeleton(), 'ident');
        foreach (['Power1', 'Power2', 'Dimmer', 'Color', 'CT', 'White', 'Shutter1Position', 'Shutter1Direction', 'Shutter1Control', 'Button1', 'Switch2'] as $ident) {
            $this->assertContains($ident, $idents);
        }
        $this->assertNotContains('Button2', $idents);
        $this->assertNotContains('Switch1', $idents, 'Schalter mit swc -1 sind nicht für MQTT freigegeben');
    }

    public function testSkeletonWithoutLightOrShutter(): void
    {
        $idents = array_column($this->parser(['rl' => [1, 1, 0, 0], 'lt_st' => 0, 'btn' => [], 'swc' => [-1, -1]])->skeleton(), 'ident');
        $this->assertSame(['Power1', 'Power2'], $idents);
    }

    public function testControllableRecordsCarryAction(): void
    {
        $records = $this->parser()->skeleton();
        $this->assertTrue($this->record($records, 'Power1')['action']);
        $this->assertTrue($this->record($records, 'Shutter1Control')['action']);
        $this->assertFalse($this->record($records, 'Shutter1Direction')['action']);
        $this->assertFalse($this->record($records, 'Button1')['action']);
    }

    public function testIdentsContainOnlyLettersDigitsAndUnderscores(): void
    {
        $records = $this->parser()->parse('tele', 'SENSOR', '{"A-B.C":{"X y/z":1,"PM2.5":3}}');
        foreach ($records as $record) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/', $record['ident']);
        }
    }

    public function testVeryLongIdentsAreShortenedUniquely(): void
    {
        $name = str_repeat('A', 70);
        $records = $this->parser()->parse('tele', 'SENSOR', json_encode([$name => ['Value1' => 1, 'Value2' => 2]]));
        $idents = array_values(array_filter(array_column($records, 'ident'), static fn (string $ident): bool => $ident !== 'Online'));
        $this->assertCount(2, array_unique($idents));
        foreach ($idents as $ident) {
            $this->assertLessThanOrEqual(60, strlen($ident));
        }
    }

    private function parser(array $override = [], bool $system = false, bool $auto = true, array $exclude = []): TasmotaV2MessageParser
    {
        $units = json_decode((string) file_get_contents(__DIR__ . '/../libs/data/sensor_units.json'), true);
        $model = TasmotaV2DiscoveryModel::fromJson(json_encode(array_merge(self::DISCOVERY, $override)));
        return new TasmotaV2MessageParser($model, $units, null, $system, $auto, $exclude);
    }

    /**
     * @param array<int,array<string,mixed>> $records
     * @return array<string,mixed> Ident => Wert
     */
    private function values(array $records, bool $withOnline = false): array
    {
        $values = [];
        foreach ($records as $record) {
            if ($withOnline || $record['ident'] !== 'Online') {
                $values[$record['ident']] = $record['value'];
            }
        }
        return $values;
    }

    /**
     * @param array<int,array<string,mixed>> $records
     */
    private function record(array $records, string $ident): array
    {
        foreach ($records as $record) {
            if ($record['ident'] === $ident) {
                return $record;
            }
        }
        $this->fail('Kein Datensatz ' . $ident);
    }
}
