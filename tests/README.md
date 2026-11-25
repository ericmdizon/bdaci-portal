# Tests

`e2e.py` is the end-to-end suite (341 checks on a fresh kit; 335 when the plugin folder is a symlink, which skips the real "update now" install). It runs against the local test site in the BDACI dev kit
(`bdaci-portal-dev-kit.zip`: WordPress on SQLite with seeded members, pages and documents). The site itself is not
kept in this repository because it is a full WordPress install.

1. Unzip the dev kit and copy `plugin/bdaci-portal-access` from this repository over
   `wp/wp-content/plugins/bdaci-portal-access` in the kit.
2. From the kit folder: `php -S localhost:8089 -t wp router.php`
3. In another terminal, from the kit folder: `S=$PWD python3 /path/to/this/repo/tests/e2e.py`

`router.php`, `wpcli.php` and `test-harness.php` (a must-use plugin that captures mail to `mail.log` and fakes the
Microsoft/Google token endpoints) are copies of the kit's harness files, kept here for reference. Test only: never put
`test-harness.php` on a real site.

`test-github.php` is another must-use plugin for the kit (`wp/wp-content/mu-plugins/`). It fakes the GitHub releases API
so the updater can be tested offline (option `test_fake_gh`), and keeps WordPress.org offline. Test only as well.

## Break tests and browser checks

- `break_tests.py` (51 checks) tries to break the QA Testing page and document rename/move: forged and malformed posts,
  members without access, script injection in notes and names, spreadsheet-formula injection in the CSV, disguised and
  oversized screenshots, tampered screenshot paths, file-name collisions and path tricks.
  Run it after `e2e.py`: `python3 break_tests.py /path/to/this/tests /path/to/kit/wp`
- `browser_qa.js` (30 checks) clicks through the QA page in Chromium at desktop and phone widths: pasting and dropping
  screenshots, size limits, Save note only, filters, the Open buttons and section search.
  Needs Playwright and an admin cookie file (written by the kit's screenshot helper).
- `viewer_qa.js` (44 checks) opens documents in the pop-up viewer in Chromium at desktop and phone widths: expand,
  print and download buttons, the folded sign-off bar, the warning when closing without signing, typed and drawn
  signatures, Excel files offering a download, and signing a sign-off pack from inside the viewer.
  `node viewer_qa.js <kit folder> <board cookie file> <PDF document id> <Excel document id>`; both documents must have
  "Ask people to sign off" ticked.
