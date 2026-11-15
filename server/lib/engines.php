<?php
declare(strict_types=1);

// Les moteurs de Cahier de lecture, tous connectés par l'administrateur de l'installation :
//  - « anthropic » : l'API Claude, par le SDK officiel anthropic-ai/sdk (vendor/) ;
//  - « openai »    : toute API compatible OpenAI (/chat/completions) ;
//  - « ollama »    : un serveur Ollama, ou un relais qui en reprend l'API (/api/tags,
//                    /api/chat), comme celui d'Open WebUI (adresse se terminant par /ollama).
// Chaque appel renvoie la valeur JSON lue dans la réponse, ou lève une LectureError dont le
// code est repris par la page pour choisir son message.

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;

/** « claude-sonnet-5-5 » → « Claude Sonnet 5.5 ». */
function lecture_claude_label(string $model): string
{
    $parts = explode('-', preg_replace('/^claude-/', '', $model) ?? $model);
    $name = ucfirst(array_shift($parts) ?? '');
    return trim('Claude ' . $name . ' ' . implode('.', $parts));
}

function lecture_sdk_installed(): bool
{
    return is_readable(dirname(__DIR__) . '/vendor/autoload.php');
}

/**
 * Les moteurs prêts, pour la liste de la page. Un serveur Ollama est sondé (liste de ses
 * modèles, 4 s au plus) : éteint, il apparaît hors ligne au lieu d'échouer au moment de
 * générer.
 *
 * @return list<array{id: string, label: string, up: bool, models: list<array{id: string, name: string}>, default_model: string}>
 */
function lecture_engines(): array
{
    $out = [];
    foreach (LECTURE_ENGINE_TYPES as $type) {
        $cfg = lecture_engine_config($type);
        if (!lecture_engine_ready($type, $cfg)) {
            continue;
        }
        $label = trim((string)$cfg['label']) ?: LECTURE_ENGINE_DEFAULTS[$type]['label'];
        if ($type === 'ollama') {
            try {
                $models = lecture_ollama_models($cfg);
                $up = true;
            } catch (LectureError) {
                $models = [];
                $up = false;
            }
            $default = (string)$cfg['default_model'];
        } else {
            $model = trim((string)$cfg['model']);
            $models = [['id' => $model, 'name' => $type === 'anthropic' ? lecture_claude_label($model) : $model]];
            $up = $type !== 'anthropic' || lecture_sdk_installed();
            $default = $model;
        }
        $out[] = ['id' => $type, 'label' => $label, 'up' => $up, 'models' => $models, 'default_model' => $default];
    }
    return $out;
}

/**
 * Moteur et modèle demandés par la page, contrôlés contre la configuration. Pour l'API Claude
 * et l'API compatible OpenAI, le modèle est toujours celui que l'administrateur a choisi.
 *
 * @return array{0: string, 1: string, 2: array} type, modèle, réglages du moteur
 */
function lecture_engine_choice(mixed $in): array
{
    $type = is_array($in) ? ($in['id'] ?? '') : '';
    if (!is_string($type) || !in_array($type, LECTURE_ENGINE_TYPES, true)) {
        throw new LectureError('bad_request', 'Moteur inconnu.', 400);
    }
    $cfg = lecture_engine_config($type);
    if (!lecture_engine_ready($type, $cfg)) {
        throw new LectureError('not_configured', 'Ce moteur n’est pas configuré.', 503);
    }
    if ($type !== 'ollama') {
        return [$type, trim((string)$cfg['model']), $cfg];
    }
    $model = is_string($in['model'] ?? null) && $in['model'] !== '' ? $in['model'] : (string)$cfg['default_model'];
    if (!preg_match('/^[\w.:\/@+-]{1,160}$/u', $model)) {
        throw new LectureError('bad_request', 'Modèle invalide.', 400);
    }
    return [$type, $model, $cfg];
}

