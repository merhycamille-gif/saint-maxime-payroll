#!/usr/bin/env bash
# =============================================================================
# 🖥️ MSA Payroll — تجهيز سيرفر Ubuntu 24.04 من الصفر بكبسة واحدة (2026-09-28)
#   «بدي البرنامج يكون على سيرفر سريع جداً وموثوق ما في أخطاء — هيدا رزق الناس»
#
#   يركّب: Apache + PHP 8.3 + MariaDB + شهادة https (Let's Encrypt) + جدار ناري + fail2ban
#          + تحديثات أمان تلقائية + swap + نسخ احتياطي يومي (قاعدة البيانات + المستندات، 30 يوماً)
#          + نشر تلقائي (git pull كل دقيقتين من GitHub — نفس آلية الموقع القديم) + مراقبة صحّة.
#   آمن لإعادة التشغيل (idempotent): كل خطوة تتحقّق قبل أن تعمل.
#
#   الاستعمال (كـroot على السيرفر الجديد):
#     curl -fsSL https://raw.githubusercontent.com/merhycamille-gif/saint-maxime-payroll/main/tools/server/setup_ubuntu.sh -o /root/setup.sh
#     bash /root/setup.sh msapayroll.com
#   بعده: (1) استيراد الداتا:  bash /usr/local/bin/msa-import.sh /root/dump.sql [/root/uploads.tar.gz]
#          (2) بعد تحويل DNS:  bash /usr/local/bin/msa-ssl.sh
# =============================================================================
set -euo pipefail
DOMAIN="${1:-msapayroll.com}"
APP_DIR="/var/www/msapayroll"
REPO="https://github.com/merhycamille-gif/saint-maxime-payroll.git"
DB_NAME="saint_maxime_payroll"
DB_USER="msa"
CRED="/root/msa-credentials.txt"
BACKUP_DIR="/var/backups/msa"
export DEBIAN_FRONTEND=noninteractive

log(){ echo -e "\n\033[1;36m=== $* ===\033[0m"; }

log "1/10 النظام: تحديث + المنطقة الزمنية + swap"
apt-get update -y
apt-get upgrade -y
timedatectl set-timezone Asia/Beirut || true
if ! swapon --show | grep -q '^/swapfile'; then
  fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
  grep -q '/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

log "2/10 الحزم: Apache + PHP + MariaDB + أدوات"
apt-get install -y apache2 mariadb-server git unzip curl ufw fail2ban unattended-upgrades \
  php php-cli php-mysql php-mbstring php-xml php-zip php-curl php-gd php-intl php-bcmath libapache2-mod-php \
  certbot python3-certbot-apache rsync
PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
a2enmod rewrite headers expires deflate >/dev/null

log "3/10 PHP: حدود مناسبة لرفع المستندات والتقارير الكبيرة"
PHPINI="/etc/php/${PHPV}/apache2/php.ini"
for kv in "upload_max_filesize=64M" "post_max_size=64M" "memory_limit=512M" "max_execution_time=300" "max_input_vars=20000" "date.timezone=Asia/Beirut" "expose_php=Off"; do
  k="${kv%%=*}"; v="${kv#*=}"
  if grep -qE "^\s*;?\s*${k}\s*=" "$PHPINI"; then sed -i -E "s|^\s*;?\s*${k}\s*=.*|${k} = ${v}|" "$PHPINI"; else echo "${k} = ${v}" >> "$PHPINI"; fi
done
# نسخة CLI (للنسخ الاحتياطي والفحوص)
CLIINI="/etc/php/${PHPV}/cli/php.ini"; [ -f "$CLIINI" ] && sed -i -E "s|^\s*;?\s*date.timezone\s*=.*|date.timezone = Asia/Beirut|" "$CLIINI" || true

log "4/10 MariaDB: قاعدة البيانات والمستخدم (كلمة سرّ عشوائية تُحفَظ بـ$CRED)"
systemctl enable --now mariadb
if [ -f "$CRED" ] && grep -q '^DB_PASS=' "$CRED"; then
  DB_PASS="$(grep '^DB_PASS=' "$CRED" | cut -d= -f2-)"
else
  DB_PASS="$(openssl rand -hex 18)"  # 36 hex chars — بلا أنبوب /dev/urandom (يتفادى SIGPIPE مع pipefail)
  { echo "DB_NAME=${DB_NAME}"; echo "DB_USER=${DB_USER}"; echo "DB_PASS=${DB_PASS}"; echo "CREATED=$(date -Is)"; } > "$CRED"; chmod 600 "$CRED"
fi
mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}'; ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}'; GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;"
# إعدادات أداء معقولة لسيرفر 8GB
cat > /etc/mysql/mariadb.conf.d/60-msa.cnf <<'EOF'
[mysqld]
innodb_buffer_pool_size = 1G
innodb_log_file_size = 256M
max_allowed_packet = 128M
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
sql_mode = NO_ENGINE_SUBSTITUTION
EOF
systemctl restart mariadb

