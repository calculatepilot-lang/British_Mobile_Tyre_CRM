<?php

declare(strict_types=1);

namespace BMT;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $connection = null;

    private static function env(string $key, string $default = ''): string
    {
        if (isset($_ENV[$key])) {
            return (string) $_ENV[$key];
        }

        if (isset($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }

        $value = getenv($key);

        return $value !== false ? (string) $value : $default;
    }

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            self::env('DB_HOST', 'localhost'),
            self::env('DB_PORT', '3306'),
            self::env('DB_DATABASE')
        );

        try {
            self::$connection = new PDO(
                $dsn,
                self::env('DB_USERNAME'),
                self::env('DB_PASSWORD'),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            throw new PDOException(
                'Database connection failed. Check environment configuration.',
                (int) $e->getCode(),
                $e
            );
        }

        return self::$connection;
    }

    /**
     * Instance-style accessor for modules that receive a Database instance
     * via constructor injection rather than calling the static method directly.
     */
    public function pdo(): PDO
    {
        return self::connection();
    }
}
