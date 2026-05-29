#Requires -Version 7
# Nextcloud App Store — génération de clés et archive signée

param(
    [string]$AppName    = "synoldap",
    [string]$AppVersion = "2.0.1"
)

$WorkDir   = "F:\Visual Studio Code\NC - Synology"
$CertDir   = "$env:USERPROFILE\.nextcloud\certificates"
$OutputDir = "$WorkDir\release"
$KeyFile   = "$CertDir\$AppName.key"
$CertFile  = "$CertDir\$AppName.crt"
$Archive   = "$OutputDir\$AppName-$AppVersion.tar.gz"
$SigFile   = "$OutputDir\$AppName-$AppVersion.sig"

# --- Prérequis -----------------------------------------------------------

New-Item -ItemType Directory -Force -Path $CertDir, $OutputDir | Out-Null

$OpenSSL = (Get-Command openssl -ErrorAction SilentlyContinue)?.Source
if (-not $OpenSSL) {
    $OpenSSL = "C:\Program Files\Git\usr\bin\openssl.exe"
}
if (-not (Test-Path $OpenSSL)) {
    Write-Error "OpenSSL introuvable. Installez Git for Windows ou OpenSSL."
    exit 1
}
Write-Host "OpenSSL : $OpenSSL" -ForegroundColor Gray

# --- 1. Paire de clés -----------------------------------------------------

if (-not (Test-Path $KeyFile)) {
    Write-Host "`n[1/4] Génération de la clé privée RSA 4096..." -ForegroundColor Cyan
    & $OpenSSL genrsa -out $KeyFile 4096
} else {
    Write-Host "`n[1/4] Clé privée existante : $KeyFile" -ForegroundColor Yellow
}

if (-not (Test-Path $CertFile)) {
    Write-Host "[2/4] Génération du certificat auto-signé..." -ForegroundColor Cyan
    & $OpenSSL req -new -x509 -key $KeyFile -out $CertFile -days 3650 -subj "/CN=$AppName"
} else {
    Write-Host "[2/4] Certificat existant : $CertFile" -ForegroundColor Yellow
}

# --- 2. Archive tar.gz (sans fichiers de dev) ----------------------------

Write-Host "[3/4] Création de l'archive..." -ForegroundColor Cyan

# Fichiers/dossiers à exclure
$Excludes = @(
    "--exclude=$AppName/.git",
    "--exclude=$AppName/node_modules",
    "--exclude=$AppName/vendor",
    "--exclude=$AppName/tests",
    "--exclude=$AppName/.github",
    "--exclude=$AppName/scripts",
    "--exclude=$AppName/*.log"
)

Push-Location $WorkDir
& tar -czf $Archive @Excludes $AppName
Pop-Location

if (-not (Test-Path $Archive)) {
    Write-Error "Échec de la création de l'archive."
    exit 1
}
$Size = [math]::Round((Get-Item $Archive).Length / 1KB, 1)
Write-Host "   Archive : $Archive ($Size KB)" -ForegroundColor Gray

# --- 3. Signature SHA-512 -------------------------------------------------

Write-Host "[4/4] Signature de l'archive..." -ForegroundColor Cyan
& $OpenSSL dgst -sha512 -sign $KeyFile -out $SigFile $Archive

$Signature = [Convert]::ToBase64String([System.IO.File]::ReadAllBytes($SigFile))

# --- Résultat -------------------------------------------------------------

Write-Host "`n=== TERMINÉ ===" -ForegroundColor Green
Write-Host "Archive    : $Archive"
Write-Host "Certificat : $CertFile"
Write-Host ""
Write-Host "--- Signature (à copier pour l'API du store) ---" -ForegroundColor Cyan
Write-Host $Signature
Write-Host ""
Write-Host "--- Contenu du certificat (à enregistrer sur apps.nextcloud.com) ---" -ForegroundColor Cyan
Get-Content $CertFile
