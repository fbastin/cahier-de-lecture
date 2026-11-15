#!/usr/bin/env bash
# Publie Cahier de lecture sur un serveur, par SSH.
#
#   HOST=mon-serveur REMOTE_DIR=/var/www/mon-site/lecture scripts/deploy.sh
#
# Variables :
#   HOST        hôte SSH (obligatoire)
#   REMOTE_DIR  dossier de l'application sur le serveur (obligatoire)
#   SSH         commande SSH (par défaut : ssh)
#   COMPOSER_BIN  commande Composer sur le serveur (par défaut : composer)
#   OVERLAY     dossier local dont les fichiers remplacent ceux de server/ (par exemple un
#               .htaccess propre au site) ; facultatif
#
# Le serveur garde config.local.php et le dossier des données : ils ne sont jamais envoyés ni
# remplacés. Composer installe le SDK de l'API Claude (vendor/).
set -euo pipefail

cd "$(dirname "$0")/.."
: "${HOST:?HOST manquant : hôte SSH du serveur}"
: "${REMOTE_DIR:?REMOTE_DIR manquant : dossier de l’application sur le serveur}"
SSH="${SSH:-ssh}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"

build="$(mktemp -d)"
trap 'rm -rf "$build"' EXIT

cp -R server/. "$build/"
rm -rf "$build/vendor" "$build/data" "$build/config.local.php" "$build/app"
scripts/build.sh "$build/app/index.html" >/dev/null
if [ -n "${OVERLAY:-}" ]; then
    cp -R "$OVERLAY/." "$build/"
fi
find "$build" -type d -exec chmod 755 {} +
find "$build" -type f -exec chmod 644 {} +

echo "Envoi vers $HOST:$REMOTE_DIR"
tar -C "$build" -cf - . | "$SSH" "$HOST" "set -e
mkdir -p '$REMOTE_DIR'
tar -C '$REMOTE_DIR' --no-same-owner -xf -
cd '$REMOTE_DIR'
$COMPOSER_BIN install --no-dev --no-interaction --quiet --optimize-autoloader </dev/null
for f in index.php api.php admin.php lib/app.php lib/lecture.php lib/engines.php; do php -l \"\$f\" >/dev/null; done
if [ ! -f data/settings.json ]; then
    echo 'Première installation : donner au serveur web l’écriture sur data/, puis fixer le mot de passe :'
    echo '  sudo -u www-data php tools/admin-password.php   (dans $REMOTE_DIR)'
fi
echo 'Publié.'"
