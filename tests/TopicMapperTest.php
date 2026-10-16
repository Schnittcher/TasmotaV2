<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TopicMapperTest extends TestCase
{
    public function testDefaultFullTopic(): void
    {
        $mapper = new TasmotaV2TopicMapper('%prefix%/%topic%/', 'wz_licht');
        $this->assertSame('cmnd/wz_licht/Power1', $mapper->commandTopic('Power1'));
        $this->assertSame(['kind' => 'tele', 'suffix' => 'STATE'], $mapper->parse('tele/wz_licht/STATE'));
        $this->assertSame(['kind' => 'stat', 'suffix' => 'RESULT'], $mapper->parse('stat/wz_licht/RESULT'));
    }

    public function testOtherDevicesAndCommandTopicsDoNotMatch(): void
    {
        $mapper = new TasmotaV2TopicMapper('%prefix%/%topic%/', 'wz_licht');
        $this->assertNull($mapper->parse('stat/wz_licht2/RESULT'), 'Präfix eines anderen Topics');
        $this->assertNull($mapper->parse('stat/xwz_licht/RESULT'));
        $this->assertNull($mapper->parse('cmnd/wz_licht/Power'), 'Befehle sind keine Antworten');
        $this->assertNull($mapper->parse('tele/wz_licht'), 'ohne Suffix');
    }

    public function testPrefixAfterTopicAndFixedSegments(): void
    {
        $mapper = new TasmotaV2TopicMapper('tasmota/%topic%/%prefix%/', 'x-1');
        $this->assertSame('tasmota/x-1/cmnd/Status', $mapper->commandTopic('Status'));
        $this->assertSame(['kind' => 'tele', 'suffix' => 'LWT'], $mapper->parse('tasmota/x-1/tele/LWT'));
        $this->assertNull($mapper->parse('tasmota/x_1/tele/LWT'), 'Sonderzeichen im Topic werden maskiert');
    }

    public function testFullTopicWithoutPrefixTokenGetsPrefixInFront(): void
    {
        $mapper = new TasmotaV2TopicMapper('home/%topic%', 'a');
        $this->assertSame('cmnd/home/a/Power', $mapper->commandTopic('Power'));
    }

    public function testHostnameTokenIsResolvedOrMatchesAnySegment(): void
    {
        $resolved = new TasmotaV2TopicMapper('%prefix%/%hostname%/%topic%', 'a', ['%hostname%' => 'tas-1']);
        $this->assertSame('cmnd/tas-1/a/Power', $resolved->commandTopic('Power'));
        $unresolved = new TasmotaV2TopicMapper('%prefix%/%hostname%/%topic%', 'a');
        $this->assertSame(['kind' => 'stat', 'suffix' => 'RESULT'], $unresolved->parse('stat/anything/a/RESULT'));
    }

    public function testUnusableWithoutTopic(): void
    {
        $mapper = new TasmotaV2TopicMapper('%prefix%/%topic%/', '');
        $this->assertFalse($mapper->isUsable());
        $this->assertNull($mapper->parse('tele/x/LWT'));
    }

    public function testDataFilterMatchesDataPacketJson(): void
    {
        $mapper = new TasmotaV2TopicMapper('%prefix%/%topic%/', 'wz_licht');
        $filter = '~.*(' . $mapper->dataFilterFragment() . ').*~';
        $this->assertSame(1, preg_match($filter, '{"DataID":"{X}","Topic":"tele/wz_licht/STATE","Payload":"7b7d"}'));
        $this->assertSame(0, preg_match($filter, '{"DataID":"{X}","Topic":"tele/wz_licht2/STATE","Payload":"7b7d"}'));
        $this->assertSame(0, preg_match($filter, '{"DataID":"{X}","Topic":"cmnd/wz_licht/Power","Payload":"4f4e"}'));
    }
}