log "5/10 الكود: استنساخ من GitHub إلى $APP_DIR"
if [ ! -d "$APP_DIR/.git" ]; then
  git clone --depth 1 "$REPO" "$APP_DIR"
else
  git -C "$APP_DIR" pull -q --ff-only || true
fi
mkdir -p "$APP_DIR/uploads" "$APP_DIR/tmp" "$APP_DIR/config"  # config/ فارغ بعد الاستنساخ (database.php متجاهَل بـgit)
# ملف الإعداد الخاصّ بهذا الخادم (غير منشور بـgit — راجع .gitignore)
if [ ! -f "$APP_DIR/config/database.php" ]; then
cat > "$APP_DIR/config/database.php" <<EOF
<?php
/**
 * إعداد هذا الخادم (msapayroll — Contabo). غير منشور بـgit. أُنشئ بـtools/server/setup_ubuntu.sh في $(date -Is)
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
define('DB_HOST', 'localhost');
define('DB_NAME', '${DB_NAME}');
define('DB_USER', '${DB_USER}');
define('DB_PASS', '${DB_PASS}');
define('DB_CHARSET', 'utf8mb4');
define('APP_NAME', 'MSA Payroll');
define('APP_VERSION', '2.0.0');
define('BASE_URL', '/'); // جذر الموقع = البرنامج مباشرة (msapayroll.com/)
define('TIMEZONE', 'Asia/Beirut');
date_default_timezone_set(TIMEZONE);
function getDB() {
    static \$pdo = null;
    if (\$pdo === null) {
        try {
            \$dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            \$pdo = new PDO(\$dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException \$e) {
            die('خطأ بالاتصال بقاعدة البيانات / Erreur de connexion à la base de données');
        }
    }
    return \$pdo;
}
EOF
fi
chown -R www-data:www-data "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 755 {} \; ; find "$APP_DIR" -type f -exec chmod 644 {} \;
chmod -R 775 "$APP_DIR/uploads" "$APP_DIR/tmp"

log "6/10 Apache: الموقع $DOMAIN"
cat > /etc/apache2/sites-available/msapayroll.conf <<EOF
<VirtualHost *:80>
    ServerName ${DOMAIN}
    ServerAlias www.${DOMAIN}
    DocumentRoot ${APP_DIR}
    <Directory ${APP_DIR}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    # لا وصول لملفات الإعداد والنسخ والأدوات من الخارج
    <DirectoryMatch "^${APP_DIR}/(config|tmp|tools|\.git)">
        Require all denied
    </DirectoryMatch>
    <FilesMatch "\.(sql|log|bak|md|sh)$">
        Require all denied
    </FilesMatch>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set Referrer-Policy "same-origin"
    ErrorLog \${APACHE_LOG_DIR}/msapayroll-error.log
    CustomLog \${APACHE_LOG_DIR}/msapayroll-access.log combined
</VirtualHost>
EOF
a2dissite 000-default >/dev/null 2>&1 || true
a2ensite msapayroll >/dev/null
echo "ServerTokens Prod
ServerSignature Off" > /etc/apache2/conf-available/msa-hardening.conf; a2enconf msa-hardening >/dev/null
apache2ctl configtest && systemctl reload apache2

log "7/10 الحماية: جدار ناري + fail2ban + تحديثات أمان تلقائية"
ufw allow OpenSSH >/dev/null; ufw allow 80/tcp >/dev/null; ufw allow 443/tcp >/dev/null; ufw --force enable >/dev/null
systemctl enable --now fail2ban
cat > /etc/fail2ban/jail.d/msa.conf <<'EOF'
[sshd]
enabled = true
maxretry = 5
bantime = 1h
EOF
systemctl restart fail2ban
echo 'APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";' > /etc/apt/apt.conf.d/20auto-upgrades

log "8/10 النشر التلقائي: git pull كل دقيقتين (نفس ما كان على الاستضافة القديمة كل 5 دقائق)"
cat > /usr/local/bin/msa-deploy.sh <<EOF
#!/usr/bin/env bash
# يسحب آخر كود من GitHub (config/database.php وuploads/ خارج git فلا يُمسّان)
cd ${APP_DIR} || exit 1
git fetch -q origin main || exit 0
if [ "\$(git rev-parse HEAD)" != "\$(git rev-parse origin/main)" ]; then
  git reset -q --hard origin/main
  chown -R www-data:www-data ${APP_DIR}
  echo "\$(date -Is) deployed \$(git rev-parse --short HEAD)" >> /var/log/msa-deploy.log
fi
EOF
chmod +x /usr/local/bin/msa-deploy.sh
git config --global --add safe.directory "$APP_DIR"

log "9/10 النسخ الاحتياطي اليومي: قاعدة البيانات + المستندات إلى $BACKUP_DIR (30 يوماً)"
mkdir -p "$BACKUP_DIR"; chmod 700 "$BACKUP_DIR"
cat > /usr/local/bin/msa-backup.sh <<EOF
#!/usr/bin/env bash
set -e
D=\$(date +%Y-%m-%d_%H%M)
mysqldump --single-transaction --quick --routines --default-character-set=utf8mb4 ${DB_NAME} | gzip -9 > ${BACKUP_DIR}/db_\$D.sql.gz
tar -czf ${BACKUP_DIR}/uploads_\$D.tar.gz -C ${APP_DIR} uploads
find ${BACKUP_DIR} -name 'db_*.sql.gz' -mtime +30 -delete
find ${BACKUP_DIR} -name 'uploads_*.tar.gz' -mtime +30 -delete
echo "\$(date -Is) ok db=\$(du -h ${BACKUP_DIR}/db_\$D.sql.gz | cut -f1) uploads=\$(du -h ${BACKUP_DIR}/uploads_\$D.tar.gz | cut -f1)" >> /var/log/msa-backup.log
EOF
chmod +x /usr/local/bin/msa-backup.sh
# استيراد الداتا (من دمب البرنامج pages/backup.php?action=sql أو phpMyAdmin) + المستندات
cat > /usr/local/bin/msa-import.sh <<EOF
#!/usr/bin/env bash
# الاستعمال: msa-import.sh /root/dump.sql [/root/uploads.tar أو .tar.gz]
set -e
SQL="\$1"; UP="\${2:-}"
[ -f "\$SQL" ] || { echo "لا يوجد \$SQL"; exit 1; }
/usr/local/bin/msa-backup.sh || true   # نسخة قبل الاستيراد
case "\$SQL" in *.gz) zcat "\$SQL" | mysql --default-character-set=utf8mb4 ${DB_NAME};; *) mysql --default-character-set=utf8mb4 ${DB_NAME} < "\$SQL";; esac
echo "✅ استُوردت قاعدة البيانات: \$(mysql -N -e "SELECT COUNT(*) FROM employees" ${DB_NAME}) موظفاً، \$(mysql -N -e "SELECT COUNT(*) FROM monthly_salaries" ${DB_NAME}) صفّ راتب"
if [ -n "\$UP" ] && [ -f "\$UP" ]; then tar -xf "\$UP" -C ${APP_DIR}; chown -R www-data:www-data ${APP_DIR}/uploads; echo "✅ المستندات: \$(find ${APP_DIR}/uploads -type f | wc -l) ملفاً"; fi
EOF
chmod +x /usr/local/bin/msa-import.sh
# شهادة https بعد تحويل الدومين
cat > /usr/local/bin/msa-ssl.sh <<EOF
#!/usr/bin/env bash
certbot --apache -n --agree-tos --redirect -m merhycamille@gmail.com -d ${DOMAIN} -d www.${DOMAIN} && systemctl reload apache2
EOF
chmod +x /usr/local/bin/msa-ssl.sh
# فحص صحّة كل 5 دقائق: إن سقط Apache أو MariaDB يعاد تشغيله ويُسجَّل
cat > /usr/local/bin/msa-health.sh <<EOF
#!/usr/bin/env bash
for s in apache2 mariadb; do systemctl is-active --quiet \$s || { systemctl restart \$s; echo "\$(date -Is) restarted \$s" >> /var/log/msa-health.log; }; done
code=\$(curl -s -o /dev/null -m 20 -w '%{http_code}' http://127.0.0.1/login.php -H "Host: ${DOMAIN}")
[ "\$code" = "200" ] || echo "\$(date -Is) login.php http=\$code" >> /var/log/msa-health.log
EOF
chmod +x /usr/local/bin/msa-health.sh
cat > /etc/cron.d/msa <<'EOF'
*/2 * * * * root /usr/local/bin/msa-deploy.sh >/dev/null 2>&1
0 3 * * *   root /usr/local/bin/msa-backup.sh >/dev/null 2>&1
*/5 * * * * root /usr/local/bin/msa-health.sh >/dev/null 2>&1
EOF
chmod 644 /etc/cron.d/msa

log "10/10 تحقّق"
systemctl is-active apache2 mariadb fail2ban | tr '\n' ' '; echo
php -v | head -1
curl -s -o /dev/null -w "login.php (بلا داتا بعد): http=%{http_code}\n" http://127.0.0.1/login.php -H "Host: ${DOMAIN}" || true
IP="$(curl -s -m 10 https://api.ipify.org || hostname -I | awk '{print $1}')"
echo
echo "✅ السيرفر جاهز. العنوان: ${IP}"
echo "   بيانات قاعدة البيانات: ${CRED}"
echo "   الخطوة التالية: bash /usr/local/bin/msa-import.sh /root/dump.sql [/root/uploads.tar.gz]"
echo "   ثم بعد تحويل DNS للدومين ${DOMAIN} → ${IP}:  bash /usr/local/bin/msa-ssl.sh"
