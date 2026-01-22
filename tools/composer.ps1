$composerPhar = 'C:\OSPanel\data\PHP-8.4\default\composer\composer.phar'

if (Test-Path $composerPhar) {
  & php $composerPhar @args
  exit $LASTEXITCODE
}

Write-Error 'Composer not found. Install Composer or place composer.phar at project root.'
exit 1
