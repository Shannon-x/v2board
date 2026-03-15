<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $configuredSecurePath = config('v2board.secure_path');

        $securePath = env('ADMIN_SECURE_PATH');
        if (empty($securePath)) {
            $securePath = $this->readAdminSecurePathFromEnvFile();
        }
        if (empty($securePath)) {
            $securePath = $configuredSecurePath;
        }
        if (empty($securePath)) {
            $securePath = config('v2board.frontend_admin_path', hash('crc32b', config('app.key')));
        }

        // Normalize to plain path segment to avoid route mismatches.
        $securePath = trim((string) $securePath, " \t\n\r\0\x0B/");

        if ($securePath === '') {
            $securePath = hash('crc32b', config('app.key'));
        }

        config(['v2board.secure_path' => $securePath]);
    }

    private function readAdminSecurePathFromEnvFile(): ?string
    {
        $envPath = base_path('.env');
        if (!is_file($envPath) || !is_readable($envPath)) {
            return null;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return null;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, 'ADMIN_SECURE_PATH=') !== 0) {
                continue;
            }

            $value = substr($line, strlen('ADMIN_SECURE_PATH='));
            return trim($value, " \t\n\r\0\x0B\"'");
        }

        return null;
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app['view']->addNamespace('theme', public_path() . '/theme');
    }
}
