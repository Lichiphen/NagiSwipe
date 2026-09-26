@echo off
rem Development server for NagiManga (PHP built-in server, not for production)
rem Data goes to dev\data so the repository stays clean.
setlocal
set "NAGIMANGA_DATA=%~dp0data"
for /f "delims=" %%i in ('where php') do set "PHPDIR=%%~dpi" & goto :found
:found
php -n -d extension_dir="%PHPDIR%ext" -d extension=gd -d extension=fileinfo -d extension=zip -d extension=mbstring -d extension=exif ^
    -d memory_limit=256M -d upload_max_filesize=32M -d post_max_size=40M -d max_file_uploads=50 ^
    -S 127.0.0.1:5190 -t "%~dp0..\server" "%~dp0router.php"
