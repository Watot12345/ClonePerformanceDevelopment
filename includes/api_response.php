<?php
/**
 * Oxford Suites, Makati — Standardized API Response & Security Helper
 */

class ApiResponse
{
    /**
     * Send standard CORS headers
     */
    public static function corsHeaders(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = [
            'http://localhost',
            'http://127.0.0.1',
            'http://localhost:8000',
            'http://127.0.0.1:8000',
            'http://localhost:3000'
        ];

        if (in_array($origin, $allowedOrigins, true) || empty($origin)) {
            header("Access-Control-Allow-Origin: " . ($origin ?: '*'));
        } else {
            header("Access-Control-Allow-Origin: " . $origin);
        }

        header('Content-Type: application/json; charset=UTF-8');
        header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token');
        header('Access-Control-Allow-Credentials: true');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }

    /**
     * Send success JSON response
     */
    public static function success(mixed $data = null, string $message = 'Success', int $statusCode = 200): void
    {
        self::corsHeaders();
        http_response_code($statusCode);
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data'    => $data,
            'timestamp' => date('c')
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Send error JSON response
     */
    public static function error(string $message = 'An error occurred', int $statusCode = 400, mixed $errors = null): void
    {
        self::corsHeaders();
        http_response_code($statusCode);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
            'timestamp' => date('c')
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Safely parse request payload (combining JSON body, POST, and GET)
     */
    public static function getPayload(): array
    {
        $rawBody = file_get_contents('php://input');
        $jsonBody = !empty($rawBody) ? json_decode($rawBody, true) : [];
        return array_merge($_GET, $_POST, is_array($jsonBody) ? $jsonBody : []);
    }
}
