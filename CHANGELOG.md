# Changelog

## 1.1.2
- Fixed the "curl_close() is deprecated" notice on PHP 8.5. The script no longer calls it on PHP 8.0 and newer, where it did nothing.

## 1.1.1
- With `--source`, only the `.css` files that a source file refers to (a `<link>`, `@import`, script or enqueue call) are checked. Spare or old stylesheets are left out and listed in the report (`unlinkedCss`). New `--include-unlinked-css` option to check every `.css` file.

## 1.1.0
- New `--source=DIR` option: read the source files (templates, scripts) in a folder for class and id names. Covers areas a crawler cannot reach, such as logins and members areas. Works on its own (the `.css` files in the folder are checked) or together with a crawl.
- New `--source-ext`, `--source-exclude`, `--no-default-source-excludes` and `--local-css` options.
- The web address is now optional when `--source` is given.
- Class and id names written in non-English alphabets are now matched correctly in scripts and source files.
- `report.json` gains `sourceFiles` and `sourceDirs`.

## 1.0.4
- `report.json` now lists each removed selector once. Previously a selector defined in several rules was listed once per rule. The "unused selectors" count still counts every rule that would be removed.

## 1.0.0 - 1.0.3
- Initial releases: site crawler, CSS parser, safelists, `--purge`, resumable crawls, `report.txt` and `report.json`.
