**What this script does?**

Automates data migration between source and destination NFSv3 mounts.

Uses parallel fpsync and rsync to accelerate large-scale transfers.

Supports initial, incremental and final synchronization.

Preserves file ownership, permissions and timestamps.

Provides a web dashboard to monitor migration progress and throughput.

Tracks individual worker status, errors and migration logs.

Supports failed-job retries and migration history.

Provides file-count, checksum and metadata validation.


Installation:

cd /opt

# git clone <Project>

cd nfs3-parallel-migration

# sudo ./install-controller.sh




**Dashboard URI:**

http://<CONTROLLER-IP>/nfs3-migrate/




2. Configure your environment
Edit:
sudo vi /etc/nfs3-migrate/migration.env


Configure your actual paths and destination IP:
SOURCE="/mnt/nfs_source"
DEST_HOST="DESTINATION_IP"
DEST_USER="nfs3-migrate"
DEST_PATH="/mnt/nfs_destination"

WORKERS="12"
MAX_WORKERS="24"

FPSYNC_MAX_FILES="2000"
FPSYNC_MAX_BYTES="2g"
