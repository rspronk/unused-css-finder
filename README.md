# Unused CSS Finder

A command-line PHP script that crawls your whole website and reports the CSS classes and ids that no page uses. Think PurgeCSS, but it finds the pages for you.

- **One file.** `unused_css.php`, no Composer, no dependencies.
- **Reads, never writes.** It only fetches pages. Your site and your CSS files are not changed.
- **Polite.** Follows `robots.txt` and `Crawl-delay`, with adjustable speed.
- **Resumable.** An interrupted crawl picks up where it stopped.

**Documentation, options and troubleshooting:** https://www.cssportal.com/unused-css-finder/unused-css-finder-cli.php

It also has an online report viewer there: drop the `report.json` file onto the page for an easy-to-read, searchable summary. The file is read in your browser and never uploaded.

## Requirements

- PHP 7.1 or newer, run from the command line (check with `php -v`)
- The `curl`, `dom` and `libxml` PHP extensions (enabled by default in most installs)

## Install

Download `unused_css.php` (or the zip from the [Releases](../../releases) page) and save it anywhere outside your public web folder, for example a `tools` folder.

## How to use

Open a terminal in the folder that holds the script and give it your site's address:

```bash
php unused_css.php https://example.com/
```

On Windows, if `php` is not recognised, use the full path to `php.exe`, for example `C:\wamp64\bin\php\php8.2.0\php.exe unused_css.php https://example.com/`.

When it finishes, look in the `unused-css-example.com` folder it created next to where you ran the command:

| File | Contents |
| --- | --- |
| `report.txt` | Readable summary: warnings, then unused classes and ids for each stylesheet, then totals |
| `report.json` | The same data for other tools, including every selector that would be removed |
| `purged/*.css` | Only with `--purge`. Cleaned copies of each stylesheet. Your originals are never touched |

### Common examples

```bash
# Also write cleaned copies of your stylesheets
php unused_css.php https://example.com/ --purge

# Keep any class starting with "btn-" or named "modal", and skip the blog
php unused_css.php https://example.com/ --safelist="btn-*" --safelist=modal --exclude=/blog/

# Go gently on a small server
php unused_css.php https://example.com/ --concurrency=1 --delay=500

# Use a saved settings file
php unused_css.php --config=unused-css.json
```

If a crawl is interrupted, run the same command again to resume. Add `--fresh` to start over.

## Options

| Option | Default | What it does |
| --- | --- | --- |
| `<url>` | required | The page to start from, usually your home page |
| `--output=DIR` | `./unused-css-<host>` | Folder for the reports and saved progress |
| `--purge` | off | Also write cleaned copies of every stylesheet to `DIR/purged/` |
| `--max-pages=N` | 5000 | Stop after N pages; `0` means no limit |
| `--concurrency=N` | 4 | Pages fetched at the same time (1-20) |
| `--delay=MS` | 0 | Minimum pause between requests, in milliseconds |
| `--timeout=SEC` | 20 | How long to wait for one request (1-120) |
| `--keep-query` | off | Treat `/page?a=1` and `/page?a=2` as different pages |
| `--exclude=PATTERN` | none | Do not crawl URLs that match (repeatable) |
| `--safelist=PATTERN` | none | Always keep matching class names (repeatable) |
| `--safelist-id=PATTERN` | none | Always keep matching ids (repeatable) |
| `--no-default-safelist` | off | Stop auto-keeping `active`, `show`, `open`, `is-*`, `has-*`, `js-*` and similar |
| `--no-js-prefixes` | off | Stop treating strings like `'btn-'` in scripts as class prefixes |
| `--external-css` | off | Also analyse stylesheets hosted on other domains |
| `--external-js` | off | Also read scripts hosted on other domains |
| `--no-robots` | off | Ignore robots.txt (only on a site you own) |
| `--insecure` | off | Skip SSL certificate checks |
| `--user-agent=TEXT` | `UnusedCssFinder/1.0` | The name the crawler sends |
| `--fresh` | off | Ignore saved progress and start again |
| `--config=FILE` | none | Read options from a JSON file (the command line wins) |
| `--help`, `--version` | | Show help or the version |

### Patterns

- A plain value may use `*` as a wildcard: `btn-*`, `*-icon`, `modal`
- Start a value with `re:` for a regular expression: `re:^col-(sm|md)-\d+$` (use `re:(?i)^promo` for case-insensitive)
- `--exclude` matches anywhere in the full URL. `--safelist` and `--safelist-id` must match the whole name.
- Put patterns containing `*`, `(`, `|` or `$` in quotes so your shell leaves them alone.

### Settings file

```json
{
  "url": "https://example.com/",
  "max-pages": 2000,
  "delay": 250,
  "exclude": ["/blog/", "/forum/*"],
  "safelist": ["btn-*", "modal", "re:^col-(sm|md|lg)-\\d+$"],
  "safelist-id": ["cookie-banner"],
  "purge": true
}
```

Run it with `php unused_css.php --config=unused-css.json`.

## Limitations

This is a careful estimate, not proof. Always review the report before deleting any CSS.

- **JavaScript is not run.** Content built in the browser (React, Vue, AJAX, infinite scroll) is invisible to it.
- **Only linked pages are found.** It does not log in, submit forms or read your sitemap.
- **States that are not showing** (error messages, banners, A/B variants) may be missed. Safelist them.
- **Selectors are not matched against page structure.** `.menu .item` is kept if both classes appear anywhere on the site.
- **Only classes and ids are examined.** Tag and attribute selectors, `:not()`/`:is()`/`:where()`/`:has()` arguments and native CSS nesting are not analysed.

The full list is in the [online documentation](https://www.cssportal.com/unused-css-finder/unused-css-finder-cli.php).

## Responsible use

Only scan sites you own or have permission to test, and pick a speed your server can handle.

## Contributing

Bug reports and suggestions are welcome through [Issues](../../issues). Please include your PHP version (`php -v`), the command you ran, and any warnings from the report. Replies are not guaranteed.

## Licence

MIT. See [LICENSE](LICENSE). The software is provided "as is", without warranty of any kind.
