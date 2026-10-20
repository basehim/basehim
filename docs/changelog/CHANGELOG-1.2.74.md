# Basehim 1.2.74

This release fixes BH-018: docs and tests never reached GitHub.

**Database:** no changes.

**Installs on:** 1.2.73. The patch is cumulative from 1.2.67.

**Pair it with CloudHim 1.3.4 on the hub.**

## The problem

`.gitattributes` marked `/docs` and `/tests` `export-ignore`, to keep them out of the download zip.

CloudHim's release sync (1.3.2+) treats every `export-ignore` path as the repository's own, and never writes it. So no changelog, guide or test written since then reached basehim/basehim. Missing on GitHub:
- the changelogs for 1.2.58–1.2.73;
- about a dozen test files.

The download zips had no docs either. `.gitattributes` was itself `export-ignore`, so a release couldn't change the rules.

## The change

`.gitattributes` now separates "in the download" from "written by a sync", using the `basehim-sync` attribute that CloudHim 1.3.4 understands.

| Path | Download zip | Release sync |
|---|---|---|
| `/docs` | **included** | written when a release ships it; never deleted by a sync |
| `/docs/audits` | left out | never written (internal security audit) |
| `/tests` | left out | written when a release ships it; never deleted by a sync |
| `/.gitattributes` | left out | written from the release, so these rules reach the repository |
| `/.github`, `/.gitignore`, `/CONTRIBUTING.md` | left out | never written, as before |
| Retired files (`/CHANGELOG-*.md`, `/RELEASE-NOTES-*.md`, `CategoryPostController.php`) | left out | never written, as before |

## Rolling it out

1. **Hub:** CloudHim 1.3.4 is installed on cloudhim.com (done through Dev Tools, health-checked).
2. **Upload:** upload this patch to CloudHim and publish it.
3. **Update:** update cloudhim.com to 1.2.74. A release is pushed only from a site that runs it.
4. **Dry run:** in CloudHim → GitHub, run a dry run of 1.2.74. The plan should say it uses *the release's .gitattributes*, and list:
   - `docs/…` and `tests/…` files to add;
   - `.gitattributes` to modify;
   - no deletions under `docs/` or `tests/`.
5. **Push:** push it. The release package built from that commit contains `docs/` and not `tests/`.

## Files

- `.gitattributes`
- `index.php` (1.2.74)
- `CHANGELOG.md`
- `docs/changelog/CHANGELOG-1.2.74.md` and `RELEASE-NOTES-1.2.74.md`

## Checked

- **`git archive`** of the tree with the new `.gitattributes`: 164 files under `docs/`, nothing from `tests/` or `docs/audits/`.
- **CloudHim's matchers** against this file:
  - `docs/…`, `tests/…` and `.gitattributes` are written and kept;
  - `docs/audits/…`, `.github/…`, `CONTRIBUTING.md` and root `CHANGELOG-*.md` are left alone;
  - core files sync as before.
- **CloudHim 1.3.4's sync against a local repository** with the old rules:
  - docs and tests were added;
  - `.gitattributes` was updated;
  - retired files were untouched;
  - a dropped file was deleted;
  - the package had docs and no tests.
