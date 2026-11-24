<?php
$tries = [
    'https://example.com/signup',
    'http://example.com',
    'javascript:alert(1)',
    'javascript://comment%0Aalert(1)',
    'data:text/html,<script>alert(1)</script>',
    'vbscript:msgbox(1)',
    'JaVaScRiPt:alert(1)',
    'file:///etc/passwd',
    'ftp://example.com/x',
];
foreach ($tries as $t) {
    printf("  %-44s %s\n", substr($t, 0, 44),
        filter_var($t, FILTER_VALIDATE_URL) !== false ? 'ACCEPTED' : 'rejected');
}
