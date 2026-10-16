<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CommandTableTest extends TestCase
{
    public function testPower(): void
    {
        $this->assertSame(['command' => 'Power2', 'payload' => 'ON'], TasmotaV2CommandTable::resolve('Power2', true));
        $this->assertSame(['command' => 'Power1', 'payload' => 'OFF'], TasmotaV2CommandTable::resolve('Power1', false));
    }

    public function testPercentValuesAreClamped(): void
    {
        $this->assertSame(['command' => 'Dimmer', 'payload' => '100'], TasmotaV2CommandTable::resolve('Dimmer', 150));
        $this->assertSame(['command' => 'Dimmer', 'payload' => '0'], TasmotaV2CommandTable::resolve('Dimmer', -5));
        $this->assertSame(['command' => 'Channel3', 'payload' => '40'], TasmotaV2CommandTable::resolve('Channel3', 40));
    }

    public function testColorTemperatureAndSpeedAreClamped(): void
    {
        $this->assertSame('153', TasmotaV2CommandTable::resolve('CT', 100)['payload']);
        $this->assertSame('500', TasmotaV2CommandTable::resolve('CT', 900)['payload']);
        $this->assertSame('40', TasmotaV2CommandTable::resolve('Speed', 99)['payload']);
    }

    public function testSchemeIsLimitedToTheKnownPrograms(): void
    {
        $this->assertSame('14', TasmotaV2CommandTable::resolve('Scheme', 99)['payload']);
        $this->assertSame('0', TasmotaV2CommandTable::resolve('Scheme', -3)['payload']);
        $this->assertSame('11', TasmotaV2CommandTable::resolve('Scheme', 11)['payload']);
    }

    public function testFade(): void
    {
        $this->assertSame(['command' => 'Fade', 'payload' => '1'], TasmotaV2CommandTable::resolve('Fade', true));
        $this->assertSame(['command' => 'Fade', 'payload' => '0'], TasmotaV2CommandTable::resolve('Fade', false));
    }

    public function testColorKeepsDimmer(): void
    {
        $this->assertSame(['command' => 'HSBColor', 'payload' => '0,100,40'], TasmotaV2CommandTable::resolve('Color', 0xFF0000, 40));
        $this->assertSame('120,100,100', TasmotaV2CommandTable::resolve('Color', 0x00FF00)['payload'], 'ohne Dimmer volle Helligkeit');
        $this->assertSame('240,100,40', TasmotaV2CommandTable::resolve('Color', 0x0000FF, 40)['payload']);
        $this->assertSame('0,0,40', TasmotaV2CommandTable::resolve('Color', 0xFFFFFF, 40)['payload'], 'Weiß hat keine Sättigung');
        $this->assertSame('60,100,40', TasmotaV2CommandTable::resolve('Color', 0xFFFF00, 40)['payload']);
    }

    public function testShutter(): void
    {
        $this->assertSame(['command' => 'ShutterPosition1', 'payload' => '25'], TasmotaV2CommandTable::resolve('Shutter1Position', 25));
        $this->assertSame(['command' => 'ShutterTilt2', 'payload' => '-20'], TasmotaV2CommandTable::resolve('Shutter2Tilt', -20));
        $this->assertSame(['command' => 'ShutterOpen1', 'payload' => ''], TasmotaV2CommandTable::resolve('Shutter1Control', 0));
        $this->assertSame(['command' => 'ShutterStop1', 'payload' => ''], TasmotaV2CommandTable::resolve('Shutter1Control', 1));
        $this->assertSame(['command' => 'ShutterClose1', 'payload' => ''], TasmotaV2CommandTable::resolve('Shutter1Control', 2));
        $this->assertNull(TasmotaV2CommandTable::resolve('Shutter1Control', 7));
        $this->assertSame(['command' => 'ShutterLock1', 'payload' => '1'], TasmotaV2CommandTable::resolve('Shutter1Lock', true));
        $this->assertSame(['command' => 'ShutterLock2', 'payload' => '0'], TasmotaV2CommandTable::resolve('Shutter2Lock', false));
    }

    public function testUnknownAndReadOnlyIdentsYieldNothing(): void
    {
        $this->assertNull(TasmotaV2CommandTable::resolve('Online', true));
        $this->assertNull(TasmotaV2CommandTable::resolve('Button1', 'x'));
        $this->assertNull(TasmotaV2CommandTable::resolve('Sensor_BME280_Temperature', 1));
    }
}
