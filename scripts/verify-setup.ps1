<#
.SYNOPSIS
    Xanh Hub Vietnam (De tai 6) - Production Readiness Verification Runner
.DESCRIPTION
    PowerShell wrapper to resolve PHP CLI binary and trigger the
    4-phase production readiness verification suite (bin/verify-production-readiness.php).
#>

[CmdletBinding()]
param (
    [Parameter(Mandatory = $false, Position = 0)]
    [string]$CustomPhpPath = ""
)

$ErrorActionPreference = "Continue"

# 1. Determine project root
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectRoot = Split-Path -Parent $ScriptDir
Set-Location -Path $ProjectRoot

# 2. Resolve PHP CLI Binary
$ResolvedPhp = ""

if ($CustomPhpPath -and (Test-Path $CustomPhpPath)) {
    $ResolvedPhp = $CustomPhpPath
} elseif (Test-Path "C:\xampp\php\php.exe") {
    $ResolvedPhp = "C:\xampp\php\php.exe"
} elseif ($env:PHP_BINARY -and (Test-Path $env:PHP_BINARY)) {
    $ResolvedPhp = $env:PHP_BINARY
} else {
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd -and (Test-Path $cmd.Source)) {
        $ResolvedPhp = $cmd.Source
    }
}

if (-not $ResolvedPhp -or -not (Test-Path $ResolvedPhp)) {
    Write-Host ""
    Write-Host "========================================================================" -ForegroundColor Red
    Write-Host " [ERROR] PHP CLI binary (php.exe) not found on system!" -ForegroundColor Red
    Write-Host "========================================================================" -ForegroundColor Red
    Write-Host " Please install PHP 8.2+ or provide path via parameter:"
    Write-Host " .\scripts\verify-setup.ps1 -CustomPhpPath 'C:\xampp\php\php.exe'"
    Write-Host "========================================================================" -ForegroundColor Red
    exit 1
}

# 3. Check verifier script bin/verify-production-readiness.php
$VerifierScript = Join-Path $ProjectRoot "bin\verify-production-readiness.php"
if (-not (Test-Path $VerifierScript)) {
    Write-Host ""
    Write-Host "========================================================================" -ForegroundColor Red
    Write-Host " [ERROR] Verifier script not found: $VerifierScript" -ForegroundColor Red
    Write-Host "========================================================================" -ForegroundColor Red
    exit 1
}

# 4. Display startup banner
Write-Host ""
Write-Host "========================================================================" -ForegroundColor Cyan
Write-Host " XANH HUB (DE TAI 6) - PRODUCTION READINESS VERIFIER (POWERSHELL)" -ForegroundColor Cyan
Write-Host "========================================================================" -ForegroundColor Cyan
Write-Host " Project Root : $ProjectRoot" -ForegroundColor DarkGray
Write-Host " PHP Binary   : $ResolvedPhp" -ForegroundColor DarkGray
Write-Host " Verifier     : bin\verify-production-readiness.php" -ForegroundColor DarkGray
Write-Host " Started At   : $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')" -ForegroundColor DarkGray
Write-Host "========================================================================" -ForegroundColor Cyan
Write-Host ""

# 5. Execute PHP verifier script and capture Exit Code
& $ResolvedPhp $VerifierScript $ResolvedPhp
$exitCode = $LASTEXITCODE

Write-Host ""
if ($exitCode -eq 0) {
    Write-Host "========================================================================" -ForegroundColor Green
    Write-Host " [VERIFICATION SUCCESS] ALL 4 PHASES PASSED PRODUCTION READINESS!" -ForegroundColor Green
    Write-Host " Exit Code: 0" -ForegroundColor Green
    Write-Host "========================================================================" -ForegroundColor Green
} else {
    Write-Host "========================================================================" -ForegroundColor Red
    Write-Host " [VERIFICATION FAILED] ERRORS DETECTED DURING VERIFICATION!" -ForegroundColor Red
    Write-Host " Exit Code: $exitCode" -ForegroundColor Red
    Write-Host "========================================================================" -ForegroundColor Red
}

exit $exitCode
