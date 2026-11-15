<?php
declare(strict_types=1);

final class ApiError extends RuntimeException {
    public function __construct(public int $status, string $message) { parent::__construct($message); }
}
function fail(int $status, string $message): never { throw new ApiError($status, $message); }
function respond(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}
function configuration(): array {
    static $config;
    if ($config !== null) return $config;
    $path = getenv('AFSPRAAKBOEKEN_CONFIG') ?: dirname(__DIR__, 2) . '/afspraakboeken-private/config.php';
    if (!is_file($path)) fail(503, 'De omgeving wordt klaargezet. Probeer het later opnieuw.');
    $config = require $path;
    if (!is_array($config) || strlen($config['app_key'] ?? '') < 32 || empty($config['db']['name'])) {
        fail(503, 'De omgeving wordt klaargezet. Probeer het later opnieuw.');
    }
    $url = parse_url($config['app_url'] ?? '');
    if (!isset($url['scheme'], $url['host']) || isset($url['user'], $url['pass'])) fail(503, 'Ongeldige serverconfiguratie.');
    if (empty($config['secure_cookies']) && !in_array($url['host'], ['localhost', '127.0.0.1'], true)) fail(503, 'HTTPS moet worden geactiveerd.');
    return $config;
}
function connection(): PDO {
    static $db;
    if ($db) return $db;
    $d = configuration()['db'];
    $db = new PDO('mysql:host=' . $d['host'] . ';port=' . (int)($d['port'] ?? 3306) . ';dbname=' . $d['name'] . ';charset=utf8mb4', $d['user'], $d['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $db->exec("SET time_zone = '+00:00'");
    return $db;
}
function query(string $sql, array $args = []): PDOStatement {
    $statement = connection()->prepare($sql); $statement->execute($args); return $statement;
}
function row(string $sql, array $args = []): ?array { return query($sql, $args)->fetch() ?: null; }
function uid(): string { return bin2hex(random_bytes(16)); }
function encoded(array $data): string { return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
function decoded(string $data): array { return json_decode($data, true, 32, JSON_THROW_ON_ERROR); }
function body(): array {
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) fail(415, 'Gebruik een JSON-aanvraag.');
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) fail(413, 'De aanvraag is te groot.');
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if ($raw === false || strlen($raw) > 65536) fail(413, 'De aanvraag is te groot.');
    try { $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); } catch (JsonException $e) { fail(400, 'Ongeldige aanvraag.'); }
    if (!is_array($data) || array_is_list($data) && $data !== []) fail(400, 'Ongeldige aanvraag.');
    return $data;
}
function text(array $data, string $key, int $max, bool $required = false): string {
    $value = $data[$key] ?? '';
    if (!is_string($value) || strlen($value) > $max * 4 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) fail(422, 'Controleer het veld ' . $key . '.');
    $value = trim($value);
    if (($required && $value === '') || preg_match_all('/./us', $value) > $max) fail(422, 'Controleer het veld ' . $key . '.');
    return $value;
}
function email(array $data, string $key = 'email'): string {
    $value = strtolower(text($data, $key, 190, true));
    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) fail(422, 'Vul een geldig e-mailadres in.');
    return $value;
}
function integer(array $data, string $key, int $min, int $max, ?int $default = null): int {
    $value = $data[$key] ?? $default;
    if (is_bool($value) || filter_var($value, FILTER_VALIDATE_INT) === false || $value < $min || $value > $max) fail(422, 'Controleer het veld ' . $key . '.');
    return (int)$value;
}
function flag(array $data, string $key, bool $default = false): bool {
    if (!array_key_exists($key, $data)) return $default;
    if (!is_bool($data[$key])) fail(422, 'Controleer het veld ' . $key . '.');
    return $data[$key];
}
function identifier(mixed $value): string {
    if (!is_string($value) || !preg_match('/^[a-f0-9]{32}$/D', $value)) fail(404, 'Niet gevonden.');
    return $value;
}
function password(array $data, string $key = 'password'): string {
    $value = $data[$key] ?? null;
    if (!is_string($value) || strlen($value) < 12 || strlen($value) > 72 || str_contains($value, "\0")) fail(422, 'Gebruik een wachtwoord van 12 tot 72 bytes. Een lange wachtzin werkt goed.');
    return $value;
}
function hash_password(string $value): string { return password_hash($value, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT); }
function origin_guard(bool $contact = false): void {
    $config = configuration();
    $allowed = [rtrim($config['app_url'], '/')];
    if ($contact) $allowed = array_merge($allowed, $config['contact_origins'] ?? []);
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!in_array($origin, $allowed, true)) fail(403, 'Deze aanvraag is niet toegestaan.');
    if ($contact && $origin !== $allowed[0]) {
        header('Access-Control-Allow-Origin: ' . $origin); header('Vary: Origin');
        header('Access-Control-Allow-Methods: POST, OPTIONS'); header('Access-Control-Allow-Headers: Content-Type');
    }
}
function limit_rate(string $kind, string $value, int $maximum, int $seconds): void {
    $slot = (int)floor(time() / $seconds);
    $bucket = hash_hmac('sha256', $kind . '|' . $value . '|' . $slot, configuration()['app_key']);
    query('INSERT INTO ab_rate_limits(bucket,hits,expires_at) VALUES (?,1,?) ON DUPLICATE KEY UPDATE hits=hits+1', [$bucket, gmdate('Y-m-d H:i:s', ($slot + 2) * $seconds)]);
    if ((int)row('SELECT hits FROM ab_rate_limits WHERE bucket=?', [$bucket])['hits'] > $maximum) {
        header('Retry-After: ' . $seconds); fail(429, 'Even wachten. Er zijn te veel pogingen; probeer het later opnieuw.');
    }
}
function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? 'unknown'; }
function cookie_name(): string { return configuration()['secure_cookies'] ? '__Host-ab_session' : 'ab_dev_session'; }
function session_cookie(string $token, int $expiry): void {
    setcookie(cookie_name(), $token, ['expires' => $expiry, 'path' => '/', 'secure' => configuration()['secure_cookies'], 'httponly' => true, 'samesite' => 'Lax']);
}
function identity(bool $required = true): ?array {
    $token = $_COOKIE[cookie_name()] ?? '';
    $user = null;
    if (preg_match('/^[a-f0-9]{64}$/D', $token)) {
        $user = row('SELECT u.id,u.tenant_id,u.email,u.name,u.role,u.active,s.csrf,s.token_hash FROM ab_sessions s JOIN ab_users u ON u.id=s.user_id LEFT JOIN ab_tenants t ON t.id=u.tenant_id WHERE s.token_hash=? AND s.expires_at>UTC_TIMESTAMP() AND s.last_seen>DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE) AND u.active=1 AND (u.role=\'superadmin\' OR (u.role=\'admin\' AND t.active=1))', [hash('sha256', $token)]);
        if ($user) query('UPDATE ab_sessions SET last_seen=UTC_TIMESTAMP() WHERE token_hash=?', [$user['token_hash']]);
    }
    if (!$user && $required) { session_cookie('', time()-3600); fail(401, 'Meld je aan om verder te gaan.'); }
    return $user;
}
function csrf(array $user): void {
    if (!hash_equals($user['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) fail(403, 'Je sessie is gewijzigd. Vernieuw de pagina en probeer opnieuw.');
}
function superadmin(array $user): void { if ($user['role'] !== 'superadmin') fail(403, 'Geen toegang.'); }
function user_view(array $user): array {
    return ['id'=>$user['id'], 'name'=>$user['name'], 'email'=>$user['email'], 'role'=>$user['role'], 'tenant_id'=>$user['tenant_id']];
}
function tenant_access(array $user, mixed $id, bool $lock = false): array {
    $id = identifier($id);
    if ($user['role'] !== 'superadmin' && $user['tenant_id'] !== $id) fail(404, 'Niet gevonden.');
    $tenant = row('SELECT * FROM ab_tenants WHERE id=?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
    if (!$tenant) fail(404, 'Niet gevonden.');
    return $tenant;
}
function transaction(callable $operation): mixed {
    $db = connection(); $db->beginTransaction();
    try { $result = $operation(); $db->commit(); return $result; }
    catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
function audit(?string $actor, string $action, string $target): void { query('INSERT INTO ab_audit(actor_id,action,target_id) VALUES (?,?,?)', [$actor,$action,$target]); }
