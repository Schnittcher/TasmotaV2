<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DiscoveryModelTest extends TestCase
{
    public function testInvalidInputIsRejected(): void
    {
        $this->assertNull(TasmotaV2DiscoveryModel::fromJson(''));
        $this->assertNull(TasmotaV2DiscoveryModel::fromJson('not json'));
        $this->assertNull(TasmotaV2DiscoveryModel::fromJson('{"mac":"AA"}'), 'Topic fehlt');
        $this->assertNull(TasmotaV2DiscoveryModel::fromJson('{"t":"x","mac":"AA","ver":2}'), 'unbekannte Formatversion');
    }

    public function testEmptyModel(): void
    {
        $this->assertTrue(TasmotaV2DiscoveryModel::empty()->isEmpty());
        $this->assertSame(0, TasmotaV2DiscoveryModel::empty()->powerCount());
        $this->assertSame('Online', TasmotaV2DiscoveryModel::empty()->onlinePayload());
        $this->assertSame(['OFF', 'ON', 'TOGGLE', 'HOLD'], TasmotaV2DiscoveryModel::empty()->stateTexts());
    }

    public function testRelaysLightsAndShutters(): void
    {
        $model = $this->model();
        $this->assertSame(2, $model->powerCount(), 'Relais und Licht, ohne Rollladenrelais');
        $this->assertTrue($model->hasLight());
        $this->assertSame(1, $model->shutterCount(), 'ein Rollladen belegt zwei Relais');
        $this->assertSame(5, $model->lightSubtype());
    }

    public function testButtonsAndSwitches(): void
    {
        $model = $this->model();
        $this->assertSame([1, 3], $model->buttons());
        $this->assertSame([2 => 'Fenster', 3 => ''], $model->switches(), 'swc -1 ist nicht freigegeben');
    }

    public function testNamesAndSetOptions(): void
    {
        $model = $this->model();
        $this->assertSame(['Licht', 'Relais'], $model->friendlyNames());
        $this->assertSame(1, $model->setOption(73));
        $this->assertSame(0, $model->setOption(4));
        $this->assertNull($model->setOption(17));
    }

    public function testJsonRoundTrip(): void
    {
        $model = $this->model();
        $copy = TasmotaV2DiscoveryModel::fromJson($model->toJson());
        $this->assertSame($model->toJson(), $copy->toJson());
        $this->assertSame('AABBCC112233', $copy->mac());
        $this->assertSame('test', $copy->topic());
    }
    private function model(array $override = []): TasmotaV2DiscoveryModel
    {
        $config = array_merge([
            'ip'    => '192.0.2.10', 'dn' => 'Test', 'fn' => ['Licht', null, 'Relais', null], 'hn' => 'tas-test',
            'mac'   => 'AABBCC112233', 'md' => 'Sonoff', 'ofln' => 'Offline', 'onln' => 'Online',
            'state' => ['OFF', 'ON', 'TOGGLE', 'HOLD'], 'sw' => '14.0.0', 't' => 'test', 'ft' => '%prefix%/%topic%/',
            'tp'    => ['cmnd', 'stat', 'tele'], 'rl' => [2, 1, 3, 3, 0], 'swc' => [-1, 0, 2], 'swn' => [null, 'Fenster', null],
            'btn'   => [1, 0, 1], 'so' => ['4' => 0, '73' => 1], 'lt_st' => 5, 'sht' => [[90, -90, 10], [0, 0, 0]], 'ver' => 1
        ], $override);
        return TasmotaV2DiscoveryModel::fromJson(json_encode($config));
    }
}
