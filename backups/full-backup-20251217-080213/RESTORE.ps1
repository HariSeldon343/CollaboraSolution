param(
  [string]$ProjectRoot = 'c:\xampp\htdocs\CollaboraNexio',
  [string]$DbUser = 'root',
  [string]$DbPassword = ''
)

$ErrorActionPreference = 'Stop'
$backupDir = $PSScriptRoot

Write-Host "Backup folder: $backupDir"
Write-Host "Target project root: $ProjectRoot"

if (!(Test-Path $ProjectRoot)) {
  throw "ProjectRoot not found: $ProjectRoot"
}

$sql = Get-ChildItem -Path $backupDir -Filter 'collaboranexio-db-*.sql' | Sort-Object Name -Descending | Select-Object -First 1
$zip = Get-ChildItem -Path $backupDir -Filter 'CollaboraNexio-files-*.zip' | Sort-Object Name -Descending | Select-Object -First 1

if (!$sql) { throw 'SQL dump not found in backup folder.' }
if (!$zip) { throw 'ZIP archive not found in backup folder.' }

$mysqlExe = 'C:\xampp\mysql\bin\mysql.exe'
if (!(Test-Path $mysqlExe)) {
  throw "mysql.exe not found at $mysqlExe"
}

Write-Host "SQL: $($sql.FullName)"
Write-Host "ZIP: $($zip.FullName)"

$confirm = Read-Host 'Confermi il RIPRISTINO COMPLETO? (s/N)'
if ($confirm.ToLower() -ne 's') {
  Write-Host 'Operazione annullata.'
  exit 0
}

Write-Host '1/2 Ripristino file (overwrite)...'
Expand-Archive -Path $zip.FullName -DestinationPath $ProjectRoot -Force

Write-Host '2/2 Ripristino database...'

# Build mysql auth args
$authArgs = @('-u', $DbUser)
if ($DbPassword -ne '') {
  # Using -pPASSWORD form to keep non-interactive restore
  $authArgs += ("-p$DbPassword")
}

# Use cmd.exe for reliable input redirection
$cmd = 'cmd.exe'
$cmdArgs = @('/c', '"' + $mysqlExe + '" ' + ($authArgs -join ' ') + ' < "' + $sql.FullName + '"')

$proc = Start-Process -FilePath $cmd -ArgumentList $cmdArgs -Wait -PassThru -NoNewWindow
if ($proc.ExitCode -ne 0) {
  throw "mysql import failed with exit code $($proc.ExitCode)"
}

Write-Host 'Ripristino completato con successo.'
