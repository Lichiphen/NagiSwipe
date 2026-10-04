# Start the local LOG preview. The first run builds it from the committed sample (dev/sample/*.zip);
# later runs keep the saved posts and settings.
[CmdletBinding()]
param(
    [ValidateRange(1024, 65535)][int]$Port = 5197,
    [string]$DataDirectory = '',
    # Also listen on this PC's LAN address so a phone on the same Wi-Fi can open the LOG.
    [switch]$Lan,
    # Start over from the sample. The current data folder is kept as a dated backup, not deleted.
    [switch]$Reset,
    # Start with an empty LOG instead of the sample.
    [switch]$Empty
)
$ErrorActionPreference = 'Stop'
if ($DataDirectory -eq '') { $DataDirectory = Join-Path $PSScriptRoot 'results/log-preview-data' }
$taskRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../server'))
$taskData = [IO.Path]::GetFullPath($DataDirectory)
$taskRouter = Join-Path $PSScriptRoot 'router.php'
$taskPhp = Get-Command php -ErrorAction SilentlyContinue
if (!$taskPhp) { throw 'PHP 8.1以上をインストールしてから、もう一度起動してください。' }
$taskExt = Join-Path (Split-Path $taskPhp.Source) 'ext'
$taskOptions = @('-n', '-d', "extension_dir=$taskExt")
foreach ($taskName in @('gd', 'fileinfo', 'zip', 'mbstring', 'sodium', 'openssl')) {
    $taskOptions += @('-d', "extension=$taskName")
}
$taskRequirements = '<?php exit(PHP_VERSION_ID >= 80100 && extension_loaded("gd") && extension_loaded("fileinfo") && extension_loaded("zip") && extension_loaded("mbstring") && extension_loaded("sodium") && extension_loaded("openssl") ? 0 : 1);'
$taskRequirements | & $taskPhp.Source @taskOptions
if ($LASTEXITCODE -ne 0) { throw 'PHPの必要な機能を読み込めません。GD・fileinfo・zip・mbstring・sodium・opensslを確認してください。' }
$taskProbe = [Net.Sockets.TcpClient]::new()
try { $taskProbe.Connect('127.0.0.1', $Port); $taskBusy = $taskProbe.Connected } catch { $taskBusy = $false } finally { $taskProbe.Dispose() }
if ($taskBusy) {
    Write-Host "ポート $Port は起動済みです。今回、新しいサーバーは起動していません。"
    Write-Host "LOG: http://127.0.0.1:$Port/"
    Write-Host '別のアプリが使っている場合は、-Portで別の番号を指定してください。'
    return
}
if ($Reset -and (Test-Path -LiteralPath $taskData)) {
    $taskBackup = "$taskData.backup-$(Get-Date -Format 'yyyyMMdd-HHmmss')"
    Move-Item -LiteralPath $taskData -Destination $taskBackup
    Write-Host "今までのデータは $taskBackup に移しました。"
}
if (!$Empty -and !(Test-Path -LiteralPath (Join-Path $taskData 'config.php'))) {
    $taskPython = Get-Command python -ErrorAction SilentlyContinue
    if (!$taskPython) { throw 'サンプルの作成にはPython 3が必要です。空のLOGで始める場合は -Empty を付けてください。' }
    & $taskPython.Source (Join-Path $PSScriptRoot 'sample_data.py') install $taskData
    if ($LASTEXITCODE -ne 0) { throw 'サンプルを作成できませんでした。' }
}
if (!(Test-Path -LiteralPath $taskData)) { New-Item -ItemType Directory -Path $taskData | Out-Null }
$taskPreviousData = [Environment]::GetEnvironmentVariable('NAGIMANGA_DATA', 'Process')
try {
    $env:NAGIMANGA_DATA = $taskData
    Write-Host "LOG: http://127.0.0.1:$Port/"
    Write-Host "管理: http://127.0.0.1:$Port/admin/login.php"
    $taskListen = '127.0.0.1'
    if ($Lan) {
        $taskListen = '0.0.0.0'
        Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } | ForEach-Object { Write-Host "スマホ（同じWi-Fi）: http://$($_.IPAddress):$Port/" }
        Write-Host '同じネットワークの端末から開けます。確認が終わったらCtrl+Cで止めてください。'
    }
    Write-Host "保存先: $taskData"
    if (!$Empty) { Write-Host 'サンプルの管理パスワード: log-test-password（このPCでの確認専用です）' }
    if (!(Test-Path -LiteralPath (Join-Path $taskData 'config.php'))) {
        Write-Host "初回は http://127.0.0.1:$Port/admin/ を開き、管理者パスワードを設定してください。"
    }
    Write-Host '終了はCtrl+Cです。次の起動でも、同じ投稿と設定を使います。'
    & $taskPhp.Source @taskOptions -d memory_limit=256M -d upload_max_filesize=32M -d post_max_size=40M -S "${taskListen}:$Port" -t $taskRoot $taskRouter
} finally {
    [Environment]::SetEnvironmentVariable('NAGIMANGA_DATA', $taskPreviousData, 'Process')
}
