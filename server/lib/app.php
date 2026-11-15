<?php
declare(strict_types=1);

// Cahier de lecture : configuration, réglages du panneau d'administration et accès.
//
// Deux sources de réglages :
//  - config.local.php, écrit par la personne qui installe l'application, jamais versionné
//    ni servi : dossier des données, intégration à un site hôte, liens de la page. Il peut
//    aussi fixer tout ou partie des moteurs ; le panneau les affiche alors sans les modifier.
//  - settings.json, dans le dossier des données, écrit par le panneau d'administration :
//    moteurs et clés d'API, moteur proposé par défaut, code d'accès, mot de passe.
//
// Deux façons de protéger l'application :
//  - par défaut, un code d'accès partagé (donné aux élèves) et un mot de passe
//    d'administrateur, retenus dans une session propre à l'application ;
//  - « site hôte » : config.local.php confie ces questions à la session d'un site existant
//    (qui peut utiliser l'application, qui l'administre, jeton CSRF, page de connexion).
//
// Ce fichier est inclus en tête de index.php, api.php et admin.php, hors de toute fonction :
// config.local.php s'exécute ainsi dans la portée globale, comme le code d'un site hôte
// qu'il inclurait.

const LECTURE_ENGINE_TYPES = ['anthropic', 'openai', 'ollama'];

// Valeurs d'un moteur jamais configuré. Aucun n'est actif : la personne qui installe
// connecte ses propres moteurs. Pour l'API Claude, Sonnet est proposé, pas le plus cher.
const LECTURE_ENGINE_DEFAULTS = [
    'anthropic' => [
        'enabled' => false, 'label' => 'Claude', 'api_key' => '', 'model' => 'claude-sonnet-5-5',
    ],
    'openai' => [
        'enabled' => false, 'label' => 'API compatible OpenAI', 'base_url' => 'https://api.openai.com/v1',
        'api_key' => '', 'model' => '',
    ],
    'ollama' => [
        'enabled' => false, 'label' => 'Modèles locaux', 'base_url' => 'http://localhost:11434',
        'api_key' => '', 'default_model' => '', 'timeout' => 270,
    ],
];

const LECTURE_CLAUDE_MODELS = ['claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-opus-5-5'];

$lecture_config_file = dirname(__DIR__) . '/config.local.php';
$GLOBALS['LECTURE_CONFIG'] = is_readable($lecture_config_file) ? (require $lecture_config_file) : [];
if (!is_array($GLOBALS['LECTURE_CONFIG'])) {
    $GLOBALS['LECTURE_CONFIG'] = [];
}
unset($lecture_config_file);

function lecture_config(): array
{
    return $GLOBALS['LECTURE_CONFIG'];
}

/* ---------- Réglages enregistrés (settings.json) ---------- */

function lecture_data_dir(): string
{
    $dir = lecture_config()['data_dir'] ?? null;
    return rtrim(is_string($dir) && $dir !== '' ? $dir : dirname(__DIR__) . '/data', '/');
}

function lecture_settings(bool $reload = false): array
{
    static $settings = null;
    if ($settings === null || $reload) {
        $raw = @file_get_contents(lecture_data_dir() . '/settings.json');
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $settings = is_array($data) ? $data : [];
    }
    return $settings;
}

/** Écrit settings.json d'un seul coup (fichier temporaire puis renommage). */
function lecture_settings_save(array $settings): void
{
    $dir = lecture_data_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
        throw new RuntimeException("Impossible de créer le dossier des données : $dir");
    }
    $file = "$dir/settings.json";
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException("Impossible d'écrire dans le dossier des données : $dir");
    }
    @chmod($tmp, 0660);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException("Impossible de remplacer $file");
    }
    lecture_settings(true);
}

/* ---------- Moteurs ---------- */

/**
 * Réglages d'un moteur : valeurs par défaut, puis settings.json, puis config.local.php, qui
 * l'emporte. La clé « locked » liste les champs fixés par config.local.php.
 */
function lecture_engine_config(string $type): array
{
    $saved = lecture_settings()['engines'][$type] ?? [];
    $fixed = lecture_config()['engines'][$type] ?? [];
    $saved = is_array($saved) ? $saved : [];
    $fixed = is_array($fixed) ? $fixed : [];
    $cfg = array_merge(LECTURE_ENGINE_DEFAULTS[$type], array_intersect_key($saved, LECTURE_ENGINE_DEFAULTS[$type]), $fixed);
    $cfg['locked'] = array_keys($fixed);
    return $cfg;
}

