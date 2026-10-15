<?php
/**
 * Multi-language support: English, Kurdish (Sorani, کوردی) and Arabic (العربية).
 *
 * How it works
 *  - The visitor's language lives in a cookie ("lang"). Any page accepts ?lang=en|ku|ar,
 *    stores it, and comes straight back without the parameter. It applies to every
 *    account type (admin, teacher, student, accountant, owner) and to the sign-in page.
 *  - Pages are written in English. For Kurdish/Arabic, the finished HTML page is run through
 *    i18n_filter(), which swaps each piece of visible text (text nodes, placeholders, tooltips,
 *    confirm() questions) for its translation from includes/lang/*.php. English pages skip
 *    the filter entirely, so English costs nothing.
 *  - Texts with changing parts use {} slots, e.g. 'Grade {}' or 'Payment #{} recorded'.
 *    {1}, {2} ... in a translation pick a captured part by position when the word order differs.
 *  - Anything without a translation simply stays in English, so a missing entry can never break
 *    a page. To add or fix a translation, edit the files in includes/lang/.
 *  - Kurdish and Arabic switch the whole page to right-to-left (dir="rtl").
 */

const I18N_LANGS = [
    'en' => ['native' => 'English', 'name' => 'English', 'dir' => 'ltr', 'html' => 'en',  'idx' => null],
    'ku' => ['native' => 'کوردی',   'name' => 'Kurdish', 'dir' => 'rtl', 'html' => 'ckb', 'idx' => 0],
    'ar' => ['native' => 'العربية', 'name' => 'Arabic',  'dir' => 'rtl', 'html' => 'ar',  'idx' => 1],
];

/** Current language code: 'en', 'ku' or 'ar'. */
function i18n_lang(): string
{
    static $lang = null;
    if ($lang === null) {
        $cookie = (string)($_COOKIE['lang'] ?? '');
        $lang = isset(I18N_LANGS[$cookie]) ? $cookie : 'en';
    }
    return $lang;
}

function i18n_is_rtl(): bool
{
    return I18N_LANGS[i18n_lang()]['dir'] === 'rtl';
}

function i18n_dir(): string
{
    return I18N_LANGS[i18n_lang()]['dir'];
}

function i18n_html_lang(): string
{
    return I18N_LANGS[i18n_lang()]['html'];
}

/** Handles ?lang=xx: remember the choice for a year, then reload the same page without it. */
function i18n_boot(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' || !isset($_GET['lang']) || headers_sent()) {
        return;
    }
    $wanted = (string)$_GET['lang'];
    if (isset(I18N_LANGS[$wanted])) {
        $path = (defined('APP_BASE_PATH') ? APP_BASE_PATH : '') . '/';
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie('lang', $wanted, [
            'expires' => time() + 31536000, 'path' => $path, 'secure' => $https,
            'httponly' => true, 'samesite' => 'Lax',
        ]);
        $_COOKIE['lang'] = $wanted;
    }
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/');
    parse_str($uri['query'] ?? '', $query);
    unset($query['lang']);
    $target = ($uri['path'] ?? '/') . ($query ? '?' . http_build_query($query) : '');
    header('Location: ' . $target);
    exit;
}

/* ------------------------------- dictionary -------------------------------- */

/** @return array{exact: array<string,string>, templates: list<array{0:string,1:string,2:int}>, byKey: array<string,string>} */
function i18n_dictionary(): array
{
    static $dict = null;
    if ($dict !== null) {
        return $dict;
    }
    $dict = ['exact' => [], 'templates' => [], 'byKey' => []];
    $idx = I18N_LANGS[i18n_lang()]['idx'];
    if ($idx === null) {
        return $dict;
    }
    foreach (glob(__DIR__ . '/lang/*.php') ?: [] as $file) {
        $entries = require $file;
        foreach ($entries as $english => $translations) {
            $text = $translations[$idx] ?? '';
            if ($text === '') {
                continue;
            }
            $dict['byKey'][$english] = $text; // every entry, for t() in PHP code
            if (strpos($english, '{}') !== false) {
                $regex = '~^' . str_replace('\{\}', '(.+?)', preg_quote($english, '~')) . '$~us';
                $dict['templates'][] = [$regex, $text, strlen(str_replace('{}', '', $english))];
            } else {
                $dict['exact'][$english] = $text;
            }
        }
    }
    // Most specific templates first, so "Grade {} fee (after discount)" wins over "Grade {} fee".
    usort($dict['templates'], fn($a, $b) => $b[2] <=> $a[2]);
    return $dict;
}