/** Génère un exercice ; la réponse a le format attendu par la page. */
function lecture_generate(string $type, string $model, array $cfg, array $settings): array
{
    $prompt = lecture_generation_prompt($settings, true);
    $schema = lecture_generation_schema();
    $data = lecture_from_schema(match ($type) {
        'anthropic' => lecture_claude_json($cfg, $model, $prompt, $schema, 'medium'),
        'openai'    => lecture_openai_json($cfg, $model, $prompt, $schema, 8192),
        default     => lecture_ollama_json($cfg, $model, $prompt, $schema, 8192),
    });
    if (is_array($data['paragraphs'] ?? null)) {
        [$data['paragraphs'], $dropped] = lecture_text_paragraphs($data['paragraphs']);
        if ($dropped > 0) {
            error_log("[lecture] $type $model : $dropped paragraphe(s) écarté(s), fragments du JSON rangés dans le texte");
        }
    }
    if (($data['paragraphs'] ?? []) === [] || !is_array($data['questions'] ?? null) || $data['questions'] === []) {
        throw new LectureError('invalid_json', 'Réponse sans texte ou sans questions.');
    }
    return lecture_shuffle_options($data);
}

/** Corrige les réponses rédigées ; renvoie la liste des résultats. */
function lecture_grade(string $type, string $model, array $cfg, array $grade): array
{
    $prompt = lecture_grade_prompt($grade);
    $schema = lecture_grade_schema();
    $data = match ($type) {
        'anthropic' => lecture_claude_json($cfg, $model, $prompt, $schema, 'low'),
        'openai'    => lecture_openai_json($cfg, $model, $prompt, $schema, 2048),
        default     => lecture_ollama_json($cfg, $model, $prompt, $schema, 2048),
    };
    $results = array_is_list($data) ? $data : ($data['results'] ?? null);
    if (!is_array($results)) {
        throw new LectureError('invalid_json', 'Correction sans résultats.');
    }
    return array_values($results);
}

/* ---------- Requêtes HTTP (Ollama, API compatible OpenAI) ---------- */

function lecture_http_headers(array $cfg, bool $json): array
{
    $headers = ['Accept: application/json, application/x-ndjson, text/event-stream'];
    if (trim((string)($cfg['api_key'] ?? '')) !== '') {
        $headers[] = 'Authorization: Bearer ' . trim((string)$cfg['api_key']);
    }
    if ($json) {
        $headers[] = 'Content-Type: application/json';
    }
    return $headers;
}

/** @return array{0: int, 1: ?array} statut HTTP et corps décodé */
function lecture_http_get(array $cfg, string $url, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => lecture_http_headers($cfg, false),
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new LectureError('provider_down', 'Serveur injoignable : ' . curl_error($ch), 503);
    }
    $data = json_decode((string)$raw, true);
    return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), is_array($data) ? $data : null];
}

function lecture_base_url(array $cfg): string
{
    return rtrim(trim((string)$cfg['base_url']), '/');
}

/** Délai total d'une génération, sous le Timeout habituel d'Apache (300 s). */
function lecture_timeout(array $cfg): int
{
    return min(LECTURE_MAX_TIME, max(30, (int)($cfg['timeout'] ?? LECTURE_MAX_TIME)));
}

/* ---------- Ollama ---------- */

/** @return list<array{id: string, name: string}> */
function lecture_ollama_models(array $cfg): array
{
    [$status, $data] = lecture_http_get($cfg, lecture_base_url($cfg) . '/api/tags', 4);
    if ($status === 401 || $status === 403) {
        throw new LectureError('not_configured', 'Clé refusée par le serveur Ollama.', 503);
    }
    if ($status !== 200 || !is_array($data['models'] ?? null)) {
        throw new LectureError('provider_down', "Serveur Ollama : liste des modèles indisponible (HTTP $status).", 503);
    }
    $models = [];
    foreach ($data['models'] as $m) {
        $id = is_array($m) ? ($m['name'] ?? $m['model'] ?? null) : null;
        // Les modèles de plongement (bge, embed…) n'écrivent pas de texte.
        if (is_string($id) && $id !== '' && !preg_match('/(^|[-_:\/])(bge|embed|e5|gte|minilm)/i', $id)) {
            $models[] = ['id' => $id, 'name' => $id];
        }
    }
    return $models;
}

/**
 * Génération par l'API native d'Ollama : son paramètre « format » contraint la sortie au
 * schéma JSON, mot après mot, si bien que la réponse est toujours un JSON valide.
 */
