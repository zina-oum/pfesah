# Script PowerShell pour sauvegarder la base de données
# Utilisation: .\backup_db.ps1

param(
    [string]$BackupPath = ".\backup_database_$(Get-Date -Format 'yyyyMMdd_HHmmss').sql"
)

$MySQLPath = "C:\xampp\mysql\bin\mysqldump.exe"
$DBName = "bus_transport"
$DBUser = "root"
$DBPass = ""

Write-Host "Sauvegarde de la base $DBName..." -ForegroundColor Yellow

try {
    & $MySQLPath -u $DBUser -p"$DBPass" $DBName > $BackupPath

    if ($LASTEXITCODE -eq 0) {
        Write-Host "SUCCES: Sauvegarde creee: $BackupPath" -ForegroundColor Green
    } else {
        Write-Host "ERREUR: Echec de la sauvegarde (code: $LASTEXITCODE)" -ForegroundColor Red
    }
} catch {
    Write-Host "ERREUR: $($_.Exception.Message)" -ForegroundColor Red
}

Read-Host "Appuyez sur Entree pour continuer"