/** Fills {} (in order) and {1}, {2}... (by position) in a translation. */
function i18n_fill(string $text, array $parts): string
{
    $n = 0;
    $text = preg_replace_callback('~\{(\d*)\}~', function ($m) use ($parts, &$n) {
        $i = $m[1] === '' ? $n++ : (int)$m[1] - 1;
        return $parts[$i] ?? '';
    }, $text);
    return $text;
}

/** Translates one piece of plain text (already HTML-decoded). Returns null when there is no translation. */
function i18n_lookup(string $text): ?string
{
    static $cache = [];
    if (isset($cache[$text])) {
        return $cache[$text] === false ? null : $cache[$text];
    }
    $dict = i18n_dictionary();
    $found = $dict['exact'][$text] ?? null;
    if ($found === null && strlen($text) < 400) {
        foreach ($dict['templates'] as [$regex, $translation]) {
            if (preg_match($regex, $text, $m)) {
                array_shift($m);
                $found = i18n_fill($translation, $m);
                break;
            }
        }
    }
    $cache[$text] = $found ?? false;
    return $found;
}

/** For use in PHP code: t('Payment #{} recorded', $no) */
function t(string $english, ...$args): string
{
    if (i18n_lang() !== 'en') {
        $dict = i18n_dictionary();
        $translation = $dict['byKey'][$english] ?? null;
        if ($translation !== null) {
            return $args ? i18n_fill($translation, array_map('strval', $args)) : $translation;
        }
    }
    return $args ? i18n_fill($english, array_map('strval', $args)) : $english;
}

/** Month names for dates such as "04 Oct 2026" (the names used in Iraq / Kurdistan). */
function i18n_months(): array
{
    static $m = [
        'ku' => ['Jan' => 'کانوونی دووەم', 'Feb' => 'شوبات', 'Mar' => 'ئازار', 'Apr' => 'نیسان', 'May' => 'ئایار', 'Jun' => 'حوزەیران',
                 'Jul' => 'تەمموز', 'Aug' => 'ئاب', 'Sep' => 'ئەیلوول', 'Oct' => 'تشرینی یەکەم', 'Nov' => 'تشرینی دووەم', 'Dec' => 'کانوونی یەکەم'],
        'ar' => ['Jan' => 'كانون الثاني', 'Feb' => 'شباط', 'Mar' => 'آذار', 'Apr' => 'نيسان', 'May' => 'أيار', 'Jun' => 'حزيران',
                 'Jul' => 'تموز', 'Aug' => 'آب', 'Sep' => 'أيلول', 'Oct' => 'تشرين الأول', 'Nov' => 'تشرين الثاني', 'Dec' => 'كانون الأول'],
    ];
    return $m[i18n_lang()] ?? [];
}

function i18n_dates(string $text): string
{
    $months = i18n_months();
    if (!$months || !preg_match('~\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\b~', $text)) {
        return $text;
    }
    $names = implode('|', array_keys($months));
    return preg_replace_callback(
        '~(?<=\d )(' . $names . ')\b(?= \d{4})|\b(' . $names . ')(?= \d{1,2}, \d{4})~',
        fn($m) => $months[$m[1] !== '' ? $m[1] : $m[2]],
        $text
    );
}

/* -------------------------------- the filter -------------------------------- */