/** Un moteur est utilisable s'il est activé et que ses champs indispensables sont remplis. */
function lecture_engine_ready(string $type, ?array $cfg = null): bool
{
    $cfg ??= lecture_engine_config($type);
    if (empty($cfg['enabled'])) {
        return false;
    }
    return match ($type) {
        'anthropic' => trim((string)$cfg['api_key']) !== '' && trim((string)$cfg['model']) !== '',
        'openai'    => trim((string)$cfg['base_url']) !== '' && trim((string)$cfg['model']) !== '',
        'ollama'    => trim((string)$cfg['base_url']) !== '',
        default     => false,
    };
}

function lecture_default_engine(): string
{
    $id = lecture_config()['default_engine'] ?? lecture_settings()['default_engine'] ?? '';
    if (is_string($id) && in_array($id, LECTURE_ENGINE_TYPES, true) && lecture_engine_ready($id)) {
        return $id;
    }
    foreach (LECTURE_ENGINE_TYPES as $type) {
        if (lecture_engine_ready($type)) {
            return $type;
        }
    }
    return '';
}

/* ---------- Accès ---------- */

/** Intégration à un site hôte (config.local.php), ou null. */
function lecture_host(): ?array
{
    $host = lecture_config()['host'] ?? null;
    return is_array($host) && is_callable($host['can_use'] ?? null) ? $host : null;
}

