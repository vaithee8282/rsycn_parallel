# NFSv3 Parallel Migration

A parallel data-migration utility for synchronizing data between source
and destination NFSv3 mounts using `fpsync` and `rsync`.

## 1. What does this script do?

The script automates data migration between source and destination NFSv3
mounts.

Key capabilities:

-   **Parallel data transfer:** Uses `fpsync` and `rsync` workers to
    accelerate large-scale file transfers.
-   **Multiple synchronization stages:** Supports initial, incremental,
    and final synchronization.
-   **Metadata preservation:** Preserves file ownership, permissions,
    and timestamps.
-   **Web dashboard:** Provides a browser-based dashboard to monitor
    migration progress and transfer throughput.
-   **Worker monitoring:** Tracks individual worker status, errors, and
    migration logs.
-   **Retry support:** Supports retrying failed jobs.
-   **Migration history:** Maintains a record of migration activity.
-   **Data validation:** Provides file-count, checksum, and metadata
    validation.

## 2. Installation

Run the following commands on the **controller VM**.

``` bash
cd /opt

# Clone the project repository
git clone <PROJECT_REPOSITORY_URL>

cd nfs3-parallel-migration

# Install the controller components
sudo ./install-controller.sh
```

Replace `<PROJECT_REPOSITORY_URL>` with the actual Git repository URL.

## 3. Access the web dashboard

After installation, open the following URL in a browser:

``` text
http://<CONTROLLER-IP>/nfs3-migrate/
```

Replace `<CONTROLLER-IP>` with the IP address or DNS name of the
controller VM.

## 4. Configure the migration environment

Open the environment configuration file:

``` bash
sudo vi /etc/nfs3-migrate/migration.env
```

Update the values to match your source and destination environment.

### Example configuration

``` bash
SOURCE="/mnt/nfs_source"

DEST_HOST="DESTINATION_IP"
DEST_USER="nfs3-migrate"
DEST_PATH="/mnt/nfs_destination"

WORKERS="12"
MAX_WORKERS="24"

FPSYNC_MAX_FILES="2000"
FPSYNC_MAX_BYTES="2g"
```

### Configuration parameters

  ------------------------------------------------------------------------
  Parameter               Description             Example
  ----------------------- ----------------------- ------------------------
  `SOURCE`                Source NFS mount path   `/mnt/nfs_source`
                          on the controller       

  `DEST_HOST`             IP address or hostname  `192.0.2.20`
                          of the destination      
                          server                  

  `DEST_USER`             SSH user used to access `nfs3-migrate`
                          the destination server  

  `DEST_PATH`             Destination path on the `/mnt/nfs_destination`
                          destination server      

  `WORKERS`               Number of parallel      `12`
                          workers to use          

  `MAX_WORKERS`           Maximum permitted       `24`
                          number of workers       

  `FPSYNC_MAX_FILES`      Maximum file count per  `2000`
                          `fpsync` task/batch     

  `FPSYNC_MAX_BYTES`      Maximum data size per   `2g`
                          `fpsync` task/batch     
  ------------------------------------------------------------------------

> **Important:** The values above are examples. Adjust worker counts and
> batch limits based on available CPU, memory, network bandwidth,
> storage performance, and the characteristics of the dataset.

## 5. Migration workflow

The tool supports the following synchronization stages:

1.  **Initial synchronization** -- Performs the first bulk copy from the
    source to the destination.
2.  **Incremental synchronization** -- Transfers changes made at the
    source after the initial copy.
3.  **Final synchronization** -- Performs the final synchronization
    before migration cutover.

Use the dashboard to monitor the migration and review worker status,
throughput, errors, and logs.

## 6. Monitoring and validation

The dashboard and migration tooling provide visibility into:

-   Overall migration progress and throughput.
-   Individual worker status.
-   Migration errors and logs.
-   Failed-job retry activity.
-   Migration history.
-   File-count validation.
-   Checksum validation.
-   Metadata validation, including ownership, permissions, and
    timestamps.

Review validation results before completing the migration and switching
workloads to the destination.

## 7. Operational notes

-   Confirm that the source and destination NFS paths are mounted and
    accessible before starting a migration.
-   Confirm that the configured destination SSH user can access the
    destination host and write to the destination path.
-   Ensure the controller can communicate with the destination host over
    the required network ports.
-   Confirm that the destination filesystem has sufficient capacity.
-   Test the configuration with a representative, non-production dataset
    before running a production migration.
-   For the final synchronization and cutover, coordinate application or
    client write activity to avoid missing changes made during
    migration.

## 8. Quick reference

  Item                     Value
  ------------------------ ----------------------------------------
  Installation directory   `/opt/nfs3-parallel-migration`
  Configuration file       `/etc/nfs3-migrate/migration.env`
  Dashboard path           `/nfs3-migrate/`
  Dashboard URL            `http://<CONTROLLER-IP>/nfs3-migrate/`
  Installation command     `sudo ./install-controller.sh`
