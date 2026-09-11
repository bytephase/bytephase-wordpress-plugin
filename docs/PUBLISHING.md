# Publishing BytePhase Connector to WordPress.org

Step-by-step release runbook. Written 2026-09-07, against the state left by the 1.0.1
release.

> **An SVN tag is immutable.** Once `tags/X.Y.Z` is committed it cannot be amended,
> deleted, or re-pointed — a bad release can only be superseded by a new version. Every
> check in §3 exists because of that. Do not skip them to save five minutes.

---

## 0. Prerequisites (one-time)

| Thing | State on this machine | Notes |
|---|---|---|
| `svn` | ✅ `/usr/bin/svn` | Verified 2026-09-07. Older internal notes claimed it was not installed — that is out of date. |
| SVN working copy | ✅ `~/workspace/bytephase-svn` | Checked out from `https://plugins.svn.wordpress.org/bytephase-connector` |
| WordPress.org credentials | ⚠️ not stored | Username + the password from **profiles.wordpress.org → Account & Security**. This is *not* your wordpress.org login password. SVN prompts on first commit. |
| `wp` CLI | ❌ not on the host | Run it in a container — see §3.3. |

If the SVN working copy is ever lost:

```bash
cd ~/workspace
svn checkout https://plugins.svn.wordpress.org/bytephase-connector bytephase-svn
```

