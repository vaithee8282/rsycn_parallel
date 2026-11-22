# Generic NFSv3 parallel filesystem migration — RHEL 8

A controller-driven migration toolkit for **any compatible NFSv3 source and destination**. It is not tied to a storage vendor or cloud platform.

## Architecture

- **Source/controller VM:** RHEL 8; mounts the source NFSv3 export at `SOURCE`.
- **Destination VM:** RHEL 8; mounts the destination NFSv3 export at `DEST_PATH`.
- The controller reads the source locally and transfers to the destination VM over SSH using rsync.
- `fpsync` orchestrates fpart partitioning and parallel rsync jobs locally on the controller. The destination VM is the SSH rsync receiver, not an fpsync worker.
- SQLite records controller runs and the fpsync run ID; fpsync native metadata tracks partition queues, completed jobs and logs. The optional PHP dashboard reads both sources and offers fixed administrative actions.
- The destination rsync receiver runs through a dedicated SSH account and a narrowly scoped sudo rule.

The destination filesystem is mounted only on the destination VM; it does not need to be mounted on the controller. fpsync runs its 12 local worker processes on the controller and sends each rsync job to the destination over SSH. Do not use fpsync `-w` workers in this topology: remote fpsync workers would need the source path and fpsync shared work directory mounted on those worker hosts.

## Important behaviour and limits

- This package expects **NFSv3 mounts** on both VMs. The actual NFS server/storage can be any vendor or platform that supports the required NFSv3 operations.
- Matching numeric UID/GID values between source and destination is recommended. `--numeric-ids` preserves numeric IDs; it does not create users/groups on either VM.
- Rsync archive mode preserves common Unix metadata (ownership, group, mode bits, timestamps, symlinks and special files) when the NFS server/export and destination filesystem permit it. The metadata probe tests UID/GID/mode round-trip.
- POSIX ACL and extended-attribute support varies by NFS server and backing filesystem. Xattrs are disabled by default (`PRESERVE_XATTRS=0`). Enable only after testing a round-trip on both real mounts. ACL preservation is not enabled in this baseline; do not assume ACL semantics are portable across NFS implementations.
- The runner scans for hardlinks. If it finds regular files with link count greater than one, it falls back to a single serial `rsync -H` tree transfer so links are not split across parallel partitions. This may be slower. Links whose other names are outside `SOURCE` cannot be reconstructed by this tree copy.
- The initial pass can run while source applications remain online, but it is not point-in-time consistent. Use repeated incremental passes, then quiesce writers (or use a consistent snapshot) for final sync.
- No destination-only files are automatically deleted. Deletion reconciliation is deliberately outside this tool and must be reviewed separately.
- Checksum validation reads file contents across the entire tree and may take many hours.
- The dashboard is a basic admin UI, not an authentication system. It defaults to dark mode and supports a light-mode toggle. For fpsync runs it reads native run metadata, completed partition metadata, queue/work/done directories and fpsync logs; throughput and ETA are based on completed partition bytes. It refreshes every five seconds. Individual in-flight rsync byte counters are not streamed in this release. Put it behind HTTPS, strong authentication and network allowlisting.
- Syntax checks do not replace a pilot against your actual NFS server, mount options, file types, hardlink patterns, and dataset.

## 1. Prerequisites

### Source/controller VM

- RHEL 8, root access for ownership-preserving rsync.
- Source export mounted locally as NFSv3.
- Network access to destination VM SSH port.
- `rsync`, `fpart` (including `fpsync`), `bc`, `sqlite`, `openssh-clients`.

### Destination VM

- RHEL 8, destination export mounted locally as NFSv3.
- `rsync`, `openssh-server`, `sudo`.
- Dedicated migration account permitted to invoke the rsync receiver as root.

Confirm mounts on each VM:

```bash
findmnt -T /mnt/nfs_source -o SOURCE,FSTYPE,OPTIONS
nfsstat -m
```

On the destination VM:

```bash
findmnt -T /mnt/nfs_destination -o SOURCE,FSTYPE,OPTIONS
nfsstat -m
```

Confirm the effective options show NFS version 3 (`vers=3`). Example fstab entries are in `docs/nfs3-mounts.example`. Mount tuning is server- and workload-dependent; begin with conservative defaults and benchmark before changing `rsize`, `wsize`, `nconnect`, or concurrency.

## 2. Install packages

On the source/controller VM (package availability depends on enabled RHEL repositories/EPEL):

```bash
sudo dnf install -y rsync openssh-clients nfs-utils sqlite attr
sudo dnf install -y https://dl.fedoraproject.org/pub/epel/epel-release-latest-8.noarch.rpm
sudo dnf install -y fpart bc php php-sqlite3 httpd
```

On the destination VM:

