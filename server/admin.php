<?php
declare(strict_types=1);

// Cahier de lecture : panneau d'administration.
//
// Code d'accès des élèves, moteurs (clés d'API, adresses, modèles) et moteur proposé par
// défaut, enregistrés dans settings.json (dossier des données). Les champs que
// config.local.php fixe sont affichés sans pouvoir être modifiés ; les clés d'API ne sont
// jamais réaffichées.
//
// Qui administre : sans site hôte, la personne qui connaît le mot de passe fixé sur le
// serveur par « php tools/admin-password.php » ; avec un site hôte, celle que désigne la
// fonction is_admin de config.local.php.

require_once __DIR__ . '/lib/app.php';
require_once __DIR__ . '/lib/lecture.php';
require_once __DIR__ . '/lib/engines.php';

lecture_session_start();
$base = lecture_base_path();
$self = $base . '/admin.php';
$host = lecture_host();

if (!lecture_is_admin()) {
    if ($host !== null) {
        if (!lecture_can_use()) {
            header('Location: ' . lecture_login_url(lecture_current_url()));
            exit;
        }
        http_response_code(403);
        lecture_page_open('Administration');
        echo '<h1>Cahier <em>de lecture</em></h1><p>Le panneau d’administration est réservé à l’administrateur du site.</p>',
            '<p><a href="', lecture_h($base), '/">Ouvrir l’application</a></p>';
        lecture_page_close();
        exit;
    }
    lecture_admin_login($self, $base);
    exit;
}

/* ---------- Actions ---------- */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (!lecture_csrf_valid($_POST['csrf'] ?? null)) {
        lecture_flash('err', 'La page avait expiré : rien n’a été enregistré. Recommence.');
    } elseif ($action === 'logout' && $host === null) {
        unset($_SESSION['lecture_admin']);
        session_regenerate_id(true);
        header('Location: ' . $base . '/', true, 303);
        exit;
    } elseif ($action === 'save') {
        try {
            $notes = lecture_admin_save($_POST, $host === null);
            lecture_flash('ok', 'Réglages enregistrés.' . ($notes ? ' ' . implode(' ', $notes) : ''));
        } catch (InvalidArgumentException $e) {
            lecture_flash('err', $e->getMessage());
        } catch (RuntimeException $e) {
            lecture_flash('err', 'Enregistrement impossible : ' . $e->getMessage());
        }
    } elseif ($action === 'check') {
        $lines = [];
        foreach (LECTURE_ENGINE_TYPES as $type) {
            $cfg = lecture_engine_config($type);
            if (!lecture_engine_ready($type, $cfg)) {
                continue;
            }
            $r = lecture_engine_check($type, $cfg);
            $lines[] = ($r['ok'] ? '✓ ' : '✗ ') . ($cfg['label'] ?: $type) . ' : ' . $r['message'];
        }
        lecture_flash('ok', $lines ? implode("\n", $lines) : 'Aucun moteur n’est activé.');
    }
    header('Location: ' . $self, true, 303);
    exit;
}

/* ---------- Page ---------- */

$settings = lecture_settings();
$csrf = lecture_csrf_token();
$flash = $_SESSION['lecture_flash'] ?? null;
unset($_SESSION['lecture_flash']);

lecture_page_open('Administration · Cahier de lecture');
echo '<div class="row"><h1>Cahier <em>de lecture</em> · administration</h1>',
    '<p><a href="', lecture_h($base), '/">Ouvrir l’application</a></p></div>';

if (is_array($flash)) {
    echo '<p class="msg ', $flash[0] === 'ok' ? 'ok' : 'err', '" role="status" style="white-space:pre-line">', lecture_h($flash[1]), '</p>';
}
$dir = lecture_data_dir();
if (!(is_dir($dir) ? is_writable($dir) : is_writable(dirname($dir)))) {
    echo '<p class="msg err">Le dossier des données n’est pas accessible en écriture au serveur web : ',
        '<code>', lecture_h($dir), '</code>. Les réglages ne pourront pas être enregistrés.</p>';
}

echo '<form method="post" action="', lecture_h($self), '" autocomplete="off">',
    '<input type="hidden" name="csrf" value="', lecture_h($csrf), '">';

