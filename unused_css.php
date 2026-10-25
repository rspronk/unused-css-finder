<?php
declare(strict_types=1);

// Command line only - this tool must never be run from a web request.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This tool must be run from the command line.');
}

/**
 * Unused CSS Finder - find CSS classes and ids that no page on your site uses.
 *
 * Copyright (c) 2026 www.cssportal.com
 * SPDX-License-Identifier: MIT (see the LICENSE file that came with this script)
 * Documentation: https://www.cssportal.com/unused-css-finder/unused-css-finder-cli.php
 *
 * Requires PHP 7.1+ with the curl, dom and libxml extensions.
 *
 *   php unused_css.php https://example.com/
 *   php unused_css.php https://example.com/ --purge
 *   php unused_css.php --help
 *
 * It crawls the site (following internal links only), collects every class, id and
 * stylesheet it finds, then reports which selectors in your CSS can never match.
 * Run with --help for every option.
 */

const VERSION = '1.1.2';

// Classes that are normally added by JavaScript at runtime and so are always kept.
// Disable with --no-default-safelist.
const DEFAULT_SAFE_CLASSES = [
    '~^(active|show|open|hidden|visible|disabled|selected|fade|collapse|collapsing)$~',
    '~^(is|has|js)-~',
];

// File types read by --source unless --source-ext says otherwise (stylesheets are never read as source).
const DEFAULT_SOURCE_EXT = ['html', 'htm', 'xhtml', 'php', 'phtml', 'inc', 'twig', 'tpl', 'js', 'mjs', 'jsx',
                            'ts', 'tsx', 'vue', 'svelte', 'erb', 'ejs', 'hbs', 'handlebars', 'njk', 'liquid',
                            'asp', 'aspx', 'cshtml', 'jsp', 'md'];

// Folders that --source skips, wherever they appear. Disable with --no-default-source-excludes.
const DEFAULT_SOURCE_SKIP_DIRS = ['node_modules', 'vendor', 'bower_components', '.git', '.svn', '.hg', '.idea', '.vscode'];

foreach (['curl', 'dom', 'libxml', 'pcre'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "Error: the PHP \"$ext\" extension is required but not enabled.\n");
        exit(1);
    }
}

ini_set('memory_limit', '1G');
libxml_use_internal_errors(true);

// ---------------------- Settings (filled from the command line) ----------------------

final class Cfg
{
    public static $startUrl = '';
    public static $host = '';
    public static $scheme = 'https';
    public static $port = '';
    public static $outputDir = '';
    public static $stateFile = '';
    public static $concurrency = 4;
    public static $delayMs = 0;
    public static $timeout = 20;
    public static $maxPages = 5000;
    public static $userAgent = 'UnusedCssFinder/1.0';
    public static $respectRobots = true;
    public static $verifySsl = true;
    public static $stripQuery = true;
    public static $purge = false;
    public static $fresh = false;
    public static $externalCss = false;
    public static $externalJs = false;
    public static $jsPrefixes = true;
    public static $exclude = [];
    public static $safeClasses = [];
    public static $safeIds = [];
    public static $crawl = true;               // false when only --source is given
    public static $sourceDirs = [];
    public static $sourceExts = [];
    public static $sourceExclude = [];
    public static $defaultSourceExcludes = true;
    public static $localCss = false;
    public static $includeUnlinkedCss = false;
    public static $ignoreParams = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term',
                                   'utm_content', 'gclid', 'fbclid', 'PHPSESSID', 'sessionid'];
    public static $skipExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'pdf', 'zip',
                              'gz', 'rar', 'mp3', 'mp4', 'avi', 'mov', 'webm', 'css', 'js', 'json',
                              'xml', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'woff',
                              'woff2', 'ttf', 'eot', 'map'];
}

// ---------------------- Command line ----------------------

function fail(string $message): void
{
    fwrite(STDERR, "Error: $message\n");
    exit(1);
}

function usageText(): string
{
    return "Unused CSS Finder " . VERSION . "\n" . <<<'TXT'

Finds CSS classes and ids that are not used anywhere on a website.

USAGE
  php unused_css.php <url> [options]
  php unused_css.php --source=FOLDER [options]

EXAMPLES
  php unused_css.php https://example.com/
  php unused_css.php --source=/var/www/example
  php unused_css.php https://example.com/ --source=/var/www/example
  php unused_css.php https://example.com/ --purge
  php unused_css.php https://example.com/ --safelist="btn-*" --safelist=modal --exclude=/blog/
  php unused_css.php --config=unused-css.json

OPTIONS
  --output=DIR          Where reports are written (default: ./unused-css-<host>)
  --purge               Also write cleaned copies of every stylesheet to DIR/purged/
  --max-pages=N         Stop after N pages; 0 = no limit (default: 5000)
  --concurrency=N       Parallel requests, 1-20 (default: 4)
  --delay=MS            Minimum pause between requests in milliseconds (default: 0)
  --timeout=SEC         Seconds before a request gives up (default: 20)
  --keep-query          Treat /page?a=1 and /page?a=2 as different pages
                        (default: query strings are ignored)
  --exclude=PATTERN     Do not crawl URLs matching PATTERN (repeatable)
  --safelist=PATTERN    Always keep classes matching PATTERN (repeatable)
  --safelist-id=PATTERN Always keep ids matching PATTERN (repeatable)
  --no-default-safelist Do not auto-keep active, show, open, is-*, has-*, js-* ...
  --no-js-prefixes      Do not treat strings like 'btn-' in JavaScript as class prefixes
  --external-css        Also analyse stylesheets hosted on other domains (CDNs)
  --external-js         Also read scripts hosted on other domains for class names
  --no-robots           Ignore robots.txt (only do this on a site you own)
  --insecure            Do not verify SSL certificates
  --user-agent=STRING   Change the User-Agent header
  --fresh               Ignore saved progress and start the crawl again
  --config=FILE         Read options from a JSON file (command line wins)
  --help, --version

SOURCE FILES (for areas a crawler cannot reach, such as logins and members areas)
  --source=DIR          Also read the source files in DIR for class and id names (repeatable).
                        With no web address, the .css files in DIR are checked as well.
  --source-ext=LIST     File types to read, comma separated
                        (default: html,htm,php,phtml,twig,js,jsx,ts,tsx,vue,svelte and more)
  --source-exclude=PAT  Skip source paths matching PAT, measured from DIR (repeatable)
  --no-default-source-excludes
                        Also read node_modules, vendor, .git and similar folders
  --local-css           With a web address, also check the .css files found in --source folders
  --include-unlinked-css
                        Check every .css file in the --source folders. By default only the ones
                        that a source file refers to (a <link>, an @import, a script) are checked.

PATTERNS
  A plain value may use * as a wildcard:  btn-*   *-icon   modal
  Start a value with re: for a regular expression:  re:^col-(sm|md)-\d+$
  (use re:(?i)^promo for a case-insensitive match)
  --exclude patterns are matched against the full URL, anywhere in it.
  --source-exclude patterns are matched against the path inside the source folder,
  written with forward slashes and starting with /, for example  /tests/  or  .min.js
  --safelist patterns must match the whole class or id name.

CONFIG FILE
  JSON using the option names without the leading dashes, for example:
  {"url": "https://example.com/", "max-pages": 2000, "safelist": ["btn-*"], "purge": true}

Progress is saved while crawling. If a run is interrupted, run the same command
again to resume, or add --fresh to start over.

TXT;
}

