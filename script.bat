@echo off
rem Thin launcher for script.ps1 at the repo root, which forwards into the
rem ikaika_scripts toolkit at decoupled-script\. Here so the tooling works from a
rem plain cmd prompt or a double-click. PowerShell selection (pwsh, falling back
rem to Windows PowerShell 5.1) is shared with every other launcher in
rem decoupled-script\lib\run-ps1.bat.
rem Arguments pass through, e.g.: script.bat build-release -Platform web
setlocal
set "PS1=%~dp0script.ps1"
call "%~dp0decoupled-script\lib\run-ps1.bat" %*
exit /b %ERRORLEVEL%