Layout is `trunk/` (the live code), `tags/X.Y.Z/` (immutable snapshots), `assets/`
(banners, icons, screenshots — these live **only** in SVN, not in the git repo's root).

---

## 1. Bump the version — **four** places, all must match

Missing any one of these ships a listing that disagrees with itself. WordPress.org serves
whatever `Stable tag:` points at, so a mismatch can publish the *wrong build* silently.

| File | Line | Content |
|---|---|---|
| `bytephase-connector.php` | 7 | ` * Version:           X.Y.Z` |
| `bytephase-connector.php` | 23 | `define('BYTEPHASE_CONNECTOR_VERSION', 'X.Y.Z');` |
| `readme.txt` | 7 | `Stable tag: X.Y.Z` |
| `readme.txt` | — | new `== Changelog ==` and `== Upgrade Notice ==` entries |

Sanity check that nothing was missed:

```bash
cd ~/workspace/bytephase-wordpress-plugin
grep -rn "X\.Y\.Z" bytephase-connector.php readme.txt      # expect 4+ hits
grep -rn "1\.0\.1" bytephase-connector.php readme.txt      # expect only old changelog entries
```

### Changelog style

Follow the existing entries — user-facing outcome first, not implementation:

```
= 1.0.1 =
* New: BytePhase → Forms lets Contact Form 7 and Elementor forms be sent as a Lead or a
  Self check-in, so a site can run both at once.
* Fix: a submission BytePhase rejected outright is now held for retry on the Health screen
  instead of being discarded.
```

`== Upgrade Notice ==` is a single sentence shown in the WP admin update prompt. Keep it to
why someone should update.

---

## 2. Header fields to review each release

Both files carry these; they are easy to leave stale:

- `Requires at least:` (currently 6.0)
- `Tested up to:` (currently 7.1) — **bump this every WordPress release**, or the listing
  shows an "untested with your version" warning
- `Requires PHP:` (currently 8.1)

---

## 3. Verify — the gates that must pass

Run all four. 1.0.1 shipped on 98 tests, clean phpcs/phpstan, and **0 ERROR rows** from
Plugin Check.

### 3.1 Tests, style, static analysis

```bash
cd ~/workspace/bytephase-wordpress-plugin
composer test        # phpunit
composer phpcs
composer phpstan
```

### 3.2 Plugin Check — the one WordPress.org actually enforces

Zero **ERROR** rows is the bar. WARNINGs are worth reading but do not block.

### 3.3 Regenerate the translation template

The `.pot` went stale once already (it sat unchanged from Aug 25 through the 1.0.1 work and
had to be regenerated at the last minute). Regenerate whenever any user-facing string
changed.

`wp` is not installed on the host, so run it against the source tree in a container:

```bash
cd ~/workspace/bytephase-wordpress-plugin
docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp -v "$PWD":/app -w /app wordpress:cli-php8.2 \
  wp i18n make-pot . languages/bytephase-connector.pot \
  --exclude=tests,docs,vendor,node_modules

git diff --stat languages/       # confirm it actually changed
```

### 3.4 Commit everything to git first

SVN should only ever receive code that is already committed to git. If the release goes
wrong, git is the record of what was published.

```bash
git add -A && git commit -m "chore(release): X.Y.Z"
git push
```

---

## 4. Sync into SVN trunk

```bash
cd ~/workspace/bytephase-svn
svn update          # ⚠️ ALWAYS. See "local copy drifts behind" in §7.

rsync -av --delete \
  --exclude-from=/home/nikhil/workspace/bytephase-wordpress-plugin/.distignore \
  --exclude='.svn' \
  /home/nikhil/workspace/bytephase-wordpress-plugin/ trunk/
```

**`--exclude='.svn'` is not optional.** `.distignore` does not list it, and `rsync --delete`
would otherwise destroy SVN's metadata and wreck the working copy.

`.distignore` keeps the release zip clean — it excludes `tests`, `docs`, `vendor`,
`composer.*`, `phpcs.xml.dist`, `phpstan.neon.dist`, `phpunit.xml.dist`, `.git*`,
`node_modules`, `README.md` (the GitHub readme, **not** `readme.txt`).

Trunk should end up with exactly: `assets/`, `languages/`, `src/`,
`bytephase-connector.php`, `readme.txt`, `uninstall.php`, `LICENSE`.

### Stage adds and deletes

`rsync` writes files but SVN does not track them until told:

```bash
svn status trunk                 # '?' = untracked new, '!' = missing/deleted

svn add --force trunk

# only if there are '!' rows:
svn rm $(svn status trunk | grep '^!' | awk '{print $2}')

svn status trunk                 # re-read: everything should now be A / M / D
```

### Commit trunk

```bash
svn commit -m "Release X.Y.Z"
```

---

## 5. Tag

Only after trunk is committed and correct.

```bash
svn cp trunk tags/X.Y.Z
svn commit -m "Tag X.Y.Z"
```

---

## 6. Tag in git, then verify live

```bash
cd ~/workspace/bytephase-wordpress-plugin
git tag vX.Y.Z
git push origin vX.Y.Z
```

Verify (the listing takes a few minutes to refresh):

```bash
curl -sI https://downloads.wordpress.org/plugin/bytephase-connector.zip | head -3
```

- Listing: <https://wordpress.org/plugins/bytephase-connector/>
- Confirm the version shown matches `Stable tag:`
- Download the zip and confirm it contains no `tests/`, `docs/`, or `vendor/`

---

## 7. Known traps

**Tags are immutable.** No amend, no delete, no re-point. A mistake costs a new version
number. This is why §3 runs before §5.

**`Stable tag:` is what actually gets served.** It is not derived from the plugin header.
If they disagree, WordPress.org serves the tag named in `readme.txt` — possibly an old one.

**The local SVN copy drifts behind.** After the 1.0.1 release the working copy sat at
`r3676076` while the repository was at `r3676080`. Always `svn update` first or the commit
conflicts.

**`assets/` lives only in SVN.** Banners, icons and screenshots are not in the git repo
root the same way. Do not let `rsync --delete` near `assets/` — the command in §4 targets
`trunk/` only, which is why it is written that way.

**The `.pot` goes stale silently.** Nothing fails if you forget; translators just get an
outdated template.

**Credentials are the wordpress.org *SVN* password**, from profiles.wordpress.org →
Account & Security. Not the site login.

---

## 8. Release history

| Version | SVN | Notes |
|---|---|---|
| 1.0.0 | `r3666368` trunk + `tags/1.0.0` | Initial release. Approved 10 Aug 2026; SVN sat empty until 27 Aug. |
| 1.0.1 | `r3676079` trunk + `r3676080` tag | Forms screen (CF7/Elementor → Lead or Self check-in); rejected submissions held for retry instead of discarded. Shipped on 98 tests, phpcs, phpstan, Plugin Check 0 ERROR, regenerated `.pot`. |

git tags `v1.0.0` and `v1.0.1` both exist and are pushed.

---

## 9. Quick reference

```bash
# 1. bump 4 version locations + changelog + upgrade notice
# 2. verify
composer test && composer phpcs && composer phpstan
docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp -v "$PWD":/app -w /app wordpress:cli-php8.2 \
  wp i18n make-pot . languages/bytephase-connector.pot --exclude=tests,docs,vendor,node_modules
git add -A && git commit -m "chore(release): X.Y.Z" && git push

# 3. publish
cd ~/workspace/bytephase-svn
svn update
rsync -av --delete \
  --exclude-from=/home/nikhil/workspace/bytephase-wordpress-plugin/.distignore \
  --exclude='.svn' \
  /home/nikhil/workspace/bytephase-wordpress-plugin/ trunk/
svn status trunk
svn add --force trunk
svn commit -m "Release X.Y.Z"
svn cp trunk tags/X.Y.Z
svn commit -m "Tag X.Y.Z"

# 4. git tag
cd ~/workspace/bytephase-wordpress-plugin
git tag vX.Y.Z && git push origin vX.Y.Z
```
