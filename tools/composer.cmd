@echo off
setlocal
set COMPOSER_PHAR=C:\OSPanel\data\PHP-8.4\default\composer\composer.phar
if exist "%COMPOSER_PHAR%" (
  php "%COMPOSER_PHAR%" %*
  exit /b %ERRORLEVEL%
)
echo Composer not found. Install Composer or place composer.phar at project root.
exit /b 1
