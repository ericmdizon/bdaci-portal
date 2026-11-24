# Tests

`e2e.py` is the end-to-end suite (271 checks). It runs against the local test site in the BDACI dev kit
(`bdaci-portal-dev-kit.zip`: WordPress on SQLite with seeded members, pages and documents). The site itself is not
kept in this repository because it is a full WordPress install.

1. Unzip the dev kit and copy `plugin/bdaci-portal-access` from this repository over
   `wp/wp-content/plugins/bdaci-portal-access` in the kit.
2. From the kit folder: `php -S localhost:8089 -t wp router.php`
3. In another terminal, from the kit folder: `S=$PWD python3 /path/to/this/repo/tests/e2e.py`

`router.php`, `wpcli.php` and `test-harness.php` (a must-use plugin that captures mail to `mail.log` and fakes the
Microsoft/Google token endpoints) are copies of the kit's harness files, kept here for reference. Test only: never put
`test-harness.php` on a real site.
