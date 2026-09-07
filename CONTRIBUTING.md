# Contributing

Thanks for taking the time to look at BytePhase Connector. Bug reports, patches and connector
contributions are all welcome.

## Reporting a bug

Open a [GitHub issue](https://github.com/bytephase/bytephase-wordpress-plugin/issues) with the plugin,
WordPress and PHP versions, what you expected, what happened, and the steps to reproduce. If a
submission failed to reach BytePhase, the **BytePhase → Health** screen shows the request ID and the
error — include those.

Security problems go to **support@bytephase.com** instead. See [SECURITY.md](SECURITY.md).

Questions about your own BytePhase account, API key or field mapping are not plugin bugs — use the
[WordPress.org support forum](https://wordpress.org/support/plugin/bytephase-connector/) or BytePhase
in-app support.

## Setting up

```bash
git clone https://github.com/bytephase/bytephase-wordpress-plugin.git
cd bytephase-wordpress-plugin
composer install
```

Symlink or copy the folder into `wp-content/plugins/` of a local WordPress install to run it.

## Before you open a pull request

All three gates run in CI on PHP 8.1, 8.2 and 8.3, and must pass:

```bash
composer test      # PHPUnit
composer phpcs     # PSR-12 + WordPress security sniffs + PHP 8.1 compatibility
composer phpstan   # static analysis
```

Please also:

- Keep the plugin a **connector**. Field mapping, validation, duplicate detection, assignment and
  notifications belong in BytePhase, not here — see section 1 of the [README](README.md).
- Add or update tests for behaviour you change. Tests use Brain Monkey, so no WordPress install is
  needed.
- Keep the plugin header `Version`, the `readme.txt` `Stable tag` and the changelog in sync when a
  release is involved.
- Write commit subjects in the form `type(scope): summary`, e.g. `fix(admin): stop double-escaping the
  health table`.

## Adding a form connector

Each integration is a single class in `src/Connectors/` that hooks its form plugin, strips that
plugin's internal fields, and hands the remaining fields plus a stable form identifier to the
dispatcher. `Cf7Connector` is the shortest example to copy. The form identifier must stay stable when
a site owner renames the form, otherwise their mapping breaks.

## Licence

By contributing you agree that your work is licensed under the
[GPL-2.0-or-later](LICENSE), the same licence as the plugin.
