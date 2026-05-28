#!/bin/bash
# Script d'installation de l'application SynoLDAP dans Nextcloud
# Usage : sudo bash install.sh /chemin/vers/nextcloud

set -e

NC_PATH="${1:-/var/www/nextcloud}"
APP_DIR="$(cd "$(dirname "$0")" && pwd)"
APP_DEST="${NC_PATH}/apps/synoldap"

if [ ! -f "${NC_PATH}/occ" ]; then
    echo "❌ Nextcloud introuvable dans : ${NC_PATH}"
    echo "   Usage : sudo bash install.sh /chemin/vers/nextcloud"
    exit 1
fi

echo "📁 Copie de l'application vers ${APP_DEST}..."
if [ -d "${APP_DEST}" ]; then
    echo "   Application déjà présente, mise à jour..."
    rm -rf "${APP_DEST}"
fi
cp -r "${APP_DIR}" "${APP_DEST}"

# Propriétaire www-data ou www selon la distrib
WWW_USER="www-data"
if ! id -u "${WWW_USER}" &>/dev/null; then
    WWW_USER="www"
fi

echo "🔑 Application des permissions (${WWW_USER})..."
chown -R "${WWW_USER}:${WWW_USER}" "${APP_DEST}"
find "${APP_DEST}" -type f -exec chmod 644 {} \;
find "${APP_DEST}" -type d -exec chmod 755 {} \;

echo "⚙️  Activation de l'application..."
sudo -u "${WWW_USER}" php "${NC_PATH}/occ" app:enable synoldap

echo ""
echo "✅ SynoLDAP installé avec succès !"
echo "   Rendez-vous dans : Administration → Synology LDAP"
echo ""
echo "   Prérequis :"
echo "   - Activer l'app 'LDAP user and group backend' (user_ldap)"
echo "   - Activer l'app 'External storage support' (files_external)"
echo "   - Extension PHP ldap : apt install php-ldap (puis redémarrer le serveur web)"
