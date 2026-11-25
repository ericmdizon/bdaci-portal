# BDACI Board & Committee Portal

WordPress plugin that turns bdaci.com's board and committee area into a private portal: sign-in (email + password with
an emailed code, Microsoft 365, Google), roles (Board Member, Education Committee Member, Finance Committee Member,
Portal Admin), private documents by section, meetings and RSVP, sign-offs with an audit trail, access requests,
profiles, and an internal QA Testing page.

**Private repository. Internal only: do not share with the client.**

## Layout

| Folder | What it is |
| --- | --- |
| `plugin/bdaci-portal-access/` | The plugin source. `readme.txt` has the changelog. |
| `tests/` | End-to-end test suite and test-site harness files. See `tests/README.md`. |
| `docs/SETUP-GUIDE.md` | Install and admin guide. |
| `docs/preview/` | The clickable design preview page (sample data only). |
| `tools/build.sh` | Builds `dist/bdaci-portal-access.zip` for upload in WordPress. |
| `.github/workflows/release.yml` | Publishes a release with the zip when a new version reaches `main`. |

## Install

Run `tools/build.sh`, then in WordPress go to Plugins › Add New › Upload Plugin and upload
`dist/bdaci-portal-access.zip` (choose "Replace current with uploaded" when updating). Then follow
`docs/SETUP-GUIDE.md`.

## Releases and updates

From 2.10.0 the plugin updates itself from this repository's GitHub releases (Settings › Updates needs a read-only
fine-grained access key; see SETUP-GUIDE section 9d). To ship a version: raise the version in the plugin header and
`BDACI_PA_VERSION`, add a `readme.txt` changelog entry, and merge to `main`. The release workflow builds the zip and
publishes release `vX.Y.Z`; sites pick it up at their next check.

## Current version

2.10.0. See `plugin/bdaci-portal-access/readme.txt` for the full changelog.