```bash
sudo dnf install -y rsync openssh-server nfs-utils sudo
sudo systemctl enable --now sshd
```

Verify versions and commands:

```bash
rsync --version
fpart -V
fpsync -V
bc --version
sqlite3 --version
php -v
```

Use compatible rsync versions on both VMs. Confirm that the installed fpart distribution includes the `fpsync` executable (`command -v fpsync`). Upstream usage and resume options are documented in the [fpsync manual](https://manpages.debian.org/unstable/fpart/fpsync.1.en.html) and [upstream examples](https://github.com/martymac/fpart/blob/master/docs/www.fpart.org/docs/fpsync.md). fpsync default fpart exclusions include `.zfs`, `.snapshot*` and `.ckpt`; review these defaults if those directories are part of the intended migration scope.

## 3. Destination account and SSH

On the destination VM:

```bash
sudo useradd --create-home --shell /bin/bash nfs3-migrate
sudo install -d -o nfs3-migrate -g nfs3-migrate -m 0700 /home/nfs3-migrate/.ssh
sudo visudo -f /etc/sudoers.d/nfs3-migrate
```

Add this narrowly scoped command rule:

```sudoers
nfs3-migrate ALL=(root) NOPASSWD: /usr/bin/rsync
```

Then:

```bash
sudo chmod 0440 /etc/sudoers.d/nfs3-migrate
sudo visudo -c
```

On the source/controller VM:

```bash
sudo ssh-keygen -t ed25519 -f /root/.ssh/nfs_migration_ed25519
```

Install the public key in `/home/nfs3-migrate/.ssh/authorized_keys` on the destination. Restrict SSH ingress to the controller VM. Populate `/root/.ssh/known_hosts` and retain `StrictHostKeyChecking=yes`; do not disable host-key checking in production. For stronger hardening, constrain the key in `authorized_keys` and restrict the migration account's SSH capabilities according to your rsync/forced-command design.

The destination filesystem must allow the receiver to create files and set the required ownership/mode metadata. If the NFS server applies root squashing or maps privileged requests, coordinate the required export-side permissions with its administrator. Do not broadly disable root squashing without a security review; restrict any temporary exception to the migration client and remove it afterward.

## 4. Install the controller package

Extract the package on the source/controller VM, then from its root directory:

```bash
sudo install -d -m 0750 /etc/nfs3-migrate
sudo install -d -m 0750 /var/lib/nfs3-migrate /var/log/nfs3-migrate
sudo install -m 0600 config/migration.env.example /etc/nfs3-migrate/migration.env
sudo install -m 0640 config/excludes.txt /etc/nfs3-migrate/excludes.txt
sudo install -m 0750 bin/nfs3-migrate /usr/local/sbin/nfs3-migrate
sudo install -m 0750 bin/nfs3-migrate-web-start /usr/local/sbin/nfs3-migrate-web-start
sudo install -d -m 0755 /usr/local/share/nfs3-migrate
sudo install -m 0644 sql/schema.sql /usr/local/share/nfs3-migrate/schema.sql
```

Edit `/etc/nfs3-migrate/migration.env`:

- `SOURCE`: source NFSv3 mount on the controller.
- `DEST_HOST`: destination RHEL 8 VM hostname/IP.
- `DEST_USER`: destination SSH account (`nfs3-migrate`).
- `DEST_PATH`: destination NFSv3 mount path on destination VM.
- `SSH_KEY`: controller private key.
- `WORKERS`: start at 8; benchmark 12 and 16 before going higher.
- `FPSYNC_MAX_FILES`: maximum file entries per sync job (default 2000).
- `FPSYNC_MAX_BYTES`: maximum estimated bytes per sync job (default 2 GiB).
- `FPSYNC_TMPDIR` and `FPSYNC_SHARED_DIR`: persistent local fpsync state directories on the controller. For local jobs they do not need to be shared with the destination VM.
- `PRESERVE_XATTRS=0`: keep disabled until tested on both mounts.
- `SOURCE_QUIESCED=0`: keep zero during online initial/incremental passes.

Protect the config:

```bash
sudo chown root:root /etc/nfs3-migrate/migration.env
sudo chmod 0600 /etc/nfs3-migrate/migration.env
```

## 5. Preflight and metadata probe

```bash
sudo /usr/local/sbin/nfs3-migrate preflight
sudo /usr/local/sbin/nfs3-migrate probe
```

Preflight verifies that the source and destination paths are mounted as NFSv3, checks required tools, tests SSH, and verifies destination rsync/sudo access. The metadata probe transfers a temporary file and compares numeric UID, GID and mode after a round trip. It requires the source and destination to permit the tested ownership operation. The probe cleans up its temporary files.

If you enable `PRESERVE_XATTRS=1`, install the `attr` package and verify that user xattrs can be set/read on both real mounts before running the probe.

## 6. Initial online copy and incremental passes

The source may remain online for the first pass:

```bash
sudo /usr/local/sbin/nfs3-migrate start
```

The runner scans for hardlinks. If none are found, it invokes fpsync, which uses fpart to build file/byte-bounded partitions and schedules concurrent rsync jobs while the scan proceeds. The native fpsync run ID is recorded in SQLite and its queue/partition/log state is retained under `FPSYNC_TMPDIR` and `FPSYNC_SHARED_DIR`. Use `retry` to resume an interrupted fpsync run or replay a completed run with failed jobs. A fully successful run is not replayed by the retry action; use `incremental` to create a fresh inventory and catch changes. If hardlinks are found, the controller uses serial `rsync -H` instead of parallel partitions because hardlinks can span partitions.

Repeat a fresh inventory and transfer pass to capture changed/new files while source writers are active:

```bash
sudo /usr/local/sbin/nfs3-migrate incremental
```

`incremental` creates a new run and performs another full source inventory; rsync transfers files that differ. It does not delete destination-only entries.

## 7. Final sync and cutover

1. Schedule a maintenance window.
2. Stop or quiesce all processes that can write to the source, or mount a consistent application snapshot and point `SOURCE` at that snapshot.
3. Set `SOURCE_QUIESCED=1` in `/etc/nfs3-migrate/migration.env` only after the source is genuinely quiesced/consistent.
4. Run the final pass:

```bash
sudo /usr/local/sbin/nfs3-migrate final-sync
```

5. Validate counts and checksums:

```bash
sudo /usr/local/sbin/nfs3-migrate validate-counts
sudo /usr/local/sbin/nfs3-migrate validate-checksum
```

6. Validate ownership, mode bits, symlinks, special files, hardlinks (where applicable), application reads/writes and mount behaviour.
7. Switch clients to the destination.
8. Keep the source read-only and available for rollback until business sign-off. Do not delete the source until retention and rollback requirements are met.

## 8. Retry and status

```bash
sudo /usr/local/sbin/nfs3-migrate status
sudo /usr/local/sbin/nfs3-migrate retry
# Optional: retry a specific run ID
sudo /usr/local/sbin/nfs3-migrate retry 20261122T000000Z
```

Do not run overlapping start/final-sync/retry commands; a host-level lock prevents concurrent runs on the controller.

## 9. Dashboard (optional)

Install the PHP files under `/var/www/html/nfs3-migrate/`, configure Apache/PHP-FPM using your RHEL standard, and install the supplied systemd wrapper/service if using the web start action. Review the PHP API and sudoers permissions before enabling any action. The web API exposes preflight, start, retry, count validation, checksum validation and final-sync (the migration script itself requires SOURCE_QUIESCED=1 for final-sync).

Before exposing it to operators:

- Enable HTTPS and corporate authentication/reverse-proxy authentication.
- Restrict access to a management network/VPN.
- Keep migration config root-owned and unreadable by Apache.
- Grant Apache only the specific fixed commands it needs through sudoers.
- Apply CSRF protection and your organisation's web security controls.
- Do not expose the dashboard directly to the public internet.

## 10. Performance and monitoring

Begin with 8 fpsync workers. Benchmark 12 and 16, and increase only while NFS server latency, metadata performance, network throughput and destination write latency remain healthy. `FPSYNC_MAX_FILES=2000` and `FPSYNC_MAX_BYTES=2g` are starting limits, not universal optima. fpsync uses its own run ID and queue files; do not delete or clean the configured fpsync state directories while a run may need to be resumed. Small-file workloads are often metadata-bound; more workers are not always faster. Set `BWLIMIT` if the migration must protect production traffic.

Useful monitoring commands:

```bash
iostat -xz 5
nfsstat -c
sar -n DEV 5
pidstat -dru 5
```

Monitor the NFS server's own volume/export metrics and network interface counters as well. Tune mount options and worker counts based on measured results, not generic assumptions.

## 11. Validation and acceptance checklist

- Source and destination are confirmed NFSv3 mounts.
- Source writers are stopped or a consistent snapshot is used for final validation.
- File and directory counts match (count equality alone is not proof of content equality).
- Checksum dry-run reports no unexpected differences.
- UID/GID and mode bits match for representative samples.
- Required xattrs/ACLs have been tested and separately validated if in scope.
- Hardlink behaviour is validated if the source tree contains hardlinks.
- Application owners approve read/write and performance tests.
- Rollback and source-retention plan is agreed.

This is a configurable engineering baseline. Run a representative pilot before production cutover.


## Dashboard themes
The PHP dashboard supports both light and dark themes. Use the theme toggle in the top-right corner; the selection is retained in the browser. Open `docs/dashboard-preview.html` for a standalone visual preview (demo-only, not connected to migration services).
