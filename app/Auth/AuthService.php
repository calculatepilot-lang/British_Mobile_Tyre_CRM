<?php

declare(strict_types=1);

namespace BMT\Auth;

use BMT\Database;
use RuntimeException;

final class AuthService
{
    public static function boot(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            session_set_cookie_params([
                'httponly' => true,
                'secure' => $secure,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public function attempt(string $email, string $password): bool
    {
        $stmt = Database::connection()->prepare('SELECT id, name, email, password_hash, role FROM users WHERE email = :email AND is_active = 1 LIMIT 1');
        $stmt->execute(['email' => strtolower(trim($email))]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];
        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public function requireUser(): array
    {
        $user = $this->user();
        if ($user === null) {
            throw new RuntimeException('Authentication required.');
        }
        return $user;
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        return is_string($token) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}