// Accès des élèves
echo '<h2>Accès des élèves</h2>';
if ($host !== null) {
    echo '<p class="soft">L’accès est confié au site hôte (réglage <code>host</code> de config.local.php).</p>';
} else {
    $has = ($settings['access_code_hash'] ?? '') !== '';
    echo '<p>', $has
        ? 'Un code d’accès est défini' . (isset($settings['access_code_set']) ? ' (depuis le ' . lecture_h($settings['access_code_set']) . ')' : '') . '. Le changer ferme les sessions ouvertes avec l’ancien.'
        : '<strong>Aucun code d’accès :</strong> personne ne peut encore utiliser l’application, à part l’administrateur.',
        '</p><label for="access_code">Nouveau code d’accès <span class="soft">(8 caractères au moins ; laisser vide pour garder l’actuel)</span></label>',
        '<input type="text" id="access_code" name="access_code" minlength="8" maxlength="100" spellcheck="false">';
}

// Moteurs
echo '<h2>Moteurs</h2><p class="soft">Chaque génération est faite par le moteur choisi dans la page, avec les clés enregistrées ici. ',
    'Les services payants facturent l’usage au compte qui détient la clé.</p>';

lecture_admin_engine('anthropic', 'API Claude (Anthropic)', [
    ['api_key', 'Clé d’API', 'key', 'Créée sur console.anthropic.com, rubrique API Keys.'],
    ['model', 'Modèle', 'claude', 'Sonnet 5.5 est un bon équilibre ; Haiku 5.5 coûte moins cher, Opus 5.5 écrit les textes les plus soignés.'],
]);
lecture_admin_engine('openai', 'API compatible OpenAI', [
    ['base_url', 'Adresse de l’API', 'url', 'Par exemple https://api.openai.com/v1, ou celle d’un autre service compatible (Mistral, OpenRouter…).'],
    ['api_key', 'Clé d’API', 'key', ''],
    ['model', 'Modèle', 'text', 'Identifiant exact du modèle chez ce service.'],
]);
lecture_admin_engine('ollama', 'Ollama (modèles installés sur un serveur)', [
    ['base_url', 'Adresse du serveur', 'url', 'http://localhost:11434 pour un Ollama sur la même machine. Un relais qui reprend l’API d’Ollama convient aussi.'],
    ['api_key', 'Clé', 'key', 'Facultative : seulement si le serveur ou le relais en demande une.'],
    ['default_model', 'Modèle proposé par défaut', 'text', 'Facultatif : nom d’un modèle installé (par exemple gemma3:12b). Vide : le premier de la liste.'],
    ['timeout', 'Délai maximal d’une génération (secondes)', 'number', 'Entre 30 et 270.'],
]);

// Moteur par défaut
$fixedDefault = isset(lecture_config()['default_engine']);
$current = lecture_config()['default_engine'] ?? $settings['default_engine'] ?? '';
echo '<label for="default_engine">Moteur proposé par défaut dans la page</label>',
    '<select id="default_engine" name="default_engine"', $fixedDefault ? ' disabled' : '', '>',
    '<option value="">Le premier moteur activé</option>';
foreach (LECTURE_ENGINE_TYPES as $type) {
    $label = lecture_engine_config($type)['label'] ?: $type;
    echo '<option value="', $type, '"', $current === $type ? ' selected' : '', '>', lecture_h($label), '</option>';
}
echo '</select>', $fixedDefault ? '<p class="soft">Fixé dans config.local.php.</p>' : '';

// Mot de passe de l'administrateur
if ($host === null) {
    echo '<h2>Mot de passe de l’administrateur</h2>',
        '<label for="admin_new">Nouveau mot de passe <span class="soft">(12 caractères au moins ; laisser vide pour garder l’actuel)</span></label>',
        '<input type="password" id="admin_new" name="admin_new" minlength="12" autocomplete="new-password">',
        '<label for="admin_current">Mot de passe actuel <span class="soft">(seulement pour changer le mot de passe)</span></label>',
        '<input type="password" id="admin_current" name="admin_current" autocomplete="current-password">';
}

echo '<p><button class="btn" type="submit" name="action" value="save">Enregistrer</button> ',
    '<button class="btn ghost" type="submit" name="action" value="check" formnovalidate>Vérifier les moteurs</button></p></form>';

if ($host === null) {
    echo '<form method="post" action="', lecture_h($self), '"><input type="hidden" name="csrf" value="', lecture_h($csrf), '">',
        '<button class="btn ghost" type="submit" name="action" value="logout">Se déconnecter</button></form>';
}
echo '<datalist id="claude-models">';
foreach (LECTURE_CLAUDE_MODELS as $m) {
    echo '<option value="', $m, '">', lecture_h(lecture_claude_label($m)), '</option>';
}
echo '</datalist>';
lecture_page_close();

/* ---------- Fonctions ---------- */

function lecture_flash(string $kind, string $message): void
{
    $_SESSION['lecture_flash'] = [$kind, $message];
}