function parseArgs(array $argv): array
{
    $valueOpts = ['output', 'concurrency', 'delay', 'max-pages', 'timeout', 'exclude', 'safelist',
                  'safelist-id', 'user-agent', 'config', 'url', 'source', 'source-ext', 'source-exclude'];
    $flagOpts  = ['purge', 'keep-query', 'external-css', 'external-js', 'no-robots', 'insecure',
                  'fresh', 'no-default-safelist', 'no-js-prefixes', 'help', 'version', 'local-css',
                  'no-default-source-excludes', 'include-unlinked-css'];
    $repeat    = ['exclude', 'safelist', 'safelist-id', 'source', 'source-exclude'];

    $o = ['_' => []];
    $n = count($argv);
    for ($i = 1; $i < $n; $i++) {
        $a = $argv[$i];
        if ($a === '-h') {
            $a = '--help';
        }
        if (substr($a, 0, 2) !== '--') {
            $o['_'][] = $a;
            continue;
        }
        $name = substr($a, 2);
        $val  = null;
        if (($p = strpos($name, '=')) !== false) {
            $val  = substr($name, $p + 1);
            $name = substr($name, 0, $p);
        }
        if (in_array($name, $flagOpts, true)) {
            if ($val !== null) {
                fail("Option --$name does not take a value.");
            }
            $o[$name] = true;
            continue;
        }
        if (!in_array($name, $valueOpts, true)) {
            fail("Unknown option --$name (run with --help to see the options).");
        }
        if ($val === null) {
            if (isset($argv[$i + 1]) && substr($argv[$i + 1], 0, 2) !== '--') {
                $val = $argv[++$i];
            } else {
                fail("Option --$name needs a value.");
            }
        }
        if (in_array($name, $repeat, true)) {
            $o[$name][] = $val;
        } else {
            $o[$name] = $val;
        }
    }
    return $o;
}

function mergeConfigFile(array $o, string $file): array
{
    if (!is_file($file)) {
        fail("Config file not found: $file");
    }
    $json = json_decode((string) file_get_contents($file), true);
    if (!is_array($json)) {
        fail("Config file is not valid JSON: $file");
    }
    foreach ($json as $key => $value) {
        if ($key === '_' || isset($o[$key])) {
            continue; // the command line wins
        }
        if (in_array($key, ['exclude', 'safelist', 'safelist-id', 'source', 'source-exclude'], true)) {
            $o[$key] = (array) $value;
        } elseif ($value !== false && $value !== null) {
            $o[$key] = $value;
        }
    }
    return $o;
}

function intOpt(array $o, string $name, int $default, int $min, int $max): int
{
    if (!isset($o[$name])) {
        return $default;
    }
    if (!preg_match('/^\d+$/', (string) $o[$name])) {
        fail("--$name must be a whole number.");
    }
    $v = (int) $o[$name];
    if ($v < $min || $v > $max) {
        fail("--$name must be between $min and $max.");
    }
    return $v;
}

function listOpt(array $o, string $name): array
{
    return isset($o[$name]) ? array_values(array_map('strval', (array) $o[$name])) : [];
}

/** Turns a user pattern (glob with * or re:REGEX) into a PCRE pattern. */
function patternToRegex(string $v, bool $anchored): string
{
    if (strpos($v, 're:') === 0) {
        $re = '~' . str_replace('~', '\~', substr($v, 3)) . '~';
    } else {
        $core = str_replace('\*', '.*', preg_quote($v, '~'));
        $re   = '~' . ($anchored ? '^' : '') . $core . ($anchored ? '$' : '') . '~';
    }
    if (@preg_match($re, '') === false) {
        fail("Invalid pattern: $v");
    }
    return $re;
}

function configure(array $o): void
{
    $url = (string) (isset($o['url']) ? $o['url'] : (isset($o['_'][0]) ? $o['_'][0] : ''));
    $sources = listOpt($o, 'source');
    if ($url === '' && !$sources) {
        fail("Please give the address of the site to scan, e.g.  php unused_css.php https://example.com/\n"
            . "Or the folder holding your website files:  php unused_css.php --source=/path/to/site");
    }
    if ($url !== '' && !preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url) && is_dir($url)
        && preg_match('#[\\\\/]|^\.#', $url)) {
        fail("\"$url\" is a folder. To check the files in a folder use  --source=\"$url\"  "
            . "(add a web address as well if you also want to crawl a site).");
    }
    Cfg::$crawl = ($url !== '');
    if (Cfg::$crawl) {
        if (!preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url)) {
            $url = 'https://' . $url;
        }
        $p = parse_url($url);
        if (!$p || empty($p['host']) || !in_array(strtolower((string) $p['scheme']), ['http', 'https'], true)) {
            fail("That doesn't look like a valid http(s) address: $url");
        }
        Cfg::$startUrl = $url;
        Cfg::$host     = strtolower($p['host']);
        Cfg::$scheme   = strtolower($p['scheme']);
        Cfg::$port     = isset($p['port']) ? ':' . $p['port'] : '';
    }

    foreach ($sources as $dir) {
        $real = is_dir($dir) ? realpath($dir) : false;
        if ($real === false) {
            fail("Source folder not found: $dir");
        }
        if (!in_array($real, Cfg::$sourceDirs, true)) {
            Cfg::$sourceDirs[] = $real;
        }
    }
    $exts = [];
    if (isset($o['source-ext'])) {
        foreach ((array) $o['source-ext'] as $chunk) {
            foreach (explode(',', (string) $chunk) as $e) {
                $e = strtolower(ltrim(trim($e), '.'));
                if ($e !== '' && $e !== 'css') {
                    $exts[$e] = true;
                }
            }
        }
        if (!$exts) {
            fail('--source-ext needs at least one file type, for example --source-ext=php,html,js');
        }
        Cfg::$sourceExts = array_keys($exts);
    } else {
        Cfg::$sourceExts = DEFAULT_SOURCE_EXT;
    }
    foreach (listOpt($o, 'source-exclude') as $v) {
        Cfg::$sourceExclude[] = patternToRegex($v, false);
    }
    Cfg::$defaultSourceExcludes = empty($o['no-default-source-excludes']);
    Cfg::$localCss = !empty($o['local-css']) || !Cfg::$crawl;
    Cfg::$includeUnlinkedCss = !empty($o['include-unlinked-css']);

    Cfg::$concurrency   = intOpt($o, 'concurrency', 4, 1, 20);
    Cfg::$delayMs       = intOpt($o, 'delay', 0, 0, 60000);
    Cfg::$timeout       = intOpt($o, 'timeout', 20, 1, 120);
    Cfg::$maxPages      = intOpt($o, 'max-pages', 5000, 0, 10000000);
    Cfg::$userAgent     = isset($o['user-agent']) ? (string) $o['user-agent'] : Cfg::$userAgent;
    Cfg::$respectRobots = empty($o['no-robots']);
    Cfg::$verifySsl     = empty($o['insecure']);
    Cfg::$stripQuery    = empty($o['keep-query']);
    Cfg::$purge         = !empty($o['purge']);
    Cfg::$fresh         = !empty($o['fresh']);
    Cfg::$externalCss   = !empty($o['external-css']);
    Cfg::$externalJs    = !empty($o['external-js']);
    Cfg::$jsPrefixes    = empty($o['no-js-prefixes']);

    foreach (listOpt($o, 'exclude') as $v) {
        Cfg::$exclude[] = patternToRegex($v, false);
    }
    if (empty($o['no-default-safelist'])) {
        Cfg::$safeClasses = DEFAULT_SAFE_CLASSES;
    }
    foreach (listOpt($o, 'safelist') as $v) {
        Cfg::$safeClasses[] = patternToRegex($v, true);
    }
    foreach (listOpt($o, 'safelist-id') as $v) {
        Cfg::$safeIds[] = patternToRegex($v, true);
    }

    if (isset($o['output'])) {
        Cfg::$outputDir = rtrim((string) $o['output'], '/\\');
    } else {
        $cwd  = getcwd();
        $label = Cfg::$crawl ? Cfg::$host : strtolower(basename(Cfg::$sourceDirs[0]));
        Cfg::$outputDir = ($cwd === false ? '.' : $cwd) . '/unused-css-' . trim((string) preg_replace('/[^a-z0-9.\-]+/', '_', $label), '_');
    }
    Cfg::$stateFile = Cfg::$outputDir . '/crawl-state.dat';
}

