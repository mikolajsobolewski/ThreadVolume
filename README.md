# XS/ThreadVolume

XenForo 2.3.10+ add-on for continuing open discussions in the same forum after 40 visible posts. The first post of a continuation counts toward that limit. The closing notice is added after the limit is reached.

Add the site settings to XenForo’s `src/config.php`:

```php
$config['xsThreadVolume'] = [
    'botUserId' => 2, // OnanizmBot on onanizm.club
    'postLimit' => 40,
];
```

Use a valid service account ID. An absent or zero ID keeps rollover disabled. The post limit must be an integer of at least 2 and defaults to 40. The config replaces the former admin option.

When a thread already has at least 40 visible posts, it continues after the next visible reply. A title conflict in the same forum leaves the source open for review. Existing subscriptions and their email preferences follow the new volume. Subscribers receive the opening notification under their usual XenForo settings.

## Build

Run `php build.php --dry-run` to inspect the package contents, or `php build.php` to create a XenForo installation ZIP under `_releases/`. The GitHub Actions build runs checks and uploads the ZIP as an artifact.

## Verification

Run `php tests/volume-rules.php` for the threshold and naming rules. After installing the add-on on dev, run `php tests/dev-rollover.php` from the installed add-on directory. The latter creates and removes test threads and uses the configured user ID.