/** Un moteur dans le formulaire : activation, nom affiché, puis ses champs. */
function lecture_admin_engine(string $type, string $title, array $fields): void
{
    $cfg = lecture_engine_config($type);
    $locked = array_flip($cfg['locked']);
    $dis = fn(string $k) => isset($locked[$k]) ? ' disabled' : '';
    $note = fn(string $k) => isset($locked[$k]) ? ' <span class="soft">(fixé dans config.local.php)</span>' : '';

    $state = lecture_engine_ready($type, $cfg) ? '<span class="ok">prêt</span>'
        : (!empty($cfg['enabled']) ? '<span class="err">activé mais incomplet</span>' : '<span class="soft">désactivé</span>');
    echo '<fieldset><legend>', lecture_h($title), ' · ', $state, '</legend>',
        '<label class="inline"><input type="checkbox" name="e[', $type, '][enabled]" value="1"',
        !empty($cfg['enabled']) ? ' checked' : '', $dis('enabled'), '> Proposer ce moteur', $note('enabled'), '</label>',
        '<label for="', $type, '-label">Nom affiché dans la page', $note('label'), '</label>',
        '<input type="text" id="', $type, '-label" name="e[', $type, '][label]" maxlength="60" value="', lecture_h($cfg['label']), '"', $dis('label'), '>';

    foreach ($fields as [$key, $label, $kind, $help]) {
        $id = "$type-$key";
        $name = "e[$type][$key]";
        echo '<label for="', $id, '">', lecture_h($label), $note($key), '</label>';
        if ($kind === 'key') {
            $saved = trim((string)$cfg[$key]);
            $hint = $saved === '' ? 'Aucune clé enregistrée' : 'Clé enregistrée, se termine par « ' . substr($saved, -4) . ' » ; laisser vide pour la garder';
            echo '<input type="password" id="', $id, '" name="', $name, '" autocomplete="off" placeholder="', lecture_h($hint), '"', $dis($key), '>';
            if ($saved !== '' && !isset($locked[$key])) {
                echo '<label class="inline soft"><input type="checkbox" name="e[', $type, '][', $key, '_erase]" value="1"> Effacer la clé enregistrée</label>';
            }
        } else {
            $attrs = match ($kind) {
                'url'    => 'type="url" maxlength="300" placeholder="https://…"',
                'number' => 'type="number" min="30" max="270" step="10"',
                'claude' => 'type="text" list="claude-models" maxlength="160" spellcheck="false"',
                default  => 'type="text" maxlength="160" spellcheck="false"',
            };
            echo '<input ', $attrs, ' id="', $id, '" name="', $name, '" value="', lecture_h($cfg[$key]), '"', $dis($key), '>';
        }
        if ($help !== '') {
            echo '<p class="soft">', lecture_h($help), '</p>';
        }
    }
    echo '</fieldset>';
}

/**
 * Enregistre le formulaire. Les champs fixés par config.local.php sont ignorés.
 *
 * @return list<string> remarques à afficher
 */