function lecture_ollama_json(array $cfg, string $model, string $prompt, array $schema, int $maxTokens): array
{
    $body = [
        'model'    => $model,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'stream'   => true,
        'format'   => $schema,
        'think'    => false,   // pas de raisonnement avant la réponse (qwen3, deepseek-r1…)
        'options'  => ['num_predict' => $maxTokens],
    ];
    $url = lecture_base_url($cfg) . '/api/chat';
    $result = lecture_stream($cfg, $url, $body);
    if ($result['status'] === 400 && stripos($result['raw'], 'think') !== false) {
        unset($body['think']);   // modèle sans réglage du raisonnement
        $result = lecture_stream($cfg, $url, $body);
    }
    return lecture_stream_json($result, 'Serveur Ollama', $model);
}

/* ---------- API compatible OpenAI ---------- */

/**
 * Génération par /chat/completions. Le schéma est demandé par response_format et rappelé
 * dans la consigne : certains serveurs compatibles ignorent response_format.
 */
function lecture_openai_json(array $cfg, string $model, string $prompt, array $schema, int $maxTokens): array
{
    $body = [
        'model'           => $model,
        'messages'        => [['role' => 'user', 'content' => $prompt . "\n\nReply with only one JSON value matching this JSON Schema:\n"
            . json_encode($schema, JSON_UNESCAPED_SLASHES)]],
        'stream'          => true,
        'max_tokens'      => $maxTokens,
        'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'reply', 'schema' => $schema, 'strict' => true]],
    ];
    $url = lecture_base_url($cfg) . '/chat/completions';
    $result = lecture_stream($cfg, $url, $body);
    // Les modèles récents d'OpenAI refusent max_tokens ; d'autres serveurs, response_format.
    for ($i = 0; $i < 2 && $result['status'] === 400; $i++) {
        if (isset($body['max_tokens']) && stripos($result['raw'], 'max_tokens') !== false) {
            $body['max_completion_tokens'] = $body['max_tokens'];
            unset($body['max_tokens']);
        } elseif (isset($body['response_format']) && stripos($result['raw'], 'response_format') !== false) {
            unset($body['response_format']);
        } else {
            break;
        }
        $result = lecture_stream($cfg, $url, $body);
    }
    return lecture_stream_json($result, 'API compatible OpenAI', $model);
}

/* ---------- Réponse en flux (Ollama, API compatible OpenAI) ---------- */

/*
 * Le flux permet deux choses qu'une réponse d'un seul bloc ne permet pas : voir qu'un modèle
 * est bloqué (rien reçu pendant LECTURE_STALL secondes : file d'attente occupée, ou modèle
 * chargé sur le processeur), et arrêter la génération quand on abandonne.
 */
const LECTURE_STALL = 90;
const LECTURE_MAX_TIME = 270;

/** Lit la réponse JSON d'un flux reçu, ou lève l'erreur qui correspond à son statut. */
function lecture_stream_json(array $result, string $who, string $model): array
{
    $status = $result['status'];
    if ($status === 200 && $result['text'] === '' && $result['error'] !== null) {
        throw new LectureError('upstream_error', "$who : " . $result['error']);
    }
    if ($status === 401 || $status === 403) {
        throw new LectureError('not_configured', "$who : clé refusée.", 503);
    }
    if ($status === 404) {
        throw new LectureError('model_unknown', "$who : modèle inconnu ($model).", 404);
    }
    if ($status === 429) {
        throw new LectureError('rate_limited', "$who : limite de débit atteinte.", 429);
    }
    if ($status !== 200) {
        $err = json_decode($result['raw'], true);
        $detail = is_array($err)
            ? (string)($err['detail'] ?? $err['error']['message'] ?? (is_string($err['error'] ?? null) ? $err['error'] : ''))
            : '';
        throw new LectureError('upstream_error', trim("$who : HTTP $status. $detail"));
    }
    $parsed = lecture_parse_json($result['text']);
    if ($parsed === null) {
        throw new LectureError('invalid_json', in_array($result['finish'], ['length', 'max_tokens'], true)
            ? "$who : réponse coupée (longueur)."
            : "$who : réponse illisible.");
    }
    return $parsed;
}

/**
 * Envoie $body à $url et lit la réponse en flux : lignes JSON de l'API d'Ollama ou
 * événements SSE de l'API compatible OpenAI.
 *
 * @return array{status: int, text: string, finish: ?string, error: ?string, raw: string}
 *         error : erreur signalée dans le flux ; raw : début du corps brut, pour lire le
 *         message d'une réponse d'erreur
 */
