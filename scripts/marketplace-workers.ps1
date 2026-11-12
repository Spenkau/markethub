param([ValidateSet('Start', 'Stop', 'Status')][string]$Action = 'Status', [string]$PhpExecutable = (Get-Command php -ErrorAction Stop).Source)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$runtime = Join-Path $projectRoot 'infra\runtime'
$phpExecutable = (Resolve-Path -LiteralPath $PhpExecutable).Path
$statePath = Join-Path $runtime 'marketplace-workers.json'

function Get-VerifiedWorkers {
    if (-not (Test-Path -LiteralPath $statePath)) { return @() }
    $records = Get-Content -Raw -LiteralPath $statePath | ConvertFrom-Json
    foreach ($record in $records) {
        $process = Get-Process -Id $record.Id -ErrorAction SilentlyContinue
        if ($process -and $process.Path -eq $phpExecutable -and $process.StartTime.ToUniversalTime().ToString('o') -eq $record.StartTime) {
            [pscustomobject]@{ Record = $record; Process = $process }
        }
    }
}

$running = @(Get-VerifiedWorkers)
if ($Action -eq 'Status') {
    if (-not $running.Count) { Write-Host 'Marketplace worker and scheduler are stopped.' }
    $running | ForEach-Object { Write-Host ($_.Record.Role + ': running (PID ' + $_.Process.Id + ')') }
    exit
}
if ($Action -eq 'Stop') {
    # Ask Laravel to stop the worker after its current job; stop the scheduler immediately.
    & $phpExecutable (Join-Path $projectRoot 'artisan') queue:restart
    $running | Where-Object { $_.Record.Role -eq 'scheduler' } | ForEach-Object { Stop-Process -Id $_.Process.Id }
    Write-Host 'Scheduler stopped; queue worker will exit after its current job. Check -Action Status before restarting.'
    exit
}
if ($running.Count) { throw 'Tracked processes are already running. Check Status or Stop before starting.' }
$runtimeConfigJson = & $phpExecutable (Join-Path $projectRoot 'artisan') marketplaces:sync-runtime
if ($LASTEXITCODE -ne 0) { throw 'Queue runtime validation failed. Check Redis and retry_after settings.' }
$runtimeConfig = $runtimeConfigJson | ConvertFrom-Json
New-Item -ItemType Directory -Path $runtime -Force | Out-Null
$workers = @()
try {
    foreach ($definition in @(
        @{ Role = 'worker'; Arguments = @('artisan', 'queue:work', $runtimeConfig.connection, ('--queue=' + $runtimeConfig.queue + ',shopify'), '--sleep=3', '--tries=3', '--timeout=1800') },
        @{ Role = 'scheduler'; Arguments = @('artisan', 'schedule:work') }
    )) {
        $process = Start-Process -FilePath $phpExecutable -ArgumentList $definition.Arguments -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtime ('marketplace-' + $definition.Role + '.log')) -RedirectStandardError (Join-Path $runtime ('marketplace-' + $definition.Role + '-error.log'))
        $workers += @{ Id = $process.Id; StartTime = $process.StartTime.ToUniversalTime().ToString('o'); Role = $definition.Role }
    }
    $workers | ConvertTo-Json | Set-Content -LiteralPath $statePath
    Write-Host 'Marketplace queue worker and Laravel Scheduler started in hidden windows.'
} catch {
    foreach ($worker in $workers) { Stop-Process -Id $worker.Id -ErrorAction SilentlyContinue }
    throw
}
