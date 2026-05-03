# Register AVILIGHT Start All as a startup task
# Run this as Administrator

$taskName = "AVILIGHT Start All"
$scriptPath = "C:\laragon\www\GitHub\avilight\start_all.ps1"

$action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-NoProfile -ExecutionPolicy RemoteSigned -File $scriptPath"
$trigger = New-ScheduledTaskTrigger -AtLogOn
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries

Register-ScheduledTask -TaskName $taskName `
    -Action $action `
    -Trigger $trigger `
    -Settings $settings `
    -RunLevel Highest `
    -Force `
    -Description "Prepares Python environment and starts the AVILIGHT backend at system startup"

Write-Host "Task '$taskName' registered successfully."
Write-Host "The script will now run automatically at system startup with elevated privileges."