// ---------------------- URL helpers ----------------------

function stripWww(string $host): string
{
    return strpos($host, 'www.') === 0 ? substr($host, 4) : $host;
}

/** example.com and www.example.com count as the same site. */
function sameSite(string $host): bool
{
    return stripWww(strtolower($host)) === stripWww(Cfg::$host);
}

function normalizeUrl(string $url): ?string
{
    $url = str_replace(' ', '%20', trim($url));
    $p = parse_url($url);
    if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)) {
        return null;
    }
    $host   = strtolower($p['host']);
    $scheme = strtolower($p['scheme']);
    if (sameSite($host)) {
        $host   = Cfg::$host;
        $scheme = Cfg::$scheme;
    }
    $port = '';
    if (isset($p['port']) && !(($scheme === 'http' && $p['port'] === 80) || ($scheme === 'https' && $p['port'] === 443))) {
        $port = ':' . $p['port'];
    }
    $path = ($p['path'] ?? '') === '' ? '/' : $p['path'];

    $query = '';
    if (!Cfg::$stripQuery && isset($p['query']) && $p['query'] !== '') {
        $parts = array_filter(explode('&', $p['query']), function ($kv) {
            $key = urldecode(explode('=', $kv, 2)[0]);
            return $kv !== '' && !in_array($key, Cfg::$ignoreParams, true);
        });
        if ($parts) {
            $query = '?' . implode('&', $parts);
        }
    }
    return "$scheme://$host$port$path$query"; // fragment (#...) is dropped
}

function removeDotSegments(string $path): string
{
    $query = '';
    if (($q = strpos($path, '?')) !== false) {
        $query = substr($path, $q);
        $path  = substr($path, 0, $q);
    }
    $out = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '..') {
            if (count($out) > 1) {
                array_pop($out);
            }
        } elseif ($seg !== '.') {
            $out[] = $seg;
        }
    }
    return implode('/', $out) . $query;
}

function resolveUrl(string $href, string $base): ?string
{
    $href = trim($href);
    if ($href === '' || $href[0] === '#') {
        return null;
    }
    if (preg_match('#^(javascript|mailto|tel|data|sms|ftp|whatsapp|blob):#i', $href)) {
        return null;
    }
    $b = parse_url($base);
    if (!$b || empty($b['host'])) {
        return null;
    }
    $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');

    if (preg_match('#^https?://#i', $href)) {
        return $href;
    }
    if (substr($href, 0, 2) === '//') {
        return $b['scheme'] . ':' . $href;
    }
    if ($href[0] === '/') {
        return $origin . $href;
    }
    $path = $b['path'] ?? '/';
    if ($href[0] === '?') {
        return $origin . $path . $href;
    }
    $dir = substr($path, 0, strrpos($path, '/') + 1);
    return $origin . removeDotSegments($dir . $href);
}

function isInternal(string $url): bool
{
    return sameSite((string) parse_url($url, PHP_URL_HOST));
}

function isExcluded(string $url): bool
{
    $path = (string) parse_url($url, PHP_URL_PATH);
    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext !== '' && in_array($ext, Cfg::$skipExt, true)) {
        return true;
    }
    foreach (Cfg::$exclude as $re) {
        if (preg_match($re, $url)) {
            return true;
        }
    }
    return false;
}

// ---------------------- HTTP helpers ----------------------

function handleId($ch): string
{
    // PHP 7 curl handles are resources; PHP 8 uses CurlHandle objects.
    return is_object($ch) ? spl_object_hash($ch) : (string) (int) $ch;
}

function baseCurlOptions(): array
{
    return [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => Cfg::$timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => Cfg::$userAgent,
        CURLOPT_ENCODING       => '',
        CURLOPT_SSL_VERIFYPEER => Cfg::$verifySsl,
        CURLOPT_SSL_VERIFYHOST => Cfg::$verifySsl ? 2 : 0,
    ];
}

/**
 * Releases a curl handle. From PHP 8.0 handles are objects that free themselves, and curl_close()
 * is deprecated in PHP 8.5, so it is only called on older versions.
 */
function closeCurl($ch): void
{
    if (PHP_VERSION_ID < 80000) {
        curl_close($ch);
    }
}

/** Blocking GET used for stylesheets / scripts / robots.txt. Returns null on failure. */
function fetchText(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, baseCurlOptions() + [CURLOPT_MAXFILESIZE => 20 * 1024 * 1024]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    closeCurl($ch);
    return (is_string($body) && $code === 200) ? $body : null;
}

/** Fetches the start page once, follows redirects (http->https, example.com->www...) and adopts the final host. */
function resolveStartUrl(): void
{
    $ch = curl_init(Cfg::$startUrl);
    curl_setopt_array($ch, baseCurlOptions() + [CURLOPT_MAXFILESIZE => 5 * 1024 * 1024]);
    curl_exec($ch);
    $errno = curl_errno($ch);
    $err   = curl_error($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $eff   = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    closeCurl($ch);

    if ($errno !== 0) {
        $hint = in_array($errno, [35, 51, 58, 59, 60, 77, 83], true)
            ? "\nThis looks like an SSL certificate problem. If you trust the site, add --insecure."
            : '';
        fail("Could not open " . Cfg::$startUrl . " ($err)." . $hint);
    }
    if ($code >= 400 || $code === 0) {
        fail("The start page returned HTTP $code. Check the address, or whether the site blocks automated requests.");
    }
    $p = parse_url($eff);
    if ($p && !empty($p['host'])) {
        $newHost = strtolower($p['host']);
        if (!sameSite($newHost)) {
            echo "Note: " . Cfg::$host . " redirects to $newHost - crawling $newHost instead.\n";
        }
        Cfg::$host   = $newHost;
        Cfg::$scheme = strtolower((string) $p['scheme']);
        Cfg::$port   = isset($p['port']) ? ':' . $p['port'] : '';
    }
    Cfg::$startUrl = $eff;
}

/** @return string[] regexes for Disallow rules that apply to every crawler */
function loadRobotsDisallows(): array
{
    if (!Cfg::$respectRobots) {
        return [];
    }
    $txt = fetchText(Cfg::$scheme . '://' . Cfg::$host . Cfg::$port . '/robots.txt');
    if ($txt === null) {
        return [];
    }
    $rules   = [];
    $applies = false;
    foreach (preg_split('/\r?\n/', $txt) as $line) {
        $line = trim((string) preg_replace('/#.*/', '', $line));
        if (stripos($line, 'user-agent:') === 0) {
            $applies = trim(substr($line, 11)) === '*';
        } elseif ($applies && stripos($line, 'disallow:') === 0) {
            $path = trim(substr($line, 9));
            if ($path !== '') {
                $rules[] = '#^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($path, '#')) . '#';
            }
        } elseif ($applies && stripos($line, 'crawl-delay:') === 0) {
            $secs = (float) trim(substr($line, 12));
            if ($secs > 0) {
                $ms = (int) min(10000, $secs * 1000);
                if ($ms > Cfg::$delayMs) {
                    Cfg::$delayMs = $ms;
                    echo "robots.txt asks for a crawl delay of {$secs}s - slowing down to match.\n";
                }
            }
        }
    }
    return $rules;
}

// ---------------------- Token harvesting ----------------------

/** Adds every word-like token in $text (and the parts of "a:b" tokens) to $set. */
function addTokens(array &$set, string $text): void
{
    // Bytes 0x80-0xFF are included so class names written in other alphabets stay in one piece.
    if (!preg_match_all('/[A-Za-z0-9_\-:\x80-\xFF]+/', $text, $m)) {
        return;
    }
    foreach ($m[0] as $t) {
        $set[$t] = true;
        if (strpos($t, ':') !== false) {
            foreach (explode(':', $t) as $part) {
                if ($part !== '') {
                    $set[$part] = true;
                }
            }
        }
    }
}

// ---------------------- Source files (--source) ----------------------

/**
 * Reads the files in the --source folders. Every word in a source file counts as "used", the same
 * way words in scripts do for a crawl. Also lists the .css files found when they are to be analysed.
 *
 * @return array{files:int, skipped:int, css:array, refs:array}
 */
function scanSources(array &$tokens): array
{
    $out   = ['files' => 0, 'skipped' => 0, 'css' => [], 'refs' => []];
    $exts  = array_flip(Cfg::$sourceExts);
    $multi = count(Cfg::$sourceDirs) > 1;

    foreach (Cfg::$sourceDirs as $root) {
        $rootSlash = rtrim(str_replace('\\', '/', $root), '/');
        $dirs = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO);
        $filtered = new RecursiveCallbackFilterIterator($dirs, function ($cur) use ($rootSlash) {
            if ($cur->isDir() && Cfg::$defaultSourceExcludes
                && in_array(strtolower($cur->getFilename()), DEFAULT_SOURCE_SKIP_DIRS, true)) {
                return false;
            }
            if (Cfg::$sourceExclude) {
                $rel = '/' . ltrim(substr(str_replace('\\', '/', $cur->getPathname()), strlen($rootSlash)), '/');
                foreach (Cfg::$sourceExclude as $re) {
                    if (preg_match($re, $rel)) {
                        return false;
                    }
                }
            }
            return true;
        });
        $walk = new RecursiveIteratorIterator($filtered, RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD);

        foreach ($walk as $f) {
            if (!$f->isFile()) {
                continue;
            }
            $ext = strtolower((string) pathinfo($f->getFilename(), PATHINFO_EXTENSION));
            if ($ext === 'css' && Cfg::$localCss) {
                $real = realpath($f->getPathname());
                if ($real !== false) {
                    $rel = ltrim(substr(str_replace('\\', '/', $f->getPathname()), strlen($rootSlash)), '/');
                    $out['css'][$real] = ['label' => ($multi ? basename($root) . '/' : '') . $rel, 'rel' => $rel];
                }
                continue;
            }
            if (!isset($exts[$ext])) {
                continue;
            }
            $size = $f->getSize();
            if ($size === false || $size > 20 * 1024 * 1024) {
                $out['skipped']++;
                continue;
            }
            $text = @file_get_contents($f->getPathname());
            if ($text === false || strpos(substr($text, 0, 8000), "\0") !== false) {
                $out['skipped']++; // unreadable, or a binary file
                continue;
            }
            addTokens($tokens, $text);
            if (stripos($text, '.css') !== false) {
                cssReferences($out['refs'], $text);
            }
            $out['files']++;
        }
    }
    ksort($out['css']);
    return $out;
}

