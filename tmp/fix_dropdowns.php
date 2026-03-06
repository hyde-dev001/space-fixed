<?php
// Fix 1: PurchaseRequest.tsx - filter out items with existing PRs
$file = __DIR__ . '/../resources/js/Pages/ERP/Procurement/PurchaseRequest.tsx';
$lines = file($file);

// Lines are 0-indexed. We need to replace indices 530-539 (inclusive)
// (lines 531-540 in 1-based).
// Get the indentation from the existing map line (index 530)
$mapLine = $lines[530]; 
preg_match('/^(\t+)/', $mapLine, $m);
$indent9 = $m[1]; // 9 tabs
$indent8 = substr($indent9, 0, 8);
$indent10 = $indent9 . "\t";
$indent11 = $indent10 . "\t";
$indent12 = $indent11 . "\t";

// Get content line (index 534) to preserve the exact em dash and template literal
$contentLine = rtrim($lines[534]); // {alreadySubmitted ? "[PR EXISTS] " : ""}{sr.request_number} — ...
// Strip its leading whitespace
$contentText = ltrim($contentLine);
// We want the same content but without the alreadySubmitted prefix:
// Remove the `{alreadySubmitted ? "[PR EXISTS] " : ""}` prefix
$newContentText = preg_replace('/^\{alreadySubmitted \? "\[PR EXISTS\] " : ""\}/', '', $contentText);

$eol = "\r\n";

// Build new lines to replace lines 530-539
$newBlock = [
    $indent9 . '{acceptedStockRequests' . $eol,
    $indent10 . '.filter((sr) => !activeInventoryItemIds.has(String(sr.inventory_item_id)))' . $eol,
    $indent10 . '.map((sr) => (' . $eol,
    $indent11 . '<option key={sr.id} value={String(sr.inventory_item_id)}>' . $eol,
    $indent12 . $newContentText . $eol,
    $indent11 . '</option>' . $eol,
    $indent10 . '))}' . $eol,
    $indent8 . '</select>' . $eol,
    $indent8 . '{acceptedStockRequests.filter((sr) => !activeInventoryItemIds.has(String(sr.inventory_item_id))).length === 0 && (' . $eol,
];

// Splice in the new block (replacing indices 530-539, which is 10 lines)
array_splice($lines, 530, 10, $newBlock);

file_put_contents($file, implode('', $lines));
echo "SUCCESS: PurchaseRequest.tsx updated!\n";
echo "New content line (stripped em dash check): $newContentText\n";

// Fix 2: PurchaseOrders.tsx - add requested_size to option label + empty state
$file2 = __DIR__ . '/../resources/js/Pages/ERP/Procurement/PurchaseOrders.tsx';
$lines2 = file($file2);

// Find the approvedPRs.map line
$poMapLine = -1;
foreach ($lines2 as $i => $line) {
    if (strpos($line, 'approvedPRs.map((pr) => (') !== false) {
        $poMapLine = $i;
        break;
    }
}
echo "PO map line index: $poMapLine\n";

if ($poMapLine === -1) {
    die("FAILED: Could not find approvedPRs.map line\n");
}

// Print lines around it
for ($i = $poMapLine - 1; $i <= $poMapLine + 6; $i++) {
    echo ($i+1) . ': ' . $lines2[$i];
}

// The option content line is at poMapLine + 2
$optContentLine = rtrim($lines2[$poMapLine + 2]);
echo "\nOption content: $optContentLine\n";

// Current: {pr.pr_number} - {pr.product_name} (Qty: {pr.quantity}, {currency.format(pr.total_cost)})
// New:     {pr.pr_number} - {pr.product_name} (Qty: {pr.quantity}{pr.requested_size ? `, Size: ${pr.requested_size}` : ""}, {currency.format(pr.total_cost)})
$newOptContent = preg_replace(
    '/\(Qty: \{pr\.quantity\}, \{currency/',
    '(Qty: {pr.quantity}{pr.requested_size ? `, Size: ${pr.requested_size}` : ""}, {currency',
    $optContentLine
);
$lines2[$poMapLine + 2] = $newOptContent . "\r\n";

// Find the </select> after this map block and add empty-state after it
// The structure: mapLine, mapLine+1 (<option key...), mapLine+2 (content), mapLine+3 (</option>), mapLine+4 (  ))}), mapLine+5 (</select>)
$closeSelectLine = -1;
for ($i = $poMapLine + 4; $i < $poMapLine + 10; $i++) {
    if (strpos($lines2[$i], '</select>') !== false) {
        $closeSelectLine = $i;
        break;
    }
}
echo "Close select line: " . ($closeSelectLine + 1) . "\n";

if ($closeSelectLine !== -1) {
    // Get indentation from the </select> line
    preg_match('/^(\t+)/', $lines2[$closeSelectLine], $m2);
    $selIndent = isset($m2[1]) ? $m2[1] : "\t\t\t\t\t\t\t\t";
    
    // Insert empty-state message after </select>
    $emptyMsg = $selIndent . '{approvedPRs.length === 0 && (' . "\r\n"
        . $selIndent . "\t" . '<p className="mt-1 text-xs text-amber-600 dark:text-amber-400">&#9888; No approved PRs available. All approved PRs may already have purchase orders.</p>' . "\r\n"
        . $selIndent . ')}' . "\r\n";
    
    array_splice($lines2, $closeSelectLine + 1, 0, [$emptyMsg]);
    file_put_contents($file2, implode('', $lines2));
    echo "SUCCESS: PurchaseOrders.tsx updated!\n";
} else {
    echo "FAILED: Could not find </select> after approvedPRs.map\n";
}
