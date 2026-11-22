#!/usr/bin/env bash
# RHEL 8 minimal controller installer for generic NFSv3 + fpsync dashboard.
# Run from the extracted project root as root. Does not configure NFS exports,
# destination VM, SSH keys, TLS certificates, or firewall allowlists.
set -Eeuo pipefail
[[ $EUID -eq 0 ]] || { echo 'Run as root: sudo ./install-controller.sh' >&2; exit 1; }
[[ -f bin/nfs3-migrate && -f web/index.php ]] || { echo 'Run from nfs3-parallel-migration project root' >&2; exit 2; }

# RHEL 8 base repositories must be registered and reachable.
dnf install -y dnf-plugins-core
# EPEL 8 commonly requires CodeReady Builder dependencies; enable only if available.
if command -v subscription-manager >/dev/null 2>&1; then
  subscription-manager repos --enable "codeready-builder-for-rhel-8-$(arch)-rpms" || true
fi

dnf install -y https://dl.fedoraproject.org/pub/epel/epel-release-latest-8.noarch.rpm
dnf install -y \
  httpd php php-fpm php-sqlite3 sqlite rsync openssh-clients nfs-utils \
  attr bc sudo acl unzip policycoreutils-python-utils curl

dnf install -y fpart
for c in fpsync fpart rsync sqlite3 php semanage setfacl; do
  command -v "$c" >/dev/null 2>&1 || { echo "Required command missing after package install: $c" >&2; exit 3; }
done
php -m | grep -qi '^sqlite3$' || { echo 'PHP SQLite3 extension is missing' >&2; exit 4; }

install -d -m 0750 /etc/nfs3-migrate
install -d -m 0750 /var/lib/nfs3-migrate /var/log/nfs3-migrate
install -m 0600 config/migration.env.example /etc/nfs3-migrate/migration.env
install -m 0640 config/excludes.txt /etc/nfs3-migrate/excludes.txt
install -m 0750 bin/nfs3-migrate /usr/local/sbin/nfs3-migrate
install -m 0750 bin/nfs3-migrate-web-start /usr/local/sbin/nfs3-migrate-web-start
install -d -m 0755 /usr/local/share/nfs3-migrate
install -m 0644 sql/schema.sql /usr/local/share/nfs3-migrate/schema.sql
install -d -m 0755 /var/www/html/nfs3-migrate
install -m 0644 web/index.php web/api.php web/status.php /var/www/html/nfs3-migrate/

# Initialize DB before applying web read-only permissions.
if [[ ! -e /var/lib/nfs3-migrate/migration.sqlite ]]; then
  sqlite3 /var/lib/nfs3-migrate/migration.sqlite < /usr/local/share/nfs3-migrate/schema.sql
fi

# Root owns all operational data. Apache receives group read/traverse only.
chown -R root:apache /var/lib/nfs3-migrate /var/log/nfs3-migrate
find /var/lib/nfs3-migrate /var/log/nfs3-migrate -type d -exec chmod 2750 {} +
find /var/lib/nfs3-migrate /var/log/nfs3-migrate -type f -exec chmod 0640 {} +
chown root:root /etc/nfs3-migrate/migration.env
chmod 0600 /etc/nfs3-migrate/migration.env
chown -R root:root /var/www/html/nfs3-migrate
find /var/www/html/nfs3-migrate -type d -exec chmod 0755 {} +
find /var/www/html/nfs3-migrate -type f -exec chmod 0644 {} +

# Apache may read state/logs but cannot modify them. Persistent fpsync state is
# readable for dashboard inspection; migration process itself runs as root.
chown -R root:apache /var/lib/nfs3-migrate /var/log/nfs3-migrate
find /var/lib/nfs3-migrate /var/log/nfs3-migrate -type d -exec chmod 2750 {} +
find /var/lib/nfs3-migrate /var/log/nfs3-migrate -type f -exec chmod 0640 {} +

# The wrapper starts a root-owned transient systemd service. Only the fixed
# wrapper and fixed CLI subcommands are delegated to Apache.
install -m 0440 docs/web-sudoers /etc/sudoers.d/nfs3-migrate-web
visudo -c

# SELinux: static PHP source plus read-only state/log visibility.
semanage fcontext -a -t httpd_sys_content_t '/var/www/html/nfs3-migrate(/.*)?' 2>/dev/null || \
  semanage fcontext -m -t httpd_sys_content_t '/var/www/html/nfs3-migrate(/.*)?'
semanage fcontext -a -t httpd_sys_content_t '/var/lib/nfs3-migrate(/.*)?' 2>/dev/null || \
  semanage fcontext -m -t httpd_sys_content_t '/var/lib/nfs3-migrate(/.*)?'
semanage fcontext -a -t httpd_sys_content_t '/var/log/nfs3-migrate(/.*)?' 2>/dev/null || \
  semanage fcontext -m -t httpd_sys_content_t '/var/log/nfs3-migrate(/.*)?'
restorecon -Rv /var/www/html/nfs3-migrate /var/lib/nfs3-migrate /var/log/nfs3-migrate

# PHP sessions and SQLite extension are provided by RHEL's PHP-FPM integration.
systemctl enable --now php-fpm httpd
httpd -t

cat <<'MSG'
Controller packages and dashboard files installed.

NEXT STEPS (do not start a migration yet):
  1. Edit /etc/nfs3-migrate/migration.env with real NFS mount paths and destination host.
  2. Configure and test the root SSH key to the destination migration account.
  3. Install docs/destination-sudoers on the destination VM and validate it.
  4. Confirm both mounts are NFSv3, then run:
       /usr/local/sbin/nfs3-migrate preflight
       /usr/local/sbin/nfs3-migrate probe
  5. Configure HTTPS, authentication, and management-subnet firewall allowlisting.
  6. Test the dashboard's status and action endpoints. SELinux may require a
     reviewed local policy for the Apache-to-sudo action path; do not disable SELinux.

Dashboard URL: http://CONTROLLER-IP/nfs3-migrate/
MSG