function lecture_stream(array $cfg, string $url, array $body): array
{
    $timeout = lecture_timeout($cfg);
    $text = '';
    $finish = null;
    $error = null;
    $raw = '';
    $pending = '';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST            => true,
        CURLOPT_POSTFIELDS      => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER      => lecture_http_headers($cfg, true),
        CURLOPT_CONNECTTIMEOUT  => 4,
        CURLOPT_TIMEOUT         => $timeout,
        CURLOPT_LOW_SPEED_LIMIT => 1,
        CURLOPT_LOW_SPEED_TIME  => LECTURE_STALL,
        CURLOPT_WRITEFUNCTION   => static function ($ch, string $chunk) use (&$text, &$finish, &$error, &$raw, &$pending): int {
            if (strlen($raw) < 8192) {
                $raw .= substr($chunk, 0, 8192 - strlen($raw));
            }
            $pending .= $chunk;
            while (($nl = strpos($pending, "\n")) !== false) {
                $line = trim(substr($pending, 0, $nl));
                $pending = substr($pending, $nl + 1);
                if (str_starts_with($line, 'data:')) {
                    $line = trim(substr($line, 5));
                }
                $event = json_decode($line, true);
                if (!is_array($event)) {
                    continue;
                }
                // Seul le texte de la réponse compte ; un raisonnement éventuel (thinking,
                // reasoning_content) fait avancer le flux sans entrer dans la réponse.
                if (is_string($event['error'] ?? null)) {
                    $error = $event['error'];
                } elseif (is_string($event['error']['message'] ?? null)) {
                    $error = $event['error']['message'];
                } elseif (is_array($event['message'] ?? null)) {            // Ollama
                    if (is_string($event['message']['content'] ?? null)) {
                        $text .= $event['message']['content'];
                    }
                    if (!empty($event['done'])) {
                        $finish = (string)($event['done_reason'] ?? 'stop');
                    }
                } elseif (is_array($event['choices'][0] ?? null)) {         // OpenAI
                    $choice = $event['choices'][0];
                    if (is_string($choice['delta']['content'] ?? null)) {
                        $text .= $choice['delta']['content'];
                    }
                    $finish = $choice['finish_reason'] ?? $finish;
                }
            }
            return strlen($chunk);
        },
    ]);

    if (curl_exec($ch) === false) {
        $msg = curl_error($ch);
        if (curl_errno($ch) === CURLE_OPERATION_TIMEDOUT) {
            $stalled = stripos($msg, 'too slow') !== false;
            throw new LectureError(
                $stalled ? 'stalled' : 'timeout',
                $stalled ? 'Rien reçu pendant ' . LECTURE_STALL . ' s.' : "Réponse inachevée après $timeout s.",
                504
            );
        }
        throw new LectureError('provider_down', 'Serveur injoignable : ' . $msg, 503);
    }
    return [
        'status' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'text'   => $text,
        'finish' => is_string($finish) ? $finish : null,
        'error'  => $error,
        'raw'    => $raw,
    ];
}

/* ---------- API Claude ---------- */

/**
 * Modèles qui acceptent le repli côté serveur (fallbacks: "default") quand un filtre de
 * sécurité refuse une demande. Haiku 5.5 n'en a pas : la demande est envoyée sans.
 */
function lecture_claude_has_fallbacks(string $model): bool
{
    return (bool)preg_match('/^claude-(opus-5-5|opus-5|sonnet-5-5|fable-5-[12]|mythos-5-[12])$/', $model);
}

function lecture_claude_client(array $cfg): Client
{
    if (!lecture_sdk_installed()) {
        throw new LectureError('not_configured', 'SDK Claude absent : lancer « composer install » dans le dossier de l’application.', 503);
    }
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    return new Client(
        apiKey: trim((string)$cfg['api_key']),
        requestOptions: ['timeout' => 280.0, 'maxRetries' => 1],
    );
}

