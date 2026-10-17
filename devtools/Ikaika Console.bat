@echo off
rem Double-click launcher for the Ikaika Dev Console.
pwsh -NoProfile -ExecutionPolicy Bypass -File "%~dp0ikaika-console.ps1" %*
if errorlevel 1 pause
