<?php
declare(strict_types=1);

// Cahier de lecture : liste des moteurs, génération des textes et correction des réponses
// rédigées, pour la page servie par index.php.
//
//   GET  api.php?action=engines   moteurs connectés par l'administrateur
//   POST api.php?action=generate  {settings, engine}          → {exercise}
//   POST api.php?action=grade     {exercise, items, engine}   → {results}
//
// Réservé à qui a accès à l'application (code d'accès, ou session du site hôte) ; les POST
// portent en plus le jeton CSRF de la session (en-tête X-CSRF-Token, placé dans la page par
// index.php). Les clés d'API ne quittent jamais le serveur.

require_once __DIR__ . '/lib/app.php';
require_once __DIR__ . '/lib/lecture.php';
require_once __DIR__ . '/lib/engines.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

function lecture_reply(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function lecture_fail(string $code, string $message, int $status): never
{
    lecture_reply(['error' => ['code' => $code, 'message' => $message]], $status);
}

function lecture_json_body(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        lecture_fail('bad_request', 'Méthode attendue : POST.', 405);
    }
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        lecture_fail('bad_request', 'Corps JSON attendu.', 415);
    }
    $raw = file_get_contents('php://input', false, null, 0, 200_000);
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        lecture_fail('bad_request', 'Corps JSON illisible.', 400);
    }
    return $data;
}

lecture_session_start();
if (!lecture_can_use()) {
    lecture_fail('session_expired', 'Connexion requise.', 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !lecture_csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    lecture_fail('stale_page', 'Jeton de sécurité périmé : recharger la page.', 403);
}

// Une génération dure jusqu'à quelques minutes : libérer la session tout de suite, sinon les
// autres pages de la même session attendent la fin de la génération.
session_write_close();
set_time_limit(330);

try {
    switch ($_GET['action'] ?? '') {
        case 'engines':
            lecture_reply(['engines' => lecture_engines(), 'default' => lecture_default_engine()]);

        case 'generate':
            $in = lecture_json_body();
            $settings = lecture_settings_input($in['settings'] ?? null);
            [$type, $model, $cfg] = lecture_engine_choice($in['engine'] ?? null);
            lecture_reply(['exercise' => lecture_generate($type, $model, $cfg, $settings)]);

        case 'grade':
            $in = lecture_json_body();
            $grade = lecture_grade_input($in['exercise'] ?? null, $in['items'] ?? null);
            [$type, $model, $cfg] = lecture_engine_choice($in['engine'] ?? null);
            lecture_reply(['results' => lecture_grade($type, $model, $cfg, $grade)]);

        default:
            lecture_fail('bad_request', 'Action inconnue.', 400);
    }
} catch (LectureError $e) {
    if ($e->status >= 500) {
        error_log('[lecture] ' . $e->codeName . ' : ' . $e->getMessage());
    }
    lecture_fail($e->codeName, $e->getMessage(), $e->status);
}
