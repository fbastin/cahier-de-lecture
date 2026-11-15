<?php
// Cahier de lecture : réglages de l'installation.
//
// À copier en config.local.php, qui n'est jamais versionné ni servi. Tout y est facultatif :
// sans ce fichier, l'application est protégée par un code d'accès et un mot de passe
// d'administrateur, et le panneau d'administration (admin.php) enregistre ses réglages dans
// le dossier data/.
//
// Pour un site hôte (voir plus bas), les require_once de son code de session se placent ici,
// avant le return : ce fichier est lu dans la portée globale.

return [
    // Dossier des données : réglages du panneau (clés d'API comprises) et essais de
    // connexion. De préférence hors de la racine du site ; le serveur web doit pouvoir y
    // écrire. Par défaut : data/, dans le dossier de l'application.
    // 'data_dir' => '/var/lib/cahier-de-lecture',

    // Lien de retour affiché en haut de la page.
    // 'back_link' => ['label' => '← Accueil', 'url' => '/'],

    // Adresse d'une version claude.ai de la page, si vous en avez publié une (elle utilise le
    // compte claude.ai de qui l'ouvre). Le lien n'est montré qu'à l'administrateur.
    // 'claude_ai_url' => 'https://claude.ai/artifact/…',

    // Moteurs fixés ici plutôt que dans le panneau, qui les affiche alors sans les modifier.
    // Types : 'anthropic' (api_key, model), 'openai' (base_url, api_key, model),
    // 'ollama' (base_url, api_key facultative, default_model, timeout).
    // 'engines' => [
    //     'ollama' => ['enabled' => true, 'base_url' => 'http://localhost:11434', 'default_model' => 'gemma3:12b'],
    // ],
    // 'default_engine' => 'ollama',

    // Site hôte : confier l'accès à la session d'un site existant, au lieu du code d'accès et
    // du mot de passe. can_use est obligatoire ; les autres fonctions sont facultatives (sans
    // is_admin, personne n'administre ; sans csrf_valid, le jeton de csrf_token est comparé).
    // 'host' => [
    //     'can_use'    => fn(): bool => !empty($_SESSION['user_id']),
    //     'is_admin'   => fn(): bool => ($_SESSION['role'] ?? '') === 'admin',
    //     'csrf_token' => fn(): string => (string)($_SESSION['csrf_token'] ?? ''),
    //     'csrf_valid' => fn(string $token): bool => hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token),
    //     'login_url'  => fn(string $back): string => '/login.php?next=' . urlencode($back),
    // ],
];
