@echo off
setlocal

set "MYSQL_EXE=C:\xampp\mysql\bin\mysql.exe"
set "SEED_FILE=%~dp0seed_rooms_and_instructors.sql"

if not exist "%MYSQL_EXE%" (
  echo MySQL executable not found at: %MYSQL_EXE%
  exit /b 1
)

if not exist "%SEED_FILE%" (
  echo Seed file not found: %SEED_FILE%
  exit /b 1
)

echo Importing rooms and instructors seed into class_scheduling...
"%MYSQL_EXE%" -u root -D class_scheduling < "%SEED_FILE%"

if errorlevel 1 (
  echo Import failed.
  exit /b 1
)

echo Import completed successfully.
exit /b 0
