<?php

declare(strict_types=1);

function env_value(string $key, mixed $default = null): mixed
{
    static $env = null;

    if ($env === null) {
        $env = [];
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';

        if (is_readable($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            foreach ($lines as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }

                [$name, $value] = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);

                if (
                    (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))
                ) {
                    $value = substr($value, 1, -1);
                }

                $env[$name] = $value;
            }
        }
    }

    return $_ENV[$key] ?? $_SERVER[$key] ?? $env[$key] ?? $default;
}

return [
    'app' => [
        'name' => (string) env_value('APP_NAME', 'Salvadindin'),
        'env' => (string) env_value('APP_ENV', 'production'),
        'debug' => filter_var(env_value('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN),
        'url' => rtrim((string) env_value('APP_URL', 'http://localhost:8000'), '/'),
    ],
    'database' => [
        'host' => (string) env_value('DB_HOST', '127.0.0.1'),
        'port' => (string) env_value('DB_PORT', '5432'),
        'database' => (string) env_value('DB_DATABASE', 'salvadindin'),
        'username' => (string) env_value('DB_USERNAME', 'postgres'),
        'password' => (string) env_value('DB_PASSWORD', ''),
    ],
];
