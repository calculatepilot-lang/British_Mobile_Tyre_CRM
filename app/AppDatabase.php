<?php

declare(strict_types=1);

namespace App;

use PDO;

/** Compatibility adapter for newer CRM modules. */
final class Database
{
    public static function fromEnvironment(): PDO
    {
        return \BMT\Database::connection();
    }

    public static function connection(): PDO
    {
        return \BMT\Database::connection();
    }

    public function pdo(): PDO
    {
        return \BMT\Database::connection();
    }
}