function lecture_claude_json(array $cfg, string $model, string $prompt, array $schema, string $effort): array
{
    $client = lecture_claude_client($cfg);
    $args = [
        'maxTokens'    => 16000,
        'messages'     => [['role' => 'user', 'content' => $prompt]],
        'model'        => $model,
        'outputConfig' => ['effort' => $effort, 'format' => ['type' => 'json_schema', 'schema' => $schema]],
    ];
    if (lecture_claude_has_fallbacks($model)) {
        // Si un filtre de sécurité refuse la demande, l'API la rejoue sur le modèle de repli
        // recommandé au lieu de renvoyer un refus.
        $args['fallbacks'] = 'default';
        $args['betas'] = ['server-side-fallback-2026-07-01'];
    }
    try {
        $message = $client->beta->messages->create(...$args);
    } catch (RateLimitException) {
        throw new LectureError('rate_limited', 'API Claude : limite de débit atteinte.', 429);
    } catch (AuthenticationException | PermissionDeniedException) {
        throw new LectureError('not_configured', 'Clé d’API Claude refusée.', 503);
    } catch (NotFoundException) {
        throw new LectureError('model_unknown', "API Claude : modèle inconnu ($model).", 404);
    } catch (BadRequestException $e) {
        throw new LectureError('upstream_error', 'API Claude : requête refusée. ' . $e->getMessage());
    } catch (APIStatusException $e) {
        $overloaded = ($e->type?->value ?? '') === 'overloaded_error';
        throw new LectureError($overloaded ? 'overloaded' : 'upstream_error', 'API Claude : ' . $e->getMessage(), 503);
    } catch (APITimeoutException) {
        throw new LectureError('timeout', 'API Claude : délai dépassé.', 504);
    } catch (APIConnectionException $e) {
        throw new LectureError('upstream_error', 'API Claude injoignable : ' . $e->getMessage());
    }

    if ($message->stopReason === 'refusal') {
        throw new LectureError('refused', 'Claude a refusé la demande.', 422);
    }
    $text = '';
    foreach ($message->content as $block) {
        if ($block->type === 'text') {
            $text .= $block->text;
        }
    }
    $parsed = lecture_parse_json($text);
    if ($parsed === null) {
        $cut = $message->stopReason === 'max_tokens';
        throw new LectureError('invalid_json', $cut ? 'Réponse de Claude coupée (longueur).' : 'Réponse de Claude illisible.');
    }
    return $parsed;
}

/* ---------- Vérification depuis le panneau d'administration ---------- */

/**
 * Vérifie qu'un moteur répond, sans rien générer : liste des modèles d'Ollama ou de l'API
 * compatible OpenAI, fiche du modèle pour l'API Claude (gratuit).
 *
 * @return array{ok: bool, message: string}
 */
function lecture_engine_check(string $type, array $cfg): array
{
    try {
        if ($type === 'ollama') {
            $models = lecture_ollama_models($cfg);
            $names = array_column($models, 'id');
            $msg = count($models) . ' modèle(s) : ' . implode(', ', array_slice($names, 0, 8)) . (count($names) > 8 ? '…' : '');
            if ($cfg['default_model'] !== '' && !in_array($cfg['default_model'], $names, true)) {
                return ['ok' => false, 'message' => "$msg. Le modèle par défaut « {$cfg['default_model']} » n’y est pas."];
            }
            return ['ok' => $models !== [], 'message' => $models === [] ? 'Aucun modèle installé.' : $msg];
        }
        if ($type === 'openai') {
            [$status, $data] = lecture_http_get($cfg, lecture_base_url($cfg) . '/models', 8);
            if ($status === 401 || $status === 403) {
                return ['ok' => false, 'message' => 'Clé refusée.'];
            }
            if ($status !== 200 || !is_array($data['data'] ?? null)) {
                return ['ok' => false, 'message' => "Liste des modèles indisponible (HTTP $status) ; le modèle sera essayé à la première génération."];
            }
            $ids = array_column($data['data'], 'id');
            return in_array($cfg['model'], $ids, true)
                ? ['ok' => true, 'message' => "Le modèle « {$cfg['model']} » est proposé par le serveur."]
                : ['ok' => false, 'message' => "Le serveur ne propose pas « {$cfg['model']} » (" . count($ids) . ' modèles listés).'];
        }
        $client = lecture_claude_client($cfg);
        $info = $client->models->retrieve($cfg['model']);
        return ['ok' => true, 'message' => 'Clé acceptée ; modèle « ' . ($info->displayName ?? $cfg['model']) . ' ».'];
    } catch (LectureError $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    } catch (AuthenticationException | PermissionDeniedException) {
        return ['ok' => false, 'message' => 'Clé d’API refusée.'];
    } catch (NotFoundException) {
        return ['ok' => false, 'message' => "Modèle inconnu : {$cfg['model']}."];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Vérification impossible : ' . $e->getMessage()];
    }
}
