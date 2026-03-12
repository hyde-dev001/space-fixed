<?php
// Fix PurchaseRequest.tsx - filter out items with existing PRs from dropdown
$file = __DIR__ . '/../resources/js/Pages/ERP/Procurement/PurchaseRequest.tsx';
$content = file_get_contents($file);

$t = "\t"; // single tab

// Build old string (9-12 tabs deep, CRLF line endings)
$old = $t.$t.$t.$t.$t.$t.$t.$t.$t.'{acceptedStockRequests.map((sr) => {'."\r\n"
    .$t.$t.$t.$t.$t.$t.$t.$t.$t.$t.'const alreadySubmitted = activeInventoryItemIds.has(String(sr.inventory_item_id));'."\r\n"
    .$t.$t.$t.$t.$t.$t.$t.$t.$t.$t.'return ('."\r\n"
    .$t.$t.$t.$t.$t.$t.$t.$t.$t.$t.$t.'<option key={sr.id} value={String(sr.inventory_item_id)} disabled={alreadySubmitted}>'."\r\n"
    .$t.$t.$t.$t.$t.$t.$t.$t.$t.$t.$t.$t.'{alreadySubmitted ? "[PR EXISTS] " : ""}{sr.request_number} \xe2\x80\x94 {sr.product_name} (Qty: {sr.quantity_needed}{sr.requested_size ? `, Size: ${sr.requested_size}` : ""})'."\r\n"
    .$t.$t.$t.$t.$t.$t.$t.$t.$t.$t.$t.'</option>'."\r\n"
    .$t.$t.$t.$t.$t.$t.$t.$t.$t.$t.');'."\r\n"
    .$t.$t.$t.$t.$t.$t.$t.$t.$t.'})}'."\r\n"
    .$t.$t.$t.$t.$t.$t.$t.$t.'</select>'."\r\n"
    .$t.$t.$t.$t.$t.$t.$t.$t.'{acceptedStockRequests.length === 0 && (';

// Build new string
$new = $t.$t.$t.$t.$t.$t.$t.$t.$t.'{acceptedStockRequests'."\r\n"
    .$t.$t.$t.$t.$t.$t.$t.$t.$t.$t.'.filter((sr) => !activeInventoryItemIds.has(String(sr.inventory_item_id)))'."\r\n"
    .$t.$t.$t.$t.$t.$t.$t.$t.$t.$t.'.map((sr) => ('."\r\n"
    .$t.$t.$t.$t.$t.$t.$t.$t.$t.$t.$t.'<option key={sr.id} value={String(sr.inventory_item_id)}>'."\r\n"
    .$t.$t.$t.$t.$t.$t.$t.$t.$t.$t.$t.$t.'{sr.request_number} \xe2\x80\x94 {sr.product_name} (Qty: {sr.quantity_needed}{sr.requested_size ? `, Size: ${sr.requested_size}` : ""})'."\r\n"
    .$t.$t.$t.$t.$t.$t.$t.$t.$t.$t.$t.'</option>'."\r\n"
    .$t.$t.$t.$t.$t.$t.$t.$t.$t.$t.'))}}'."\r\n"
    .$t.$t.$t.$t.$t.$t.$t.$t.'</select>'."\r\n"
    .$t.$t.$t.$t.$t.$t.$t.$t.'{acceptedStockRequests.filter((sr) => !activeInventoryItemIds.has(String(sr.inventory_item_id))).length === 0 && (';

if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    file_put_contents($file, $content);
    echo "SUCCESS: PurchaseRequest.tsx updated!\n";
} else {
    echo "FAILED: old string not found\n";
    // Debug: show what's around line 531
    $pos = strpos($content, 'acceptedStockRequests.map');
    if ($pos !== false) {
        echo "Found at pos $pos\n";
        $chunk = substr($content, $pos - 20, 300);
        echo "Chunk hex:\n";
        for ($i = 0; $i < strlen($chunk); $i++) {
            echo sprintf('%02x', ord($chunk[$i]));
            if ($i % 20 === 19) echo "\n";
            else echo ' ';
        }
        echo "\n";
    }
}
