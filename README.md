# XS/ThreadVolume

XenForo 2.3.10+ add-on for continuing open discussions in the same forum after 40 visible posts. The first post of a continuation counts toward that limit. The closing notice is added after the limit is reached.

Set the service account in the **Thread Volume** option group. The site default is user ID 2 (OnanizmBot). Setting the ID to zero pauses rollover. Existing eligible threads can be picked up with the separate backfill.

## Existing threads

From the installed add-on directory, run:

```sh
php scripts/backfill.php --preview
php scripts/backfill.php --apply
```

Preview is the default. Optionally pass `--thread-id=ID` after the mode to inspect or apply to one thread. A failed batch exits with status 2 and reports the failed thread IDs. The operation can be repeated safely.

## Verification

Run `php tests/volume-rules.php` for the threshold and naming rules. After installing the add-on on dev, run `php tests/dev-rollover.php` from the installed add-on directory. The latter creates and removes test threads and uses the configured user ID.
