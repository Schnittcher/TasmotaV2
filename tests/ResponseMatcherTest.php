<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ResponseMatcherTest extends TestCase
{
    public function testResultWithCommandNameAsKey(): void
    {
        $this->assertTrue(TasmotaV2ResponseMatcher::matches('PowerOnState', 'stat', 'RESULT', '{"PowerOnState":3}'));
        $this->assertTrue(TasmotaV2ResponseMatcher::matches('poweronstate', 'stat', 'RESULT', '{"PowerOnState":"RESTORE"}'), 'Groß-/Kleinschreibung egal');
        $this->assertTrue(TasmotaV2ResponseMatcher::matches('FpCount', 'stat', 'RESULT', '{"FpCount":25}'));
    }

    public function testCommandWithNumberedResultKeys(): void
    {
        $this->assertTrue(TasmotaV2ResponseMatcher::matches('Power', 'stat', 'RESULT', '{"POWER":"ON"}'));
        $this->assertTrue(TasmotaV2ResponseMatcher::matches('Power', 'stat', 'RESULT', '{"POWER1":"ON"}'));
        $this->assertTrue(TasmotaV2ResponseMatcher::matches('Power2', 'stat', 'RESULT', '{"POWER2":"OFF"}'));
        $this->assertTrue(TasmotaV2ResponseMatcher::matches('Gpio', 'stat', 'RESULT', '{"GPIO0":{"Button1":32}}'));
    }

    public function testAnswerToAnotherCommandIsNotAnAnswer(): void
    {
        $this->assertFalse(TasmotaV2ResponseMatcher::matches('PowerOnState', 'stat', 'RESULT', '{"POWER1":"ON"}'));
        $this->assertFalse(TasmotaV2ResponseMatcher::matches('Power2', 'stat', 'RESULT', '{"POWER1":"ON"}'));
        $this->assertFalse(TasmotaV2ResponseMatcher::matches('MaxPower', 'stat', 'RESULT', '{"MaxPowerHold":10}'), 'MaxPowerHold ist eine andere Antwort');
    }

    public function testStatusCommandMatchesStatusReplies(): void
    {
        $this->assertTrue(TasmotaV2ResponseMatcher::matches('Status', 'stat', 'STATUS0', '{"Status":{"Module":18}}'));
        $this->assertTrue(TasmotaV2ResponseMatcher::matches('Status', 'stat', 'STATUS', '{"Status":{"Module":18}}'));
        $this->assertTrue(TasmotaV2ResponseMatcher::matches('Status', 'stat', 'STATUS5', '{"StatusNET":{}}'));
        $this->assertFalse(TasmotaV2ResponseMatcher::matches('Status', 'stat', 'RESULT', '{"POWER1":"ON"}'));
    }

    public function testSetOption4ReplyUnderTheCommandNameTopic(): void
    {
        $this->assertTrue(TasmotaV2ResponseMatcher::matches('Dimmer', 'stat', 'DIMMER', '{"Dimmer":10}'));
        $this->assertFalse(TasmotaV2ResponseMatcher::matches('Dimmer', 'stat', 'COLOR', '{"Color":"FF0000"}'));
    }

    public function testOnlyJsonRepliesFromTheStatTopicsCount(): void
    {
        $this->assertFalse(TasmotaV2ResponseMatcher::matches('Power', 'stat', 'POWER', 'ON'), 'Klartext ist keine Antwort');
        $this->assertFalse(TasmotaV2ResponseMatcher::matches('PowerOnState', 'tele', 'RESULT', '{"PowerOnState":3}'));
        $this->assertFalse(TasmotaV2ResponseMatcher::matches('', 'stat', 'RESULT', '{"A":1}'));
        $this->assertFalse(TasmotaV2ResponseMatcher::matches('Power', 'stat', 'RESULT', 'not json'));
    }
}
