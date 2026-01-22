$root = Split-Path -Parent $PSScriptRoot

Write-Output '== PHP version =='
& php -v

Write-Output ''
Write-Output '== PHP ini =='
& php --ini

Write-Output ''
Write-Output '== PHP extensions =='
& php -m

Write-Output ''
Write-Output '== Composer (tools/composer.cmd -V) =='
$composerCmd = Join-Path $PSScriptRoot 'composer.cmd'
if (Test-Path $composerCmd) {
  & $composerCmd -V
  if ($LASTEXITCODE -ne 0) {
    Write-Output ("Composer exit code: {0}" -f $LASTEXITCODE)
  }
} else {
  Write-Output 'tools/composer.cmd not found.'
}

Write-Output ''
Write-Output '== Git status =='
$gitCmd = Get-Command git -ErrorAction SilentlyContinue
if ($gitCmd) {
  & git -C $root rev-parse --is-inside-work-tree *> $null
  if ($LASTEXITCODE -eq 0) {
    & git -C $root status -sb
  } else {
    Write-Output 'Git repo not detected.'
  }
} else {
  Write-Output 'Git not found.'
}

Write-Output ''
Write-Output '== Directory sanity =='
$dirs = @('docs', 'storage', 'vendor')
foreach ($dir in $dirs) {
  $path = Join-Path $root $dir
  if (Test-Path $path) {
    Write-Output ("{0}: OK" -f $dir)
  } else {
    Write-Output ("{0}: MISSING" -f $dir)
  }
}
