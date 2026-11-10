param([ValidateSet('Start', 'Stop')][string]$Action = 'Start')
$ErrorActionPreference = 'Stop'
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this script in an administrator PowerShell window.'
}
foreach ($name in @('markethub-postgresql', 'Memurai')) {
    if ($Action -eq 'Start') { Start-Service $name } else { Stop-Service $name }
}
Get-Service markethub-postgresql, Memurai