/** Collects the names of stylesheets mentioned in $text: <link href>, @import, script loaders, enqueue calls. */
function cssReferences(array &$refs, string $text): void
{
    if (!preg_match_all('~([^"\'()<>\s=,;]*\.css)(?=[?#"\'()\s>,;]|$)~i', $text, $m)) {
        return;
    }
    foreach ($m[1] as $r) {
        if (preg_match('~^([a-z][a-z0-9+.\-]*:|//)~i', $r)) {
            continue; // a web address (CDN), not one of the local files
        }
        $r = strtolower(ltrim((string) preg_replace('~^(\.{1,2}/)+~', '', str_replace('\\', '/', $r)), '/'));
        if (strlen($r) > 4) { // ignores a bare ".css" built from variables
            $refs[$r] = true;
        }
    }
}

/** True when a stylesheet at $rel (inside a source folder) is the one a reference points to. */
function cssIsLinked(string $rel, array $refs): bool
{
    $rel = strtolower(str_replace('\\', '/', $rel));
    foreach ($refs as $r => $_) {
        $r = (string) $r;
        if ($r === $rel || substr($rel, -strlen($r) - 1) === '/' . $r || substr($r, -strlen($rel) - 1) === '/' . $rel) {
            return true;
        }
    }
    return false;
}

// ---------------------- Crawler ----------------------

final class Crawler
{
    private $queue;
    private $seen = [];
    private $classes = [];   // class names seen in class="" attributes
    private $ids = [];       // ids seen in id="" attributes / referenced by #fragment, for=, aria-*
    private $tokens = [];    // words from inline JS and data-* attributes
    private $cssUrls = [];   // host+path => url
    private $jsUrls = [];
    private $inlineCss = []; // md5 => ['css' => ..., 'page' => ...]
    private $failures = [];  // url => reason (first 50)
    private $failed = 0;
    private $parsed = 0;
    private $done = 0;
    private $truncated = false;
    private $robots = [];

    public function __construct()
    {
        $this->robots = loadRobotsDisallows();
        $this->queue  = new SplQueue();

        $s = null;
        if (!Cfg::$fresh && is_file(Cfg::$stateFile)) {
            $s = @unserialize((string) file_get_contents(Cfg::$stateFile), ['allowed_classes' => ['SplQueue']]);
        }
        if (is_array($s) && isset($s['v'], $s['host']) && $s['v'] === 2 && $s['host'] === Cfg::$host
            && $s['queue'] instanceof SplQueue) {
            $this->queue     = $s['queue'];
            $this->seen      = $s['seen'];
            $this->classes   = $s['classes'];
            $this->ids       = $s['ids'];
            $this->tokens    = $s['tokens'];
            $this->cssUrls   = $s['cssUrls'];
            $this->jsUrls    = $s['jsUrls'];
            $this->inlineCss = $s['inlineCss'];
            $this->failures  = $s['failures'];
            $this->failed    = $s['failed'];
            $this->parsed    = $s['parsed'];
            $this->done      = $s['done'];
            echo "Resuming a previous run: {$this->done} pages done, " . count($this->queue) . " queued.\n";
        } else {
            $start = normalizeUrl(Cfg::$startUrl);
            $this->queue->enqueue($start);
            $this->seen[$start] = true;
        }
    }

    public function result(): array
    {
        return [
            'pages'     => $this->done,
            'parsed'    => $this->parsed,
            'failed'    => $this->failed,
            'failures'  => $this->failures,
            'truncated' => $this->truncated,
            'classes'   => $this->classes,
            'ids'       => $this->ids,
            'tokens'    => $this->tokens,
            'cssUrls'   => $this->cssUrls,
            'jsUrls'    => $this->jsUrls,
            'inlineCss' => $this->inlineCss,
        ];
    }

    private function limitReached(int $inFlight): bool
    {
        return Cfg::$maxPages > 0 && ($this->done + $inFlight) >= Cfg::$maxPages;
    }

