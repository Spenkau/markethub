$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$runtime = Join-Path $projectRoot 'infra\runtime'
$php = 'C:\tools\php83\php-cgi.exe'
$nginx = 'C:\tools\nginx-1.30.6\nginx.exe'
$config = Join-Path $projectRoot 'infra\nginx.conf'
New-Item -ItemType Directory (Join-Path $runtime 'logs') -Force | Out-Null
New-Item -ItemType Directory (Join-Path $runtime 'temp') -Force | Out-Null
$prefix = $runtime.Replace('\', '/') + '/'

foreach ($name in @('Memurai')) {
    if ((Get-Service $name).Status -ne 'Running') {
        throw "Service $name is stopped. Run scripts/services.ps1 -Action Start as administrator."
    }
}
foreach ($port in @(80, 9000)) {
    if (Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue) {
        throw "Port $port is occupied. Stop the existing stack before starting."
    }
}
& $nginx -p $prefix -c $config -t
if ($LASTEXITCODE -ne 0) { throw 'Invalid nginx configuration.' }
$env:PHP_FCGI_MAX_REQUESTS = '0'
$cgi = Start-Process $php -ArgumentList '-b 127.0.0.1:9000 -c C:\tools\php83\php.ini' -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardError (Join-Path $runtime 'php-error.log')
@{ Id = $cgi.Id; StartTime = $cgi.StartTime.ToUniversalTime().ToString('o'); Path = $php } | ConvertTo-Json | Set-Content (Join-Path $runtime 'php-process.json')
try {
    Start-Process $nginx -ArgumentList "-p $prefix -c $config" -WorkingDirectory (Split-Path $nginx) -WindowStyle Hidden
    $ready = $false
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        Start-Sleep -Milliseconds 300
        try {
            $response = Invoke-WebRequest 'http://markethub.test/up' -UseBasicParsing -TimeoutSec 3
            if ($response.StatusCode -eq 200) { $ready = $true; break }
        } catch { }
    }
    if (-not $ready) { throw 'HTTP check failed. See infra/runtime/logs/error.log and php-error.log.' }
    Write-Host 'MarketHub is running: http://markethub.test'
} catch {
    & (Join-Path $PSScriptRoot 'stop.ps1')
    throw
}
