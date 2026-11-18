# Scan API shared database cutover

The menu API and restaurant sites use the `menu` database in `menu_api_db`.
The scan API currently uses `menu_scan` in `newmenuscanapi-mysql-1`.
The scan API cannot validate menu login tokens while these databases are separate.

## Backups and current data

On 2026-11-18, root-only, gzip-verified backups were created on the production server:

- `/root/menu-backups/menu-before-shared-scan-20261118.sql.gz`
- `/root/menu-backups/menu_scan-before-shared-scan-20261118.sql.gz`

The source has 15 scans, 314 images, 46 scan jobs, and 4 job outputs. All 15
scans have null `restaurant_id`, `created_by_user_id`, and `dish_id`. The target
has no scans and requires restaurant and user references. Do not import those
rows into `menu`. The owner decision for this cutover is to keep them archived
and start new scans in `menu`. Existing app sessions referring to those scan IDs
will not resolve after cutover; create new scans in the app. The backups preserve
the old records for a later, explicitly mapped import.

## Before cutover

1. Confirm both checkouts are at the intended commits and clean. The scan API
   code must match the local tested release before restarting containers.
2. Take fresh backups of both databases immediately before the cutover; the
   existing backups are a baseline, not a substitute for a fresh snapshot.
3. Confirm no scan uploads or background jobs are in progress. Check the old
   database queue for pending jobs and let them finish or record them.
4. Keep the 15 ownerless legacy scans archived in `menu_scan`; do not copy them
   into `menu` or assign a restaurant by guesswork.
5. Verify that `newmenuapi_default` exists and `menu_api_db` is healthy.
6. Add `SHARED_MENU_DB_USERNAME` and `SHARED_MENU_DB_PASSWORD` to the scan API's
   untracked `.env`. Use credentials with access to `menu`; keep them out of Git.
7. Clear Laravel's cached configuration in the scan checkout. Check migration
   status against `menu` before running any migration. Do not run the scan
   API's whole migration set against `menu` without reviewing pending entries.

## Cutover

Run the scan app, queue worker, and its Redis service with
`compose.shared-menu-db.yml`. This file connects the app and queue to the
existing menu Docker network and points them to `menu_api_db`/`menu`. It uses
the scan Redis instance for queue, cache, and session data. It does not start a
second MySQL service. Leave the old MySQL container and its volume intact for
rollback and legacy scan recovery.

After startup, verify the scan app and queue report `DB_DATABASE=menu`, that
the scan API health endpoint returns 200, and that a restaurant login token
can create and access a new scan. Test with a disposable restaurant account
and unique `QA_RUN_` scan data; clean it afterward. Then verify background
removal for one scan and confirm the menu API remains healthy.

## Rollback

If the shared connection fails, restart the scan app and queue with the
original `docker-compose.yml` and original untracked `.env`. The old MySQL
service and its volume must remain available. Restore a database dump only
after evaluating any writes made since the cutover; a blind restore would
discard newer restaurant data.
