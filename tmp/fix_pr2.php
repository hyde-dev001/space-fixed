<?php
// Fix PurchaseRequest.tsx dropdown - use line-range approach
$file = __DIR__ . '/../resources/js/Pages/ERP/Procurement/PurchaseRequest.tsx';
$lines = file($file); // preserves line endings

// Find the line with acceptedStockRequests.map
$mapLine = -1;
$closeParen = -1;
$emptyCheck = -1;

foreach ($lines as $i => $line) {
    if (strpos($line, 'acceptedStockRequests.map((sr) => {') !== false) {
        $mapLine = $i;
    }
    // The closing })} right after the map
    if ($mapLine >= 0 && $i > $mapLine && strpos($line, 'acceptedStockRequests.length === 0') !== false) {
        $emptyCheck = $i;
        break;
    }
}

echo "mapLine: $mapLine\n";
echo "emptyCheck: $emptyCheck\n";

if ($mapLine === -1 || $emptyCheck === -1) {
    die("Could not find the target lines\n");
}

// The block is lines $mapLine through ($emptyCheck - 2) which is the </select> line
// Let's see what lines $mapLine through $emptyCheck look like:
for ($i = $mapLine; $i <= $emptyCheck; $i++) {
    echo ($i+1) . ': ' . $lines[$i];
}