    public function run(): void
    {
        $mh = curl_multi_init();
        $active = [];
        $t0 = microtime(true);
        $doneAtStart = $this->done;
        $lastStart = 0.0;

        while (true) {
            while (count($active) < Cfg::$concurrency && !$this->queue->isEmpty() && !$this->limitReached(count($active))) {
                if (Cfg::$delayMs > 0) {
                    $wait = Cfg::$delayMs / 1000 - (microtime(true) - $lastStart);
                    if ($wait > 0) {
                        usleep((int) ($wait * 1000000));
                    }
                }
                $url = $this->queue->dequeue();
                $ch  = $this->makeHandle($url);
                curl_multi_add_handle($mh, $ch);
                $active[handleId($ch)] = $url;
                $lastStart = microtime(true);
            }
            if (!$active) {
                break;
            }

            curl_multi_exec($mh, $running);
            if (curl_multi_select($mh, 1.0) === -1) {
                usleep(20000);
            }

            while ($info = curl_multi_info_read($mh)) {
                $ch  = $info['handle'];
                $id  = handleId($ch);
                $req = $active[$id];
                unset($active[$id]);

                if ($info['result'] === CURLE_OK) {
                    $this->processPage(
                        $req,
                        (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
                        (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                        (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
                        (string) curl_multi_getcontent($ch)
                    );
                } else {
                    $this->recordFailure($req, curl_strerror($info['result']));
                }
                curl_multi_remove_handle($mh, $ch);
                closeCurl($ch);

                $this->done++;
                if ($this->done % 50 === 0) {
                    $rate = ($this->done - $doneAtStart) / max(1, microtime(true) - $t0);
                    printf("Crawled %d | queued %d | classes %d | ids %d | %.1f pages/s\n",
                        $this->done, count($this->queue), count($this->classes), count($this->ids), $rate);
                }
                if ($this->done % 500 === 0) {
                    $this->saveState($active);
                }
            }
        }
        curl_multi_close($mh);

        if (Cfg::$maxPages > 0 && !$this->queue->isEmpty()) {
            $this->truncated = true;
        }
    }

    private function recordFailure(string $url, string $why): void
    {
        $this->failed++;
        if (count($this->failures) < 50) {
            $this->failures[$url] = $why;
        }
    }

    private function makeHandle(string $url)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, baseCurlOptions() + [
            CURLOPT_MAXFILESIZE => 5 * 1024 * 1024,
            CURLOPT_HTTPHEADER  => ['Accept: text/html,application/xhtml+xml'],
        ]);
        return $ch;
    }

    private function saveState(array $inFlight): void
    {
        // Put in-flight URLs back at the front of the queue so nothing is lost on resume
        $q = new SplQueue();
        foreach ($inFlight as $u) {
            $q->enqueue($u);
        }
        foreach ($this->queue as $u) {
            $q->enqueue($u);
        }
        $tmp = Cfg::$stateFile . '.tmp';
        file_put_contents($tmp, serialize([
            'v' => 2, 'host' => Cfg::$host, 'queue' => $q, 'seen' => $this->seen,
            'classes' => $this->classes, 'ids' => $this->ids, 'tokens' => $this->tokens,
            'cssUrls' => $this->cssUrls, 'jsUrls' => $this->jsUrls, 'inlineCss' => $this->inlineCss,
            'failures' => $this->failures, 'failed' => $this->failed, 'parsed' => $this->parsed,
            'done' => $this->done,
        ]));
        rename($tmp, Cfg::$stateFile);
    }

    private function enqueue(string $raw, string $base): void
    {
        $abs = resolveUrl($raw, $base);
        $url = $abs ? normalizeUrl($abs) : null;
        if ($url === null || isset($this->seen[$url]) || !isInternal($url) || isExcluded($url)) {
            return;
        }
        if ($this->blockedByRobots($url)) {
            return;
        }
        $this->seen[$url] = true;
        $this->queue->enqueue($url);
    }

    private function blockedByRobots(string $url): bool
    {
        $p  = (string) parse_url($url, PHP_URL_PATH);
        $q  = parse_url($url, PHP_URL_QUERY);
        $pq = $p . ($q ? '?' . $q : '');
        foreach ($this->robots as $re) {
            if (preg_match($re, $pq)) {
                return true;
            }
        }
        return false;
    }

    private function registerAsset(array &$bucket, string $raw, string $base, bool $allowExternal): void
    {
        $abs = resolveUrl($raw, $base);
        if (!$abs) {
            return;
        }
        $p = parse_url($abs);
        if (empty($p['host'])) {
            return;
        }
        $host = strtolower($p['host']);
        if (!$allowExternal && !sameSite($host)) {
            return;
        }
        $key = $host . ($p['path'] ?? '/');   // ignore ?v=123 cache busters when de-duping
        if (!isset($bucket[$key])) {
            $bucket[$key] = $abs;
        }
    }

    private function processPage(string $req, string $final, int $code, string $type, string $body): void
    {
        // Follow redirects: treat the final URL as this page
        $pageUrl = normalizeUrl($final) ?? $req;
        if ($pageUrl !== $req) {
            if (!isInternal($pageUrl) || isset($this->seen[$pageUrl])) {
                return;
            }
            $this->seen[$pageUrl] = true;
        }

        if ($code !== 200) {
            $this->recordFailure($req, "HTTP $code");
            return;
        }
        if (stripos($type, 'html') === false || $body === '') {
            return;
        }

        // Make libxml read UTF-8 pages as UTF-8 so non-English class names survive.
        if (preg_match('//u', $body)) {
            $body = '<?xml encoding="UTF-8">' . $body;
        }
        $dom = new DOMDocument();
        $dom->loadHTML($body, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        $xp = new DOMXPath($dom);
        $this->parsed++;

        // Pages marked noindex or with another canonical URL are scanned too:
        // their markup still uses CSS.

        // class="..."
        foreach ($xp->query('//@class') as $n) {
            foreach (preg_split('/\s+/', trim($n->nodeValue), -1, PREG_SPLIT_NO_EMPTY) as $c) {
                $this->classes[$c] = true;
            }
        }
        // id="..."
        foreach ($xp->query('//@id') as $n) {
            $v = trim($n->nodeValue);
            if ($v !== '') {
                $this->ids[$v] = true;
            }
        }
        // attributes that reference ids: for=, aria-controls=, etc.
        $refAttrs = "name()='for' or name()='aria-controls' or name()='aria-labelledby' "
                  . "or name()='aria-describedby' or name()='aria-owns' or name()='list' or name()='form'";
        foreach ($xp->query("//@*[$refAttrs]") as $n) {
            foreach (preg_split('/\s+/', trim($n->nodeValue), -1, PREG_SPLIT_NO_EMPTY) as $v) {
                $this->ids[$v] = true;
            }
        }
        // <a href="#section"> and <a href="/page#section"> make :target ids "used"
        foreach ($xp->query('//a/@href') as $n) {
            $h = $n->nodeValue;
            if (($pos = strpos($h, '#')) !== false && strlen($h) > $pos + 1) {
                $this->ids[urldecode(substr($h, $pos + 1))] = true;
            }
        }
        // data-* attributes (data-bs-target="#nav", data-toggle="collapse", data-class="foo"...)
        foreach ($xp->query("//@*[starts-with(name(),'data-')]") as $n) {
            addTokens($this->tokens, $n->nodeValue);
        }

        // <script src> / inline scripts
        foreach ($xp->query('//script') as $s) {
            $src = trim($s->getAttribute('src'));
            if ($src !== '') {
                $this->registerAsset($this->jsUrls, $src, $pageUrl, Cfg::$externalJs);
            } else {
                addTokens($this->tokens, $s->textContent);
            }
        }

        // <link rel="stylesheet"> and <link rel="preload" as="style">
        $q1 = "//link[contains(concat(' ',normalize-space(translate(@rel,'STYLEHT','styleht')),' '),' stylesheet ')]/@href";
        $q2 = "//link[translate(@rel,'PRELOAD','preload')='preload' and translate(@as,'STYLE','style')='style']/@href";
        foreach ([$q1, $q2] as $q) {
            foreach ($xp->query($q) as $n) {
                $this->registerAsset($this->cssUrls, $n->nodeValue, $pageUrl, Cfg::$externalCss);
            }
        }

        // inline <style>
        foreach ($xp->query('//style') as $s) {
            $css = trim($s->textContent);
            if ($css !== '') {
                $h = md5($css);
                if (!isset($this->inlineCss[$h])) {
                    $this->inlineCss[$h] = ['css' => $css, 'page' => $pageUrl];
                }
            }
        }

        foreach ($xp->query('//a[@href]/@href') as $n) {
            $this->enqueue($n->nodeValue, $pageUrl);
        }
    }
}

// ---------------------- CSS parsing ----------------------

/**
 * Parses CSS into nodes:
 *  rule  => ['type'=>'rule',  'prelude'=>'a, .b', 'body'=>'color:red']
 *  group => ['type'=>'group', 'prelude'=>'@media ...', 'children'=>[...]]   (media/supports/layer/container)
 *  at    => ['type'=>'at',    'prelude'=>'@keyframes x', 'body'=>'...']      (kept untouched)
 *  stmt  => ['type'=>'stmt',  'text'=>'@import url(x);']
 */
function parseCss(string $css): array
{
    $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
    $i = 0;
    return parseNodes($css, $i, false);
}

function parseNodes(string $s, int &$i, bool $nested): array
{
    $n = strlen($s);
    $nodes = [];

    while ($i < $n) {
        $start = $i;
        $paren = 0;
        $quote = '';
        while ($i < $n) {
            $c = $s[$i];
            if ($quote !== '') {
                if ($c === '\\') { $i += 2; continue; }
                if ($c === $quote) { $quote = ''; }
                $i++;
                continue;
            }
            if ($c === '"' || $c === "'") { $quote = $c; $i++; continue; }
            if ($c === '\\') { $i += 2; continue; }
            if ($c === '(' || $c === '[') {
                $paren++;
            } elseif ($c === ')' || $c === ']') {
                $paren--;
            } elseif ($paren <= 0 && ($c === '{' || $c === ';' || $c === '}')) {
                break;
            }
            $i++;
        }

        $prelude = trim(substr($s, $start, min($i, $n) - $start));
        if ($i >= $n) {
            break;
        }

        $c = $s[$i];
        if ($c === '}') {
            $i++;
            if ($nested) {
                return $nodes;
            }
            continue;
        }
        if ($c === ';') {
            $i++;
            if ($prelude !== '') {
                $nodes[] = ['type' => 'stmt', 'text' => $prelude . ';'];
            }
            continue;
        }

        // '{'
        $i++;
        if ($prelude !== '' && $prelude[0] === '@'
            && preg_match('/^@(media|supports|layer|container|document|scope)\b/i', $prelude)) {
            $nodes[] = ['type' => 'group', 'prelude' => $prelude, 'children' => parseNodes($s, $i, true)];
            continue;
        }

        // raw body up to the matching '}'
        $bodyStart = $i;
        $depth = 1;
        $quote = '';
        while ($i < $n && $depth > 0) {
            $c = $s[$i];
            if ($quote !== '') {
                if ($c === '\\') { $i += 2; continue; }
                if ($c === $quote) { $quote = ''; }
            } elseif ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
            }
            $i++;
        }
        $body = substr($s, $bodyStart, max(0, $i - 1 - $bodyStart));
        $nodes[] = [
            'type'    => ($prelude !== '' && $prelude[0] === '@') ? 'at' : 'rule',
            'prelude' => $prelude,
            'body'    => $body,
        ];
    }
    return $nodes;
}

function splitSelectors(string $prelude): array
{
    $out = [];
    $depth = 0;
    $quote = '';
    $cur = '';
    $len = strlen($prelude);
    for ($i = 0; $i < $len; $i++) {
        $c = $prelude[$i];
        if ($quote !== '') {
            if ($c === '\\' && $i + 1 < $len) { $cur .= $c . $prelude[++$i]; continue; }
            if ($c === $quote) { $quote = ''; }
        } elseif ($c === '"' || $c === "'") {
            $quote = $c;
        } elseif ($c === '(' || $c === '[') {
            $depth++;
        } elseif ($c === ')' || $c === ']') {
            $depth--;
        } elseif ($c === ',' && $depth === 0) {
            $out[] = trim($cur);
            $cur = '';
            continue;
        }
        $cur .= $c;
    }
    if (trim($cur) !== '') {
        $out[] = trim($cur);
    }
    return array_values(array_filter($out, function ($x) {
        return $x !== '';
    }));
}

/** @return array{0:string[],1:string[]} classes and ids a selector REQUIRES */
function selectorRefs(string $sel): array
{
    // blank out strings, attribute selectors and :not()/:is()/:where()/:has() arguments
    $s = preg_replace('/"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'/', '""', $sel);
    $s = preg_replace('/\[[^\]]*\]/', '', $s);
    do {
        $prev = $s;
        $s = preg_replace('/:(?:not|is|where|has|matches|-webkit-any|-moz-any)\((?:[^()]|\([^()]*\))*\)/i', '', $s);
    } while ($s !== $prev);

    $unescape = function ($x) {
        return preg_replace('/\\\\(.)/s', '$1', $x);
    };

    preg_match_all('/\.((?:\\\\.|[\w\-]|[^\x00-\x7F])+)/', $s, $c);
    preg_match_all('/#((?:\\\\.|[\w\-]|[^\x00-\x7F])+)/', $s, $i);

    return [array_map($unescape, $c[1]), array_map($unescape, $i[1])];
}

// ---------------------- Usage checking ----------------------

final class Usage
{
    private $classes;
    private $ids;
    private $tokens;
    private $prefixes = [];

    public function __construct(array $classes, array $ids, array $tokens)
    {
        $this->classes = $classes;
        $this->ids     = $ids;
        $this->tokens  = $tokens;

        // Strings like 'btn-' in JavaScript usually mean a class name is built at runtime
        // ('btn-' + type), so every class starting with that prefix is kept.
        if (Cfg::$jsPrefixes) {
            foreach ($tokens as $t => $_) {
                $t = (string) $t;
                $l = strlen($t);
                if ($l >= 3 && ($t[$l - 1] === '-' || $t[$l - 1] === '_') && preg_match('/[A-Za-z]/', $t)) {
                    $this->prefixes[] = $t;
                }
            }
        }
    }

    public function classUsed(string $c): bool
    {
        return isset($this->classes[$c]) || isset($this->tokens[$c])
            || $this->safe($c, Cfg::$safeClasses) || $this->prefixed($c);
    }

    public function idUsed(string $i): bool
    {
        return isset($this->ids[$i]) || isset($this->tokens[$i])
            || $this->safe($i, Cfg::$safeIds) || $this->prefixed($i);
    }

    private function prefixed(string $name): bool
    {
        foreach ($this->prefixes as $p) {
            if (strpos($name, $p) === 0) {
                return true;
            }
        }
        return false;
    }

    private function safe(string $name, array $patterns): bool
    {
        foreach ($patterns as $re) {
            if (preg_match($re, $name)) {
                return true;
            }
        }
        return false;
    }
}

function filterNodes(array $nodes, Usage $u, array &$st): array
{
    $out = [];
    foreach ($nodes as $node) {
        switch ($node['type']) {
            case 'rule':
                $keep = [];
                foreach (splitSelectors($node['prelude']) as $sel) {
                    $st['selectors']++;
                    [$classes, $ids] = selectorRefs($sel);
                    $bad = false;
                    foreach ($classes as $c) {
                        if (!$u->classUsed($c)) {
                            $bad = true;
                            $st['classes'][$c] = true;
                        }
                    }
                    foreach ($ids as $id) {
                        if (!$u->idUsed($id)) {
                            $bad = true;
                            $st['ids'][$id] = true;
                        }
                    }
                    if ($bad) {
                        $st['removed']++;
                        $st['removedSelectors'][$sel] = true; // keyed: each selector listed once
                    } else {
                        $keep[] = $sel;
                    }
                }
                if ($keep) {
                    $out[] = ['type' => 'rule', 'selectors' => $keep, 'body' => $node['body']];
                }
                break;
            case 'group':
                $children = filterNodes($node['children'], $u, $st);
                if ($children) {
                    $node['children'] = $children;
                    $out[] = $node;
                }
                break;
            default: // 'at', 'stmt'
                $out[] = $node;
        }
    }
    return $out;
}

function serializeNodes(array $nodes): string
{
    $s = '';
    foreach ($nodes as $n) {
        switch ($n['type']) {
            case 'rule':
                $s .= implode(",\n", $n['selectors']) . ' {' . $n['body'] . "}\n";
                break;
            case 'at':
                $s .= $n['prelude'] . ' {' . $n['body'] . "}\n";
                break;
            case 'group':
                $s .= $n['prelude'] . " {\n" . serializeNodes($n['children']) . "}\n";
                break;
            case 'stmt':
                $s .= $n['text'] . "\n";
                break;
        }
    }
    return $s;
}

function importsIn(array $nodes): array
{
    $urls = [];
    foreach ($nodes as $n) {
        if ($n['type'] === 'stmt' && preg_match('/^@import\s+(?:url\(\s*)?["\']?([^"\')\s;]+)/i', $n['text'], $m)) {
            $urls[] = $m[1];
        } elseif ($n['type'] === 'group') {
            $urls = array_merge($urls, importsIn($n['children']));
        }
    }
    return $urls;
}

// ---------------------- Main ----------------------

$opts = parseArgs($argv);
if (isset($opts['help']) || count($argv) < 2) {
    echo usageText();
    exit(0);
}
if (isset($opts['version'])) {
    echo 'unused_css.php ' . VERSION . "\n";
    exit(0);
}
if (isset($opts['config'])) {
    $opts = mergeConfigFile($opts, (string) $opts['config']);
}
configure($opts);

echo "Unused CSS Finder " . VERSION . "\n";
if (!Cfg::$verifySsl) {
    echo "Warning: SSL certificate checks are turned off (--insecure).\n";
}
if (Cfg::$crawl) {
    resolveStartUrl();
}

if (!is_dir(Cfg::$outputDir) && !mkdir(Cfg::$outputDir, 0775, true)) {
    fail('Could not create the output folder: ' . Cfg::$outputDir);
}
if (Cfg::$fresh && is_file(Cfg::$stateFile)) {
    unlink(Cfg::$stateFile);
}

if (Cfg::$crawl) {
    echo 'Scanning ' . Cfg::$scheme . '://' . Cfg::$host . Cfg::$port . "/ (up to "
        . (Cfg::$maxPages > 0 ? Cfg::$maxPages : 'unlimited') . " pages, " . Cfg::$concurrency . " at a time)\n";

    $crawler = new Crawler();
    $crawler->run();
    $data = $crawler->result();

    if ($data['parsed'] === 0) {
        fail('No HTML pages could be read, so there is nothing to compare the CSS against.');
    }

    echo "\nCrawl finished: {$data['parsed']} pages read, " . count($data['classes']) . " classes, "
        . count($data['ids']) . " ids, " . count($data['cssUrls']) . " stylesheets, "
        . count($data['inlineCss']) . " inline <style> blocks, " . count($data['jsUrls']) . " scripts.\n";
} else {
    $data = ['pages' => 0, 'parsed' => 0, 'failed' => 0, 'failures' => [], 'truncated' => false,
             'classes' => [], 'ids' => [], 'tokens' => [], 'cssUrls' => [], 'jsUrls' => [], 'inlineCss' => []];
}

$warnings = [];
if (!Cfg::$crawl) {
    // nothing to warn about from a crawl
} elseif ($data['truncated']) {
    $warnings[] = 'The crawl stopped at the --max-pages limit (' . Cfg::$maxPages . ') before every page was visited. '
        . 'Classes used only on pages that were not visited are wrongly listed as unused. Raise --max-pages or use --exclude.';
}
if ($data['failed'] > 0) {
    $warnings[] = $data['failed'] . ' page(s) could not be read (errors, timeouts or blocked requests). '
        . 'Classes used only on those pages may be wrongly listed as unused.';
}
if (Cfg::$crawl && $data['parsed'] <= 1) {
    $warnings[] = 'Only one page was found. If the site builds its pages with JavaScript (React, Vue, etc.) '
        . 'or hides its links behind JavaScript, this tool cannot see them - results are unreliable.';
}
if (Cfg::$crawl && !$data['cssUrls'] && !$data['inlineCss']) {
    $warnings[] = 'No stylesheets were found on the site.';
}

// Read the source files, if asked: every word in them counts as "used".
$tokens = $data['tokens'];
$sourceFiles = 0;
$localCss = [];
$unlinkedCss = [];
if (Cfg::$sourceDirs) {
    echo 'Reading source files in ' . implode(', ', Cfg::$sourceDirs) . " ...\n";
    $scan = scanSources($tokens);
    $sourceFiles = $scan['files'];
    $localCss = $scan['css'];
    $foundCss = count($localCss);
    echo "Source files read: $sourceFiles" . (Cfg::$localCss ? ", stylesheets found: $foundCss" : '') . "\n";
    if ($localCss && !Cfg::$includeUnlinkedCss) {
        if (!$scan['refs']) {
            $warnings[] = 'No source file mentions a .css file, so every stylesheet in the folder is being checked. '
                . 'If some of them are not used by the website, the results will be misleading.';
        } else {
            foreach ($localCss as $real => $info) {
                if (!cssIsLinked($info['rel'], $scan['refs'])) {
                    $unlinkedCss[$real] = $info['label'];
                    unset($localCss[$real]);
                }
            }
            echo 'Stylesheets referenced by your source files: ' . count($localCss) . ' of ' . $foundCss . "\n";
        }
    }
    if ($sourceFiles === 0) {
        $warnings[] = 'No source files were found in the --source folder(s) with the file types ('
            . implode(', ', Cfg::$sourceExts) . '). Nothing was added to the list of names in use, '
            . 'so the results are unreliable. Check the folder and --source-ext.';
    }
    if ($scan['skipped'] > 0) {
        $warnings[] = $scan['skipped'] . ' source file(s) were skipped because they were unreadable, binary or over 20 MB.';
    }
    if (!Cfg::$crawl && !$foundCss) {
        $warnings[] = 'No .css files were found in the --source folder(s), so there was nothing to check. '
            . 'Add the website address to check the live stylesheets instead.';
    }
}
foreach ($warnings as $w) {
    echo "WARNING: $w\n";
}

// Harvest words from internal JS files: they often add classes at runtime.
if ($data['jsUrls']) {
    echo 'Reading ' . count($data['jsUrls']) . " script(s) for class names...\n";
}
foreach ($data['jsUrls'] as $url) {
    $js = fetchText($url);
    if ($js === null) {
        $warnings[] = "Could not fetch script: $url (classes it adds at runtime may be listed as unused).";
        fwrite(STDERR, "Could not fetch script: $url\n");
        continue;
    }
    addTokens($tokens, $js);
}

$usage = new Usage($data['classes'], $data['ids'], $tokens);

// Build the list of stylesheets to analyze (@imports get appended as they are found).
$pending = [];
foreach ($data['cssUrls'] as $url) {
    $pending[] = ['name' => $url, 'url' => $url, 'css' => null, 'base' => $url, 'file' => null];
}
foreach ($data['inlineCss'] as $h => $item) {
    $pending[] = ['name' => 'inline <style> on ' . $item['page'], 'url' => null, 'css' => $item['css'],
                  'base' => $item['page'], 'file' => 'inline-' . substr((string) $h, 0, 8) . '.css'];
}
foreach ($localCss as $real => $info) {
    $label = is_array($info) ? $info['label'] : $info;
    $pending[] = ['name' => $label, 'url' => null, 'css' => null, 'path' => $real, 'base' => $real,
                  'file' => trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $label), '_')];
}

