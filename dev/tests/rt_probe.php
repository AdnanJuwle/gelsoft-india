<?php
// Feeds each case through richtext()/richtext_plain() and prints JSON.
require 'C:/Users/adnan/Documents/repos/gelsoft-india/inc/helpers.php';
require 'C:/Users/adnan/Documents/repos/gelsoft-india/inc/richtext.php';
$cases = json_decode(file_get_contents('php://stdin'), true);
$out = [];
foreach ($cases as $k => $src) {
    $out[$k] = ['html' => richtext($src), 'plain' => richtext_plain($src)];
}
echo json_encode($out);
