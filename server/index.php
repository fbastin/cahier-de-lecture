<?php
declare(strict_types=1);

// Cahier de lecture : sert la page de l'application (app/index.html, compilée par
// scripts/build.sh), après le code d'accès ou la connexion au site hôte.

require_once __DIR__ . '/lib/app.php';

lecture_session_start();
$base = lecture_base_path();

if (!lecture_can_use()) {
    if (lecture_host() !== null) {
        header('Location: ' . lecture_login_url(lecture_current_url()));
        exit;
    }
    lecture_access_form($base);
    exit;
}

$page = __DIR__ . '/app/index.html';
if (!is_readable($page)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "La page de l'application n'est pas compilée : lancer scripts/build.sh dans le dépôt.\n";
    exit;
}

$cfg = lecture_config();
$admin = lecture_is_admin();
$back = is_array($cfg['back_link'] ?? null) ? $cfg['back_link'] : [];
$values = [
    '__LECTURE_API__'        => $base . '/api.php',
    '__LECTURE_CSRF__'       => lecture_csrf_token(),
    // Lien vers une version claude.ai de la page, si l'administrateur en a publié une :
    // elle utilise le compte claude.ai de qui l'ouvre ; montré à l'administrateur seul.
    '__LECTURE_CLAUDE_URL__' => $admin && is_string($cfg['claude_ai_url'] ?? null) ? $cfg['claude_ai_url'] : '',
    '__LECTURE_ADMIN__'      => $admin ? $base . '/admin.php' : '',
    '__LECTURE_BACK_URL__'   => (string)($back['url'] ?? ''),
    '__LECTURE_BACK_LABEL__' => (string)($back['label'] ?? ''),
    '__LECTURE_LOGIN__'      => lecture_login_url(lecture_current_url()),
];
session_write_close();

lecture_no_cache_headers();
header('Content-Type: text/html; charset=utf-8');
echo strtr((string)file_get_contents($page), array_map('lecture_h', $values));

/** Formulaire du code d'accès (mode par défaut, sans site hôte). */
function lecture_access_form(string $base): void
{
    $settings = lecture_settings();
    $error = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $code = trim((string)($_POST['code'] ?? ''));
        $hash = (string)($settings['access_code_hash'] ?? '');
        if (!lecture_csrf_valid($_POST['csrf'] ?? null)) {
            $error = 'La page a expiré. Réessaie.';
        } elseif (!lecture_attempts_allowed()) {
            $error = 'Trop d’essais. Réessaie dans quelques minutes.';
        } elseif ($hash !== '' && $code !== '' && password_verify($code, $hash)) {
            lecture_attempts_record(true);
            session_regenerate_id(true);
            $_SESSION['lecture_access'] = lecture_fingerprint($hash);
            header('Location: ' . $base . '/', true, 303);
            return;
        } else {
            lecture_attempts_record(false);
            $error = 'Ce code n’est pas le bon.';
        }
    }

    lecture_page_open('Cahier de lecture');
    echo '<h1>Cahier <em>de lecture</em></h1>';
    if (($settings['access_code_hash'] ?? '') === '') {
        echo '<p>Cette installation n’a pas encore de code d’accès. L’administrateur le choisit dans le ',
            '<a href="', lecture_h($base), '/admin.php">panneau d’administration</a>.</p>';
        lecture_page_close();
        return;
    }
    echo '<p class="soft">Entre le code d’accès donné par ton enseignant.</p>';
    if ($error !== '') {
        echo '<p class="msg err" role="alert">', lecture_h($error), '</p>';
    }
    echo '<form method="post" action="', lecture_h($base), '/">',
        '<input type="hidden" name="csrf" value="', lecture_h(lecture_csrf_token()), '">',
        '<label for="code">Code d’accès</label>',
        '<input type="password" id="code" name="code" autocomplete="off" autocapitalize="off" spellcheck="false" required autofocus>',
        '<button class="btn" type="submit">Entrer</button></form>',
        '<p class="soft" style="margin-top:24px"><a href="', lecture_h($base), '/admin.php">Administration</a></p>';
    lecture_page_close();
}
