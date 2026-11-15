# Cahier de lecture

Application de compréhension de lecture. Elle génère des textes courts en français ou en anglais, du niveau A1 au niveau C2 du CECRL, avec leurs questions : QCM, vrai ou faux, réponses rédigées. Elle corrige les réponses, montre dans le texte la phrase qui justifie chaque réponse, et propose une lecture orale qui surligne les mots au fur et à mesure.

Une seule source, [`artifact/cahier-de-lecture.html`](artifact/cahier-de-lecture.html), sert de deux façons :

- **comme artefact claude.ai** : la page est publiée telle quelle, et chaque personne qui l'ouvre génère les textes avec son propre compte claude.ai ;
- **sur votre serveur** : une petite application PHP sert la même page, et les textes sont générés par les moteurs que l'administrateur de l'installation connecte : API Claude, API compatible OpenAI, ou modèles installés avec Ollama.

Aucun moteur n'est fourni ni préconfiguré : chaque installation utilise ses propres clés d'API et ses propres serveurs.

## Comme artefact claude.ai

Publier `artifact/cahier-de-lecture.html` comme artefact, avec la capacité `sample`. Le fichier est un fragment de page : claude.ai ajoute `<!doctype html>`, `<head>` et `<body>` au moment de la publication. La génération et la correction des réponses rédigées passent par le compte claude.ai de la personne qui ouvre la page.

## Sur votre serveur

### Prérequis

- PHP 8.1 ou plus récent, avec les extensions `curl`, `json` et `mbstring`.
- Apache 2.4 (le fichier `.htaccess` fourni protège tout sauf les trois pages de l'application). Avec un autre serveur web, n'exposer que `index.php`, `api.php` et `admin.php`.
- Composer, pour le SDK de l'API Claude ; inutile si vous n'utilisez pas ce moteur.

### Installer

```sh
scripts/build.sh                     # compile la page dans server/app/index.html
cd server
composer install --no-dev            # SDK de l'API Claude (vendor/)
cp config.example.php config.local.php   # facultatif : voir les réglages plus bas
mkdir data && sudo chown www-data data   # le serveur web doit pouvoir écrire ses réglages
sudo -u www-data php tools/admin-password.php
```

Le dossier `server/` est l'application : le servir à l'adresse voulue (par exemple `/lecture/`), ou le publier sur un serveur distant avec `scripts/deploy.sh` :

```sh
HOST=mon-serveur REMOTE_DIR=/var/www/mon-site/lecture scripts/deploy.sh
```

Le mot de passe de l'administrateur se fixe sur le serveur, jamais depuis le web : une installation neuve ne peut pas être prise par la première personne qui en trouve l'adresse.

### Administrer

Le panneau d'administration est la page `admin.php` de l'application. On y règle :

- le **code d'accès** que l'enseignant donne aux élèves ; sans lui, personne d'autre que l'administrateur n'utilise l'application, et le changer ferme les sessions ouvertes avec l'ancien ;
- les **moteurs** proposés dans la page, et celui qui est choisi par défaut ;
- le mot de passe de l'administrateur.

Les clés d'API sont enregistrées dans `data/settings.json` et ne sont jamais réaffichées ni envoyées au navigateur. Le bouton « Vérifier les moteurs » contrôle les clés et les modèles sans rien générer. Après dix essais de code ou de mot de passe manqués, une adresse IP attend quinze minutes.

### Moteurs

Chaque génération est facturée, le cas échéant, au compte qui détient la clé.

- **API Claude** : la clé se crée sur console.anthropic.com, rubrique API Keys. Le modèle proposé est Claude Sonnet 5.5 ; Claude Haiku 5.5 coûte moins cher, Claude Opus 5.5 écrit les textes les plus soignés. La sortie est contrainte par un schéma JSON. Si un filtre de sécurité refuse une demande, l'API la rejoue sur un modèle de repli, pour les modèles qui le permettent.
- **API compatible OpenAI** : adresse de l'API (par exemple `https://api.openai.com/v1`), clé et identifiant du modèle. Le schéma JSON est demandé par `response_format` et rappelé dans la consigne, pour les services qui ignorent ce paramètre.
- **Ollama** : l'installation d'Ollama et de ses modèles est à la charge de la personne qui déploie l'application (voir ollama.com ; par exemple `ollama pull gemma3:12b`). Adresse par défaut : `http://localhost:11434`. Un relais qui reprend l'API d'Ollama et demande une clé convient aussi. La sortie est contrainte par le schéma JSON ; une carte graphique est très recommandée. Si les réponses sont coupées, augmenter la longueur de contexte du modèle (`num_ctx` d'au moins 8192).

Les modèles locaux respectent moins bien la structure demandée. L'application écarte du texte les morceaux de structure qu'un modèle y aurait glissés, et tire au sort la version vraie ou fausse de chaque vrai-faux, que le modèle écrit toutes deux : laissés choisir, les modèles écrivent presque toujours une affirmation fausse.

### Réglages de l'installation (`config.local.php`)

Tous facultatifs ; le détail est dans [`server/config.example.php`](server/config.example.php).

- `data_dir` : dossier des données, de préférence hors de la racine du site.
- `back_link` : lien de retour affiché en haut de la page.
- `claude_ai_url` : adresse d'une version claude.ai de la page, montrée à l'administrateur seul.
- `engines`, `default_engine` : moteurs fixés dans le fichier plutôt que dans le panneau, qui les affiche alors sans les modifier.
- `host` : **site hôte**. Au lieu du code d'accès et du mot de passe, l'application s'en remet à la session d'un site existant : quelques fonctions disent qui peut l'utiliser, qui l'administre, quel est le jeton CSRF et où se trouve la page de connexion.

### Ce qui est envoyé, et où

- Les réglages de génération (langue, niveau, genre, longueur, types de questions) sont pris dans des listes fermées ; le seul texte libre est le thème, limité à 120 caractères. Les consignes sont construites par le serveur : `api.php` ne sert pas de relais général vers une IA.
- Le texte et les réponses rédigées à corriger partent vers le moteur choisi.
- Les réponses des élèves et leur historique restent dans leur navigateur.

## Fichiers

| Fichier | Rôle |
|---|---|
| `artifact/cahier-de-lecture.html` | La page : réglages, texte, questions, correction, lecture orale |
| `server/index.php` | Code d'accès, puis la page avec son jeton CSRF et ses liens |
| `server/api.php` | `engines` (liste des moteurs), `generate`, `grade` |
| `server/admin.php` | Panneau d'administration |
| `server/lib/app.php` | Configuration, réglages enregistrés, accès |
| `server/lib/lecture.php` | Réglages vérifiés, consignes, schémas JSON, lecture des réponses |
| `server/lib/engines.php` | API Claude, API compatible OpenAI, Ollama |
| `server/tools/admin-password.php` | Mot de passe de l'administrateur, en ligne de commande |
| `server/config.example.php` | Modèle de `config.local.php` |
| `scripts/build.sh` | Compile la page pour le serveur |
| `scripts/deploy.sh` | Publie l'application sur un serveur par SSH |

Les consignes existent en deux exemplaires : `buildPrompt()` et `buildGradePrompt()` dans la page (pour claude.ai), et `server/lib/lecture.php` (pour le serveur). Il faut modifier les deux ensemble.

## Licence

MIT : voir [LICENSE](LICENSE).