function lecture_admin_save(array $post, bool $ownAccess): array
{
    $settings = lecture_settings();
    $notes = [];
    $engines = is_array($settings['engines'] ?? null) ? $settings['engines'] : [];

    foreach (LECTURE_ENGINE_TYPES as $type) {
        $in = is_array($post['e'][$type] ?? null) ? $post['e'][$type] : [];
        $locked = array_flip(lecture_engine_config($type)['locked']);
        $cur = array_merge(LECTURE_ENGINE_DEFAULTS[$type], is_array($engines[$type] ?? null) ? $engines[$type] : []);
        $next = $cur;
        $next['enabled'] = !empty($in['enabled']);
        $next['label'] = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in['label'] ?? '')) ?? ''), 0, 60) ?: LECTURE_ENGINE_DEFAULTS[$type]['label'];
        foreach (LECTURE_ENGINE_DEFAULTS[$type] as $key => $default) {
            if (in_array($key, ['enabled', 'label'], true) || isset($locked[$key])) {
                continue;
            }
            $value = trim((string)($in[$key] ?? ''));
            if ($key === 'api_key') {
                if ($value !== '') {
                    if (!preg_match('/^[\x21-\x7E]{8,400}$/', $value)) {
                        throw new InvalidArgumentException('Clé d’API illisible : elle ne doit contenir ni espace ni accent.');
                    }
                    $next[$key] = $value;
                } elseif (!empty($in['api_key_erase'])) {
                    $next[$key] = '';
                }
            } elseif ($key === 'base_url') {
                if ($value !== '' && !preg_match('#^https?://[^\s"<>]{1,290}$#i', $value)) {
                    throw new InvalidArgumentException('Adresse invalide : elle doit commencer par http:// ou https://.');
                }
                $next[$key] = rtrim($value, '/');
            } elseif ($key === 'timeout') {
                $next[$key] = max(30, min(270, (int)$value ?: 270));
            } else {   // model, default_model
                if ($value !== '' && !preg_match('/^[\w.:\/@+-]{1,160}$/u', $value)) {
                    throw new InvalidArgumentException('Nom de modèle invalide : « ' . $value . ' ».');
                }
                $next[$key] = $value;
            }
        }
        foreach (array_keys($locked) as $key) {
            unset($next[$key]);   // la valeur de config.local.php s'applique
        }
        $engines[$type] = $next;
        if ($next['enabled'] && !lecture_engine_ready($type, array_merge($next, array_intersect_key(lecture_engine_config($type), $locked)))) {
            $notes[] = '« ' . $next['label'] . ' » est activé mais incomplet : il ne sera pas proposé.';
        }
    }
    $settings['engines'] = $engines;

    $default = (string)($post['default_engine'] ?? '');
    if (!isset(lecture_config()['default_engine'])) {
        $settings['default_engine'] = in_array($default, LECTURE_ENGINE_TYPES, true) ? $default : '';
    }

    if ($ownAccess) {
        $code = trim((string)($post['access_code'] ?? ''));
        if ($code !== '') {
            if (mb_strlen($code) < 8) {
                throw new InvalidArgumentException('Le code d’accès doit compter 8 caractères au moins.');
            }
            $settings['access_code_hash'] = password_hash($code, PASSWORD_DEFAULT);
            $settings['access_code_set'] = date('Y-m-d');
            $notes[] = 'Nouveau code d’accès en service.';
        }
        $new = (string)($post['admin_new'] ?? '');
        if ($new !== '') {
            if (!password_verify((string)($post['admin_current'] ?? ''), (string)($settings['admin_password_hash'] ?? ''))) {
                throw new InvalidArgumentException('Mot de passe actuel incorrect : rien n’a été enregistré.');
            }
            if (strlen($new) < 12) {
                throw new InvalidArgumentException('Le mot de passe doit compter 12 caractères au moins.');
            }
            $settings['admin_password_hash'] = password_hash($new, PASSWORD_DEFAULT);
            $_SESSION['lecture_admin'] = lecture_fingerprint($settings['admin_password_hash']);
            $notes[] = 'Mot de passe de l’administrateur changé.';
        }
    }
    lecture_settings_save($settings);
    return $notes;
}

/** Connexion de l'administrateur (sans site hôte). */
function lecture_admin_login(string $self, string $base): void
{
    $hash = (string)(lecture_settings()['admin_password_hash'] ?? '');
    if ($hash === '') {
        lecture_page_open('Administration · Cahier de lecture');
        echo '<h1>Cahier <em>de lecture</em> · administration</h1>',
            '<p>Aucun mot de passe d’administrateur n’est encore défini. Sur le serveur, dans le dossier de l’application, lancer :</p>',
            '<p><code>php tools/admin-password.php</code></p>',
            '<p class="soft">Lancer la commande avec le compte du serveur web (par exemple <code>sudo -u www-data php tools/admin-password.php</code>), pour qu’il puisse ensuite modifier les réglages.</p>';
        lecture_page_close();
        return;
    }
    $error = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!lecture_csrf_valid($_POST['csrf'] ?? null)) {
            $error = 'La page a expiré. Réessaie.';
        } elseif (!lecture_attempts_allowed()) {
            $error = 'Trop d’essais. Réessaie dans quelques minutes.';
        } elseif (password_verify((string)($_POST['password'] ?? ''), $hash)) {
            lecture_attempts_record(true);
            session_regenerate_id(true);
            $_SESSION['lecture_admin'] = lecture_fingerprint($hash);
            header('Location: ' . $self, true, 303);
            exit;
        } else {
            lecture_attempts_record(false);
            $error = 'Mot de passe incorrect.';
        }
    }
    lecture_page_open('Administration · Cahier de lecture');
    echo '<h1>Cahier <em>de lecture</em> · administration</h1>';
    if ($error !== '') {
        echo '<p class="msg err" role="alert">', lecture_h($error), '</p>';
    }
    echo '<form method="post" action="', lecture_h($self), '">',
        '<input type="hidden" name="csrf" value="', lecture_h(lecture_csrf_token()), '">',
        '<label for="password">Mot de passe de l’administrateur</label>',
        '<input type="password" id="password" name="password" autocomplete="current-password" required autofocus>',
        '<button class="btn" type="submit">Se connecter</button></form>',
        '<p class="soft" style="margin-top:24px"><a href="', lecture_h($base), '/">Retour à l’application</a></p>';
    lecture_page_close();
}
