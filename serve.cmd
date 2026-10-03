@echo off
rem Supervisor for the dev API server.
rem php -S dies silently sometimes (killed from outside the process, no PHP
rem error), so restart it whenever it exits and log the exit code + uptime so
rem the cause is visible instead of guessing.
rem
rem Must use `artisan serve`, NOT a bare `php -S`: Laravel's router script
rem (Foundation/resources/server.php) resolves the public path from getcwd(),
rem and artisan serve chdirs into public/ first. Running php -S from the
rem backend root makes it look for backend/index.php, which prints a PHP
rem warning before every JSON response and breaks the API.
setlocal
cd /d "%~dp0"
:loop
echo [%date% %time%] starting artisan serve on 127.0.0.1:8080 >> serve.log
php artisan serve --host=127.0.0.1 --port=8080 >> serve.log 2>> serve.err
echo [%date% %time%] artisan serve exited with code %errorlevel% - restarting in 2s >> serve.log
timeout /t 2 /nobreak > nul
goto loop