$report = [
    'tool'          => 'unused_css.php ' . VERSION,
    'site'          => Cfg::$crawl ? Cfg::$scheme . '://' . Cfg::$host . Cfg::$port . '/' : implode(', ', Cfg::$sourceDirs),
    'date'          => date('c'),
    'pagesRead'     => $data['parsed'],
    'sourceFiles'   => $sourceFiles,
    'sourceDirs'    => Cfg::$sourceDirs,
    'warnings'      => [],
    'files'         => [],
    'unusedClasses' => [],
    'unusedIds'     => [],
];
$allClasses = [];
$allIds = [];
$visited = [];
$usedNames = [];

for ($idx = 0; $idx < count($pending); $idx++) {
    $src = $pending[$idx];
    if ($src['url'] !== null) {
        $k = strtolower((string) preg_replace('/\?.*$/', '', $src['url']));
        if (isset($visited[$k])) {
            continue;
        }
        $visited[$k] = true;
        $css = fetchText($src['url']);
        if ($css === null) {
            $warnings[] = "Could not fetch stylesheet: {$src['url']}";
            fwrite(STDERR, "Could not fetch stylesheet: {$src['url']}\n");
            continue;
        }
        $path = (string) parse_url($src['url'], PHP_URL_PATH);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', ltrim($path, '/'));
        $src['file'] = $name !== '' ? $name : 'style';
    } elseif (isset($src['path'])) {
        $k = 'file:' . $src['path'];
        if (isset($visited[$k])) {
            continue;
        }
        $visited[$k] = true;
        $css = @file_get_contents($src['path']);
        if ($css === false) {
            $warnings[] = "Could not read stylesheet: {$src['path']}";
            fwrite(STDERR, "Could not read stylesheet: {$src['path']}\n");
            continue;
        }
    } else {
        $css = $src['css'];
    }
    if (!preg_match('/\.css$/i', (string) $src['file'])) {
        $src['file'] .= '.css';
    }

    // never let two stylesheets overwrite each other in purged/
    $base = $src['file'];
    for ($n = 2; isset($usedNames[strtolower($src['file'])]); $n++) {
        $src['file'] = preg_replace('/\.css$/i', '', $base) . '-' . $n . '.css';
    }
    $usedNames[strtolower($src['file'])] = true;

    $nodes = parseCss($css);

    foreach (importsIn($nodes) as $imp) {
        if (isset($src['path'])) {
            // @import inside a stylesheet read from disk: follow relative paths, skip web addresses
            if (preg_match('#^([a-z][a-z0-9+.\-]*:|//)#i', $imp)) {
                continue;
            }
            $clean = (string) preg_replace('/[?#].*$/', '', $imp);
            $tries = $clean !== '' && $clean[0] === '/' ? array_map(function ($d) use ($clean) {
                return $d . $clean;
            }, Cfg::$sourceDirs) : [dirname($src['path']) . '/' . $clean];
            foreach ($tries as $t) {
                $real = realpath($t);
                if ($real !== false && is_file($real)) {
                    $pending[] = ['name' => $src['name'] . ' > ' . basename($real), 'url' => null, 'css' => null,
                                  'path' => $real, 'base' => $real,
                                  'file' => trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($real)), '_')];
                    break;
                }
            }
            continue;
        }
        $abs = resolveUrl($imp, $src['base']);
        if ($abs && (Cfg::$externalCss || isInternal($abs))) {
            $pending[] = ['name' => $abs, 'url' => $abs, 'css' => null, 'base' => $abs, 'file' => null];
        }
    }

    $st = ['selectors' => 0, 'removed' => 0, 'classes' => [], 'ids' => [], 'removedSelectors' => []];
    $kept = filterNodes($nodes, $usage, $st);
    $purged = serializeNodes($kept);

    $allClasses += $st['classes'];
    $allIds += $st['ids'];

    $report['files'][] = [
        'source'           => $src['name'],
        'bytesBefore'      => strlen($css),
        'bytesAfter'       => strlen($purged),
        'selectors'        => $st['selectors'],
        'unusedSelectors'  => $st['removed'],
        'unusedClasses'    => array_map('strval', array_keys($st['classes'])),
        'unusedIds'        => array_map('strval', array_keys($st['ids'])),
        'removedSelectors' => array_map('strval', array_keys($st['removedSelectors'])),
    ];
    printf("%s: %d/%d selectors unused (%s -> %s bytes)\n", $src['name'], $st['removed'], $st['selectors'],
        number_format(strlen($css)), number_format(strlen($purged)));

    if (Cfg::$purge) {
        $dir = Cfg::$outputDir . '/purged';
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            fail("Could not create $dir");
        }
        file_put_contents($dir . '/' . $src['file'], $purged);
    }
}

