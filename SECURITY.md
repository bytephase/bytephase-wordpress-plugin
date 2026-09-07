# Security Policy

## Supported versions

Security fixes are released for the current stable version published on
[WordPress.org](https://wordpress.org/plugins/bytephase-connector/). Older versions are not patched —
please update before reporting.

| Version | Supported |
|---------|-----------|
| 1.0.x   | ✅        |
| < 1.0   | ❌        |

## Reporting a vulnerability

**Please do not open a public GitHub issue or a WordPress.org support thread for a security problem.**

Email **support@bytephase.com** with `SECURITY` in the subject line. Include:

- the plugin version and the WordPress and PHP versions,
- the steps to reproduce, and what an attacker gains,
- any proof-of-concept request or code you have.

We aim to acknowledge a report within 3 business days. Once a fix is released we are happy to credit
you in the changelog — tell us the name or handle you would like used, or ask to stay anonymous.

Please give us a reasonable window to ship a fix before disclosing publicly, and do not run tests
against a BytePhase customer's site without that customer's permission.

## Scope

In scope: everything in this repository — the plugin's admin screens, shortcodes, form connectors,
the background delivery queue and the uninstall routine.

Out of scope: the BytePhase platform and its API (report those the same way, but they are not fixed
by a plugin release), and third-party form plugins such as Contact Form 7 or Elementor. Report issues
in those to their own maintainers.

## What the plugin does with your data

Context that usually matters in a report:

- The plugin forwards the fields a visitor submitted to the BytePhase API over HTTPS. It stores no
  customer records of its own; queued submissions are held only until delivery succeeds.
- The BytePhase API key is stored in a non-autoloaded option. It is never re-rendered after saving,
  never written to logs, and never included in error output.
- Every admin action requires the `manage_options` capability and a nonce. The native form uses a
  nonce and a honeypot.
- Requests use `sslverify`, refuse non-HTTPS BytePhase addresses, have bounded timeouts and do not
  follow cross-host redirects.

See section 12 of the [README](README.md#12-security) and `docs/ARCHITECTURE.md` for the detail.
