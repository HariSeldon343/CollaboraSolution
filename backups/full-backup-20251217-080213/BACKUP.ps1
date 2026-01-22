param(
  [string]$ProjectRoot = 'c:\xampp\htdocs\CollaboraNexio',
  [string]$DbUser = 'root',
  [string]$DbPassword = ''
)

$ErrorActionPreference = 'Stop'

if (!(Test-Path $ProjectRoot)) {
  throw "ProjectRoot not found: $ProjectRoot"
}

$ts = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupDir = Join-Path $ProjectRoot ("backups\full-backup-$ts")
New-Item -ItemType Directory -Force -Path $backupDir | Out-Null

$mysqldumpExe = 'C:\xampp\mysql\bin\mysqldump.exe'
if (!(Test-Path $mysqldumpExe)) {
  throw "mysqldump.exe not found at $mysqldumpExe"
}

$sql = Join-Path $backupDir ("collaboranexio-db-$ts.sql")
$zip = Join-Path $backupDir ("CollaboraNexio-files-$ts.zip")

Write-Host "Backup dir: $backupDir"
Write-Host "DB dump: $sql"
Write-Host "ZIP: $zip"

# Build mysqldump auth args
$authArgs = @('-u', $DbUser)
if ($DbPassword -ne '') {
  $authArgs += ("-p$DbPassword")
}

& $mysqldumpExe @authArgs --routines --triggers --events --single-transaction --databases collaboranexio > $sql
if ($LASTEXITCODE -ne 0) {
  throw "mysqldump failed ($LASTEXITCODE)"
}

$items = Get-ChildItem $ProjectRoot -Force | Where-Object { $_.Name -notin @('backups','logs','.git') }
Compress-Archive -Force -Path $items.FullName -DestinationPath $zip -CompressionLevel Optimal

Write-Host 'Backup completato.'
Write-Host "Per ripristinare: esegui RESTORE.ps1 dentro $backupDir"
