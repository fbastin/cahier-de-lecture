<?php
declare(strict_types=1);

// Fixe le mot de passe de l'administrateur de Cahier de lecture (mode par défaut, sans site
// hôte). À lancer sur le serveur, dans le dossier de l'application, avec le compte du
// serveur web, pour qu'il puisse ensuite modifier les réglages :
//
//   sudo -u www-data php tools/admin-password.php
//
// Le mot de passe n'est jamais fixé depuis le web : une installation neuve ne peut pas être
// prise par la première personne qui en trouve l'adresse.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$_SERVER['SCRIPT_NAME'] ??= '/index.php';
require dirname(__DIR__) . '/lib/app.php';

function lecture_ask(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $tty = function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $line = rtrim((string)fgets(STDIN), "\r\n");
    if ($tty) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }
    return $line;
}

if (lecture_host() !== null) {
    fwrite(STDERR, "config.local.php confie l'administration au site hôte (réglage host) : ce mot de passe ne servirait pas.\n");
    exit(1);
}
$password = lecture_ask('Nouveau mot de passe (12 caractères au moins) : ');
if (strlen($password) < 12) {
    fwrite(STDERR, "Trop court : rien n'a été enregistré.\n");
    exit(1);
}
if (function_exists('posix_isatty') && posix_isatty(STDIN) && lecture_ask('Le même, encore une fois : ') !== $password) {
    fwrite(STDERR, "Les deux saisies diffèrent : rien n'a été enregistré.\n");
    exit(1);
}
try {
    $settings = lecture_settings();
    $settings['admin_password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    lecture_settings_save($settings);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo 'Mot de passe enregistré dans ', lecture_data_dir(), "/settings.json.\n",
    "Le panneau d'administration est la page admin.php de l'application.\n";
