<?php

declare(strict_types=1);

// Die Bibliothek testet reine PHP-Logik ohne Symcon-Laufzeit. Nur die Presentation-GUIDs stellt sonst der Kernel bereit.
$presentations = [
    'VARIABLE_PRESENTATION_VALUE_PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
    'VARIABLE_PRESENTATION_VALUE_INPUT'        => '{6F477326-1683-A2FD-D2E7-477F366ECB62}',
    'VARIABLE_PRESENTATION_SLIDER'             => '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}',
    'VARIABLE_PRESENTATION_COLOR'              => '{05CC3CC2-A0B2-5837-A4A7-A07EA0B9DDFB}',
    'VARIABLE_PRESENTATION_SWITCH'             => '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}',
    'VARIABLE_PRESENTATION_SHUTTER'            => '{6075FC22-69AF-B110-3749-C24138883082}',
    'VARIABLE_PRESENTATION_ENUMERATION'        => '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}'
];
foreach ($presentations as $name => $guid) {
    if (!defined($name)) {
        define($name, $guid);
    }
}

require_once __DIR__ . '/../libs/TopicMapper.php';
require_once __DIR__ . '/../libs/DiscoveryModel.php';
require_once __DIR__ . '/../libs/MessageParser.php';
require_once __DIR__ . '/../libs/CommandTable.php';
require_once __DIR__ . '/../libs/ResponseMatcher.php';