function lecture_is_https(): bool
{
    return ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/** Chemin de l'application dans le site (« /lecture », ou « » à la racine). */
function lecture_base_path(): string
{
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    return rtrim($dir, '/');
}

function lecture_session_start(): void
{
    if (lecture_host() !== null || session_status() === PHP_SESSION_ACTIVE) {
        return;   // le site hôte a ouvert sa propre session
    }
    ini_set('session.use_strict_mode', '1');
    session_name('cahier_lecture');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => lecture_base_path() . '/',
        'secure'   => lecture_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Empreinte d'un hachage de mot de passe, gardée en session : changer le code d'accès ou le
 * mot de passe ferme les sessions ouvertes avec l'ancien.
 */
function lecture_fingerprint(string $hash): string
{
    return substr(hash('sha256', $hash), 0, 24);
}

function lecture_is_admin(): bool
{
    if (($host = lecture_host()) !== null) {
        return is_callable($host['is_admin'] ?? null) && (bool)($host['is_admin'])();
    }
    $hash = (string)(lecture_settings()['admin_password_hash'] ?? '');
    return $hash !== '' && hash_equals(lecture_fingerprint($hash), (string)($_SESSION['lecture_admin'] ?? ''));
}

function lecture_can_use(): bool
{
    if (($host = lecture_host()) !== null) {
        return (bool)($host['can_use'])();
    }
    if (lecture_is_admin()) {
        return true;
    }
    $hash = (string)(lecture_settings()['access_code_hash'] ?? '');
    return $hash !== '' && hash_equals(lecture_fingerprint($hash), (string)($_SESSION['lecture_access'] ?? ''));
}

function lecture_csrf_token(): string
{
    if (($host = lecture_host()) !== null) {
        return is_callable($host['csrf_token'] ?? null) ? (string)($host['csrf_token'])() : '';
    }
    if (!is_string($_SESSION['lecture_csrf'] ?? null) || strlen($_SESSION['lecture_csrf']) < 32) {
        $_SESSION['lecture_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['lecture_csrf'];
}

function lecture_csrf_valid(mixed $token): bool
{
    if (!is_string($token) || $token === '') {
        return false;
    }
    $host = lecture_host();
    if ($host !== null && is_callable($host['csrf_valid'] ?? null)) {
        return (bool)($host['csrf_valid'])($token);
    }
    $expected = lecture_csrf_token();
    return $expected !== '' && hash_equals($expected, $token);
}

/** Adresse de connexion, qui ramène ensuite à $back. */
function lecture_login_url(string $back): string
{
    $host = lecture_host();
    if ($host !== null && is_callable($host['login_url'] ?? null)) {
        return (string)($host['login_url'])($back);
    }
    return lecture_base_path() . '/';
}

/** Adresse complète de la page demandée, pour revenir après la connexion. */
function lecture_current_url(): string
{
    $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    return (lecture_is_https() ? 'https://' : 'http://') . $host . (str_starts_with($uri, '/') ? $uri : '/');
}

/* ---------- Essais de code ou de mot de passe ---------- */

const LECTURE_ATTEMPTS_MAX = 10;
const LECTURE_ATTEMPTS_WINDOW = 900;   // secondes

/** Vrai si cette adresse IP a encore droit à un essai. */
function lecture_attempts_allowed(): bool
{
    $list = lecture_attempts_read();
    $entry = $list[lecture_attempts_key()] ?? null;
    return !is_array($entry) || $entry['n'] < LECTURE_ATTEMPTS_MAX || time() - $entry['t'] > LECTURE_ATTEMPTS_WINDOW;
}

function lecture_attempts_record(bool $success): void
{
    $file = lecture_data_dir() . '/attempts.json';
    $list = lecture_attempts_read();
    $now = time();
    $list = array_filter($list, fn($e) => is_array($e) && $now - ($e['t'] ?? 0) <= LECTURE_ATTEMPTS_WINDOW);
    $key = lecture_attempts_key();
    if ($success) {
        unset($list[$key]);
    } else {
        $entry = $list[$key] ?? ['n' => 0, 't' => $now];
        $list[$key] = ['n' => $entry['n'] + 1, 't' => $entry['t']];
        usleep(400_000);
    }
    if (is_dir(lecture_data_dir())) {
        @file_put_contents($file, json_encode($list), LOCK_EX);
    }
}

function lecture_attempts_read(): array
{
    $raw = @file_get_contents(lecture_data_dir() . '/attempts.json');
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

function lecture_attempts_key(): string
{
    return substr(hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 16);
}

/* ---------- Pages simples (code d'accès, administration) ---------- */

function lecture_h(mixed $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function lecture_no_cache_headers(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
}

/** En-tête et styles communs aux pages du code d'accès et du panneau d'administration. */
function lecture_page_open(string $title): void
{
    lecture_no_cache_headers();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">',
        '<meta name="viewport" content="width=device-width,initial-scale=1">',
        '<title>', lecture_h($title), '</title><style>', LECTURE_PAGE_CSS, '</style></head><body><main class="box">';
}

function lecture_page_close(): void
{
    echo '</main></body></html>';
}

const LECTURE_PAGE_CSS = <<<'CSS'
:root{--paper:#F3F4F9;--sheet:#fff;--ink:#1A1F3D;--soft:#5A6082;--encre:#2D3AA8;--on:#fff;--rule:#D7DAEC;--wash:#E7E9F8;--rouge:#C42338;--vert:#1C7A51;color-scheme:light}
@media (prefers-color-scheme:dark){:root{--paper:#0E1023;--sheet:#161931;--ink:#E3E5F3;--soft:#A1A6C7;--encre:#9EA8FF;--on:#0E1023;--rule:#2C3056;--wash:#20244A;--rouge:#FF7387;--vert:#5CD49C;color-scheme:dark}}
*{box-sizing:border-box}
body{margin:0;background:var(--paper);color:var(--ink);font:16px/1.5 "Segoe UI",system-ui,-apple-system,sans-serif;padding:24px 16px 56px}
.box{max-width:760px;margin:0 auto;background:var(--sheet);border-radius:14px;padding:24px 28px;box-shadow:0 10px 28px -14px rgba(26,31,61,.25)}
h1{font:600 1.6rem/1.2 Georgia,serif;margin:0 0 6px}
h1 em{color:var(--encre);font-weight:500}
h2{font-size:1.05rem;margin:28px 0 10px;padding-top:18px;border-top:1px solid var(--rule)}
p{margin:8px 0}
.soft{color:var(--soft);font-size:.92rem}
label{display:block;font-weight:600;font-size:.9rem;margin:12px 0 4px}
label.inline{display:flex;align-items:center;gap:8px;font-weight:600}
input[type=text],input[type=password],input[type=url],input[type=number],select{width:100%;height:42px;padding:0 10px;border:1px solid var(--rule);border-radius:10px;background:var(--sheet);color:inherit;font:inherit}
input:disabled,select:disabled{opacity:.65}
fieldset{border:1px solid var(--rule);border-radius:12px;padding:6px 16px 16px;margin:14px 0}
legend{font-weight:700;padding:0 6px}
.btn{display:inline-block;margin-top:16px;padding:11px 18px;border:0;border-radius:10px;background:var(--encre);color:var(--on);font:700 .95rem inherit;font-family:inherit;cursor:pointer;text-decoration:none}
.btn.ghost{background:transparent;color:var(--encre);border:1px solid var(--rule)}
.msg{padding:10px 14px;border-radius:10px;margin:12px 0;background:var(--wash)}
.msg.err{border:1px solid var(--rouge)}
.msg.ok{border:1px solid var(--vert)}
.row{display:flex;flex-wrap:wrap;gap:8px 16px;align-items:baseline;justify-content:space-between}
.ok{color:var(--vert)} .err{color:var(--rouge)}
a{color:var(--encre)}
code{font-size:.9em}
CSS;
