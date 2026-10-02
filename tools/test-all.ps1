# Runs every test suite from the project root and stops at the first failure.
# Usage: .\tools\test-all.ps1
# Needs MySQL (XAMPP) running, because the PHP tests use the crms_test database.
# Written for Windows PowerShell 5.1, so it avoids && and ||.

Set-Location (Split-Path -Parent $PSScriptRoot)

$suites = @(
    @{ Name = 'PHP';                 Command = { php artisan test } },
    @{ Name = 'JavaScript';          Command = { npm run test:js } },
    @{ Name = 'Python (line detection)'; Command = { & 'ml\.venv-kraken\Scripts\python.exe' -m unittest tests.Python.test_line_markers tests.Python.test_grid_layouts } },
    @{ Name = 'Python (evaluation)'; Command = { & '.venv\Scripts\python.exe' -m unittest tests.Python.test_evaluation_report } }
)

foreach ($suite in $suites) {
    Write-Host ""
    Write-Host "=== $($suite.Name) tests ===" -ForegroundColor Cyan
    & $suite.Command
    if ($LASTEXITCODE -ne 0) {
        Write-Host ""
        Write-Host "FAILED: the $($suite.Name) tests failed (exit code $LASTEXITCODE). Later suites were not run." -ForegroundColor Red
        exit 1
    }
}

Write-Host ""
Write-Host 'All test suites passed.' -ForegroundColor Green
exit 0
