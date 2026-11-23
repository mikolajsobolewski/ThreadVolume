# XS/ThreadVolume

XenForo 2.3.10+ add-on for continuing open discussions in the same forum after 40 visible posts. The first post of a continuation counts toward that limit. The closing notice is added after the limit is reached.

Set the service account in the **Thread Volume** option group. The site default is user ID 2 (OnanizmBot). Setting the ID to zero pauses rollover.

When a thread already has at least 40 visible posts, it continues after the next visible reply. A title conflict in the same forum leaves the source open for review. Existing subscriptions and their email preferences follow the new volume. Subscribers receive the opening notification under their usual XenForo settings.

## Verification

Run `php tests/volume-rules.php` for the threshold and naming rules. After installing the add-on on dev, run `php tests/dev-rollover.php` from the installed add-on directory. The latter creates and removes test threads and uses the configured user ID.
