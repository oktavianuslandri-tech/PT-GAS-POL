<?php
declare(strict_types=1);

namespace Warehouse;

final class ApiException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

final class Http
{
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        try {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException(400, 'Request body must be valid JSON');
        }
        if (!is_array($body) || array_is_list($body)) {
            throw new ApiException(400, 'Request body must be a JSON object');
        }
        return $body;
    }

    public static function requireRole(array $roles): void
    {
        $token = self::bearerToken();
        foreach ($roles as $role) {
            $expected = getenv($role . '_TOKEN');
            if ($expected !== false && $expected !== '' && hash_equals($expected, $token)) {
                return;
            }
        }
        throw new ApiException(403, 'A valid token for an allowed role is required');
    }

    public static function requireInternalToken(): void
    {
        $expected = getenv('EVENT_CONSUMER_TOKEN');
        if ($expected === false || $expected === '' || !hash_equals($expected, self::bearerToken())) {
            throw new ApiException(403, 'A valid event consumer token is required');
        }
    }

    private static function bearerToken(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches) !== 1) {
            return '';
        }
        return trim($matches[1]);
    }
}
