<?php
/**
 * GenZMart API - Authentication Helper
 * 
 * Reusable helper class for session/token management, header parsing,
 * and user authentication verification.
 */

require_once __DIR__ . '/../database/Database.php';

class AuthHelper {
    /**
     * Get JSON input body or POST array
     *
     * @return array
     */
    public static function getRequestData(): array {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        if (str_contains(strtolower($contentType), 'application/json')) {
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true);
            if (is_array($data) && !empty($data)) {
                return $data;
            }
        }
        return is_array($_POST) ? $_POST : [];
    }

    /**
     * Extract Bearer token from Authorization header or HTTP_AUTHORIZATION
     *
     * @return string|null
     */
    public static function getTokenFromRequest(): ?string {
        $headers = [];
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        }

        $authHeader = $headers['Authorization'] 
            ?? $headers['authorization'] 
            ?? $_SERVER['HTTP_AUTHORIZATION'] 
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] 
            ?? null;

        if ($authHeader && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return trim($matches[1]);
        }

        // Fallback to query parameter or cookie if provided
        if (!empty($_GET['token'])) {
            return trim($_GET['token']);
        }

        return null;
    }

    /**
     * Generate a new secure session token and insert into user_sessions
     *
     * @param PDO $pdo
     * @param int|string $userId
     * @return string Plain token string sent to client
     */
    public static function createSession(PDO $pdo, $userId): string {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $expiresAt = date('Y-m-d H:i:s', time() + (30 * 86400)); // 30 days validity

        $stmt = $pdo->prepare("
            INSERT INTO user_sessions (user_id, token_hash, ip_address, user_agent, expires_at)
            VALUES (:user_id, :token_hash, :ip_address, :user_agent, :expires_at)
        ");

        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'expires_at' => $expiresAt
        ]);

        return $token;
    }

    /**
     * Validate session token and return authenticated user details
     *
     * @param PDO $pdo
     * @return array|null User details array or null if unauthorized
     */
    public static function getAuthenticatedUser(PDO $pdo): ?array {
        $token = self::getTokenFromRequest();
        if (!$token) {
            return null;
        }

        $tokenHash = hash('sha256', $token);

        $stmt = $pdo->prepare("
            SELECT 
                u.id, 
                u.first_name, 
                u.last_name, 
                CONCAT(u.first_name, ' ', u.last_name) AS name,
                u.email, 
                u.phone,
                u.avatar,
                u.status,
                u.created_at,
                r.name AS role
            FROM user_sessions s
            JOIN users u ON s.user_id = u.id
            JOIN roles r ON u.role_id = r.id
            WHERE s.token_hash = :token_hash 
              AND s.expires_at > NOW() 
              AND u.status = 'active'
            LIMIT 1
        ");

        $stmt->execute(['token_hash' => $tokenHash]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return null;
        }

        // Format role name to lowercase (customer, seller, admin)
        $user['role'] = strtolower($user['role']);
        $user['id'] = (int) $user['id'];

        return $user;
    }

    /**
     * Revoke/Destroy a session token
     *
     * @param PDO $pdo
     * @param string|null $token
     * @return bool
     */
    public static function destroySession(PDO $pdo, ?string $token): bool {
        if (!$token) {
            return false;
        }

        $tokenHash = hash('sha256', $token);
        $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE token_hash = :token_hash");
        return $stmt->execute(['token_hash' => $tokenHash]);
    }
}
