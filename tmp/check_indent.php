<?php
$file = __DIR__ . '/../resources/js/Pages/ERP/Procurement/PurchaseRequest.tsx';
$lines = file($file);
foreach (array_slice($lines, 529, 14, true) as $no => $line) {
    $hex = '';
    for ($i = 0; $i < min(strlen($line), 30); $i++) {
        $hex .= sprintf('%02x ', ord($line[$i]));
    }
    echo ($no+1) . ': HEX[' . $hex . '] TEXT[' . rtrim($line) . "]\n";
}
