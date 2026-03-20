
$filePath = "resources/js/Pages/ERP/Manager/AuditLogs.tsx"
$content = Get-Content -Path $filePath -Raw

# Check if humanizeValue already exists
if ($content -match "const humanizeValue") {
    Write-Output "humanizeValue function already exists"
    exit 0
}

# Add the humanizeValue function
$pattern = "return value\.toString\(\);\s+\};\s+// Parse user agent"
$replacement = @"
return value.toString();
  };

  // Convert database enum values to human-readable labels
  const humanizeValue = (value: any, fieldName: string = ''): string => {
    if (value === null || value === undefined) return 'N/A';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (typeof value === 'object') return JSON.stringify(value);
    if (typeof value === 'number') return formatValue(value);
    
    const str = value.toString();
    
    // Convert database enum Convention: assigned_to_repairer → Assigned to Repairer
    return str
      .split('_')
      .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
      .join(' ');
  };

  // Parse user agent
"@

$newContent = $content -replace $pattern, $replacement
Set-Content -Path $filePath -Value $newContent
Write-Output "Added humanizeValue function"

# Now update the method calls
$oldCall = "const oldVal = formatValue(change?.old);" + [Environment]::NewLine + "          const newVal = formatValue(change?.new);"
$newCall = "const oldVal = humanizeValue(change?.old, changedFields[0]);" + [Environment]::NewLine + "          const newVal = humanizeValue(change?.new, changedFields[0]);"

$content = Get-Content -Path $filePath -Raw
$newContent = $content -replace [regex]::Escape($oldCall), $newCall
Set-Content -Path $filePath -Value $newContent
Write-Output "Updated method calls to use humanizeValue"