function i18n_text_node(string $raw): string
{
    if (trim($raw) === '') {
        return $raw;
    }
    preg_match('~^(\s*)(.*?)(\s*)$~us', $raw, $parts);
    $core = html_entity_decode(preg_replace('~\s+~u', ' ', $parts[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $translated = i18n_lookup($core);
    if ($translated === null) {
        $dated = i18n_dates($core);
        if ($dated === $core) {
            return $raw;
        }
        $translated = $dated;
    } else {
        $translated = i18n_dates($translated);
    }
    return $parts[1] . htmlspecialchars($translated, ENT_NOQUOTES, 'UTF-8') . $parts[3];
}

/** confirm('...') / alert('...') questions inside inline handlers and scripts. */
function i18n_js_messages(string $code): string
{
    return preg_replace_callback(
        "~\b(confirm|alert)\(\s*'((?:[^'\\\\]|\\\\.)*)'~u",
        function ($m) {
            $plain = html_entity_decode(stripslashes($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $translated = i18n_lookup($plain);
            if ($translated === null) {
                return $m[0];
            }
            return $m[1] . "('" . addcslashes(htmlspecialchars($translated, ENT_QUOTES, 'UTF-8'), "'\\") . "'";
        },
        $code
    );
}

function i18n_tag(string $tag): string
{
    if (strpos($tag, '=') === false) {
        return $tag;
    }
    $tag = preg_replace_callback(
        '~\b(placeholder|title|aria-label|alt|data-bs-title|data-confirm)=(["\'])(.*?)\2~us',
        function ($m) {
            $plain = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $translated = i18n_lookup(trim(preg_replace('~\s+~u', ' ', $plain)));
            return $translated === null ? $m[0] : $m[1] . '=' . $m[2] . htmlspecialchars($translated, ENT_QUOTES, 'UTF-8') . $m[2];
        },
        $tag
    );
    if (stripos($tag, 'confirm(') !== false || stripos($tag, 'alert(') !== false) {
        $tag = i18n_js_messages($tag);
    }
    return $tag;
}

/** Output-buffer callback: translates a finished HTML page. */
function i18n_filter(string $html): string
{
    if ($html === '' || stripos($html, '<html') === false) {
        return $html; // not a full page (e.g. a fragment or file download): leave alone
    }
    $pieces = preg_split(
        '~(<script\b[^>]*>.*?</script>|<style\b[^>]*>.*?</style>|<!--.*?-->|<[^>]+>)~is',
        $html, -1, PREG_SPLIT_DELIM_CAPTURE
    );
    foreach ($pieces as $i => $piece) {
        if ($i % 2 === 0) {
            $pieces[$i] = i18n_text_node($piece);
        } elseif (stripos($piece, '<script') === 0) {
            $pieces[$i] = i18n_js_messages($piece);
        } elseif ($piece !== '' && $piece[0] === '<' && strncmp($piece, '<!--', 4) !== 0 && stripos($piece, '<style') !== 0) {
            $pieces[$i] = i18n_tag($piece);
        }
    }
    return implode('', $pieces);
}

/** Starts translating the page being built. Call before any output; safe to call twice. */
function i18n_start(): void
{
    static $started = false;
    if (!$started && i18n_lang() !== 'en') {
        $started = true;
        ob_start('i18n_filter');
    }
}

/* ------------------------------ language switcher ------------------------------ */

/** The language picker (dropdown). $variant 'auth' is the floating one on the sign-in card. */
function i18n_switcher(string $variant = 'topbar'): string
{
    $current = i18n_lang();
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/');
    parse_str($uri['query'] ?? '', $query);
    unset($query['lang']);
    $base = ($uri['path'] ?? '/');
    $html = '<div class="dropdown lang-switcher lang-switcher-' . $variant . '">'
        . '<button class="lang-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="' . h(t('Language')) . '">'
        . '<i class="bi bi-translate"></i><span class="lang-btn-label">' . h(I18N_LANGS[$current]['native']) . '</span>'
        . '<i class="bi bi-chevron-down lang-caret"></i></button>'
        . '<ul class="dropdown-menu dropdown-menu-end lang-menu">';
    foreach (I18N_LANGS as $code => $meta) {
        $href = $base . '?' . http_build_query($query + ['lang' => $code]);
        $html .= '<li><a class="dropdown-item lang-item' . ($code === $current ? ' active' : '') . '" href="' . h($href) . '" lang="' . h($meta['html']) . '" hreflang="' . h($meta['html']) . '">'
            . '<span class="lang-code">' . strtoupper($code) . '</span>'
            . '<span class="lang-native">' . h($meta['native']) . '</span>'
            . ($code === $current ? '<i class="bi bi-check2 ms-auto"></i>' : '')
            . '</a></li>';
    }
    return $html . '</ul></div>';
}

i18n_boot();
