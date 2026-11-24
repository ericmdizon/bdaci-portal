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

## Install

Run `tools/build.sh`, then in WordPress go to Plugins › Add New › Upload Plugin and upload
`dist/bdaci-portal-access.zip` (choose "Replace current with uploaded" when updating). Then follow
`docs/SETUP-GUIDE.md`.

## Current version

2.9.1. See `plugin/bdaci-portal-access/readme.txt` for the full changelog.