// Stylesheets nothing refers to (and that no other stylesheet imports) were left out.
$notChecked = [];
foreach ($unlinkedCss as $real => $label) {
    if (!isset($visited['file:' . $real])) {
        $notChecked[] = $label;
    }
}
if ($notChecked) {
    $shown = array_slice($notChecked, 0, 10);
    $w = count($notChecked) . ' stylesheet(s) in the source folder are not referenced by any source file and were not checked: '
        . implode(', ', $shown) . (count($notChecked) > 10 ? ', and ' . (count($notChecked) - 10) . ' more' : '')
        . '. They may be unused, or loaded in a way that is not visible in the source. '
        . 'Add --include-unlinked-css to check them too.';
    $warnings[] = $w;
    echo "WARNING: $w\n";
}

$report['warnings']      = $warnings;
$report['unlinkedCss']   = $notChecked;
$report['failedPages']   = $data['failures'];
$report['unusedClasses'] = array_map('strval', array_keys($allClasses));
$report['unusedIds']     = array_map('strval', array_keys($allIds));
sort($report['unusedClasses']);
sort($report['unusedIds']);

file_put_contents(Cfg::$outputDir . '/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$txt  = "Unused CSS report for {$report['site']}\n" . str_repeat('=', 60) . "\n";
$txt .= "Generated {$report['date']} by {$report['tool']}\n";
if (Cfg::$crawl) {
    $txt .= "Pages read: {$report['pagesRead']}\n";
}
if (Cfg::$sourceDirs) {
    $txt .= "Source files read: {$report['sourceFiles']}\n";
}
$txt .= "\n";
if ($warnings) {
    $txt .= "WARNINGS\n";
    foreach ($warnings as $w) {
        $txt .= "  - $w\n";
    }
    $txt .= "\n";
}
foreach ($report['files'] as $f) {
    $txt .= $f['source'] . "\n";
    $txt .= sprintf("  selectors: %d | unused: %d | size: %s -> %s bytes\n", $f['selectors'],
        $f['unusedSelectors'], number_format($f['bytesBefore']), number_format($f['bytesAfter']));
    if ($f['unusedClasses']) {
        $txt .= '  unused classes: .' . implode(' .', $f['unusedClasses']) . "\n";
    }
    if ($f['unusedIds']) {
        $txt .= '  unused ids: #' . implode(' #', $f['unusedIds']) . "\n";
    }
    $txt .= "\n";
}
$txt .= 'UNUSED CLASSES (' . count($report['unusedClasses']) . ")\n";
foreach ($report['unusedClasses'] as $c) {
    $txt .= "  .$c\n";
}
$txt .= "\nUNUSED IDS (" . count($report['unusedIds']) . ")\n";
foreach ($report['unusedIds'] as $i) {
    $txt .= "  #$i\n";
}
$txt .= "\nReview this list before deleting anything. See the documentation for what is not checked.\n";
file_put_contents(Cfg::$outputDir . '/report.txt', $txt);

if (is_file(Cfg::$stateFile)) {
    unlink(Cfg::$stateFile);
}
echo "\nDone. " . count($report['unusedClasses']) . ' unused classes, ' . count($report['unusedIds']) . " unused ids.\n";
echo 'Reports written to ' . Cfg::$outputDir . "/report.txt and report.json"
    . (Cfg::$purge ? ' (cleaned CSS in purged/)' : ' (add --purge to also write cleaned CSS)') . "\n";
