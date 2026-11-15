#!/usr/bin/env bash
# Compile la page pour le serveur : artifact/cahier-de-lecture.html, la source unique (aussi
# publiable telle quelle comme artefact claude.ai), devient un document complet,
# server/app/index.html, dont index.php remplit les métadonnées.
#
#   scripts/build.sh [fichier de sortie]
set -euo pipefail

cd "$(dirname "$0")/.."
SRC=artifact/cahier-de-lecture.html
OUT="${1:-server/app/index.html}"
mkdir -p "$(dirname "$OUT")"

# claude.ai entoure la page d'un squelette au moment de la publier ; ici, on l'écrit. Le titre,
# les métadonnées et les polices (tout ce qui précède <style>) vont dans <head>.
{
    printf '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
    printf '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">\n'
    for meta in api:API csrf:CSRF claude-url:CLAUDE_URL admin:ADMIN back-url:BACK_URL back-label:BACK_LABEL login:LOGIN; do
        printf '<meta name="lecture-%s" content="__LECTURE_%s__">\n' "${meta%%:*}" "${meta#*:}"
    done
    printf '<style>:root{color-scheme:light;padding-top:env(safe-area-inset-top,0px);padding-bottom:env(safe-area-inset-bottom,0px)}body{margin:0;font:14px/1.4 system-ui,sans-serif}img{max-width:100%%}[hidden]{display:none!important}</style>\n'
    awk '/^<style>/{exit} {print}' "$SRC"
    printf '</head><body>\n'
    awk 'f{print; next} /^<style>/{f=1; print}' "$SRC"
    printf '</body></html>\n'
} > "$OUT"
echo "Page compilée : $OUT"
