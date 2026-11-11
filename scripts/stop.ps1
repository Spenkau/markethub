$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$runtime = Join-Path $projectRoot 'infra\runtime'
$pidFile = Join-Path $runtime 'logs\nginx.pid'
$nginx = 'C:\tools\nginx-1.30.6\nginx.exe'
if (Test-Path $pidFile) {
    $nginxId = [int](Get-Content $pidFile -Raw).Trim()
    $process = Get-Process -Id $nginxId -ErrorAction SilentlyContinue
    if ($process -and $process.Path -eq $nginx) {
        & $nginx -p ($runtime.Replace('\', '/') + '/') -c (Join-Path $projectRoot 'infra\nginx-spa.conf') -s quit
        if ($LASTEXITCODE -ne 0) { throw 'Could not stop nginx.' }
        $process | Wait-Process -Timeout 15
    }
}
$phpState = Join-Path $runtime 'php-process.json'
if (Test-Path $phpState) {
    $saved = Get-Content $phpState -Raw | ConvertFrom-Json
    $process = Get-Process -Id $saved.Id -ErrorAction SilentlyContinue
    if ($process -and $process.Path -eq $saved.Path -and $process.StartTime.ToUniversalTime().ToString('o') -eq $saved.StartTime) {
        $process | Stop-Process
    }
    Remove-Item -LiteralPath $phpState
}
Write-Host 'MarketHub nginx and PHP FastCGI stopped. Database and cache services are unchanged.'
