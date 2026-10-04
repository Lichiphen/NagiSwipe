# Start the current local LOG without recreating or deleting its saved data.
[CmdletBinding()]
param(
    [ValidateRange(1024, 65535)][int]$Port = 5197,
    [string]$DataDirectory = ''
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
if (!(Test-Path -LiteralPath $taskData)) { New-Item -ItemType Directory -Path $taskData | Out-Null }
$taskPreviousData = [Environment]::GetEnvironmentVariable('NAGIMANGA_DATA', 'Process')
try {
    $env:NAGIMANGA_DATA = $taskData
    Write-Host "LOG: http://127.0.0.1:$Port/"
    Write-Host "管理: http://127.0.0.1:$Port/admin/login.php"
    Write-Host "保存先: $taskData"
    if (!(Test-Path -LiteralPath (Join-Path $taskData 'config.php'))) {
        Write-Host "初回は http://127.0.0.1:$Port/admin/ を開き、管理者パスワードを設定してください。"
    }
    Write-Host '終了はCtrl+Cです。次の起動でも、同じ投稿と設定を使います。'
    & $taskPhp.Source @taskOptions -d memory_limit=256M -d upload_max_filesize=32M -d post_max_size=40M -S "127.0.0.1:$Port" -t $taskRoot $taskRouter
} finally {
    [Environment]::SetEnvironmentVariable('NAGIMANGA_DATA', $taskPreviousData, 'Process')
}
