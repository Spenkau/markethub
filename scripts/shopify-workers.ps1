param([ValidateSet('Start', 'Stop', 'Status')][string]$Action = 'Status')
# Compatibility entry point: only the common worker and scheduler are managed.
& (Join-Path $PSScriptRoot 'marketplace-workers.ps1') -Action $Action
