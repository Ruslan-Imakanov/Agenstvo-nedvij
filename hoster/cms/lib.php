<?php
// Кабинет администратора сайта: общие функции — хранилище правок, вход по паролю, очистка текста.
// PHP 7.0+, без расширений mbstring / dom / gd. Данные лежат в cms/data: папка закрыта от интернета (.htaccess),
// а каждый файл данных начинается со строки «<?php exit;» — даже открытый напрямую, он ничего не покажет.
if (!defined('MIP_CMS')) {
    http_response_code(404);
    exit;
}

define('CMS_DIR', __DIR__);
define('CMS_DATA', __DIR__ . '/data');
define('CMS_ROOT', dirname(__DIR__));
define('CMS_TPL', __DIR__ . '/page.tpl');
define('CMS_COOKIE', 'mip_cms');
define('CMS_TTL', 12 * 3600);          // вход действует 12 часов
define('CMS_KEEP', 60);                // столько последних версий хранит история
define('CMS_EXIT', '<?php exit; ?' . ">\n");   // строка склеена из двух частей, чтобы не закрыть сам этот файл

// ---------- Хранилище ----------

function cms_path($name)
{
    return CMS_DATA . '/' . $name . '.php';
}

function cms_read($name, $default = null)
{
    $f = cms_path($name);
    if (!is_file($f)) {
        return $default;
    }
    $raw = (string) @file_get_contents($f);
    if (strncmp($raw, CMS_EXIT, strlen(CMS_EXIT)) === 0) {
        $raw = substr($raw, strlen(CMS_EXIT));
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

// Запись через временный файл и переименование: файл никогда не бывает записан наполовину.
function cms_write($name, $data)
{
    $f = cms_path($name);
    $dir = dirname($f);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать папку ' . $dir);
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        throw new RuntimeException('Не удалось подготовить данные к записи');
    }
    $tmp = $f . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($tmp, CMS_EXIT . $json) === false) {
        throw new RuntimeException('Не удалось записать ' . basename($f) . ' — проверьте права на папку cms/data');
    }
    @chmod($tmp, 0640);
    if (!@rename($tmp, $f)) {
        @unlink($tmp);
        throw new RuntimeException('Не удалось сохранить ' . basename($f));
    }
}

function cms_delete($name)
{
    $f = cms_path($name);
    return !is_file($f) || @unlink($f);
}

// Одна запись за раз: сохранение правок, пользователей, счётчиков попыток входа.
function cms_lock()
{
    if (!is_dir(CMS_DATA)) {
        @mkdir(CMS_DATA, 0750, true);
    }
    $fh = @fopen(CMS_DATA . '/lock.php', 'c');
    if ($fh) {
        flock($fh, LOCK_EX);
    }
    return $fh;
}

function cms_unlock($fh)
{
    if ($fh) {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

// Текущие правки сайта (то, чем страница отличается от шаблона).
function cms_content()
{
    $c = cms_read('content', []);
    $arr = function ($k) use ($c) {
        return (isset($c[$k]) && is_array($c[$k])) ? $c[$k] : [];
    };
    return [
        'rev' => isset($c['rev']) ? (int) $c['rev'] : 0,
        'at' => isset($c['at']) ? (string) $c['at'] : '',
        'by' => isset($c['by']) ? (string) $c['by'] : '',
        'text' => $arr('text'),
        'list' => $arr('list'),
        'img' => $arr('img'),
        'config' => (isset($c['config']) && is_array($c['config']) && $c['config']) ? $c['config'] : null,
    ];
}

// ---------- Секретный ключ, пользователи, вход ----------

function cms_secret($create = false)
{
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }
    $f = cms_path('secret');
    $raw = is_file($f) ? (string) @file_get_contents($f) : '';
    if (preg_match('/([0-9a-f]{64})\s*$/', $raw, $m)) {
        return $secret = $m[1];
    }
    if (!$create) {
        return '';
    }
    if (!is_dir(CMS_DATA)) {
        @mkdir(CMS_DATA, 0750, true);
    }
    $new = bin2hex(random_bytes(32));
    $fh = @fopen($f, 'x');
    if ($fh) {
        fwrite($fh, CMS_EXIT . $new . "\n");
        fclose($fh);
        @chmod($f, 0640);
        return $secret = $new;
    }
    $raw = (string) @file_get_contents($f);
    if (preg_match('/([0-9a-f]{64})\s*$/', $raw, $m)) {
        return $secret = $m[1];
    }
    throw new RuntimeException('Не удалось создать секретный ключ в cms/data');
}

function cms_b64e($s)
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function cms_b64d($s)
{
    $r = base64_decode(strtr($s, '-_', '+/'), true);
    return $r === false ? '' : $r;
}

function cms_users()
{
    $d = cms_read('users', []);
    return (isset($d['users']) && is_array($d['users'])) ? $d['users'] : [];
}

function cms_save_users($users)
{
    ksort($users);
    cms_write('users', ['users' => $users]);
}

function cms_norm_login($l)
{
    return strtolower(trim((string) $l));
}

function cms_valid_login($l)
{
    return is_string($l) && preg_match('/^[a-z0-9._-]{3,32}$/', $l) === 1;
}

function cms_password_problem($p)
{
    if (!is_string($p) || strlen($p) < 10) {
        return 'Пароль — не короче 10 символов';
    }
    if (strlen($p) > 200) {
        return 'Слишком длинный пароль';
    }
    if (preg_match('/^\s|\s$/', $p)) {
        return 'Пароль не должен начинаться или заканчиваться пробелом';
    }
    return '';
}

// Кука входа: данные (логин, срок, «поколение» пароля, случайная метка) + подпись HMAC секретным ключом.
function cms_token($login, $epoch, $nonce = null)
{
    $payload = cms_b64e(json_encode([
        'u' => $login,
        'e' => time() + CMS_TTL,
        'p' => (int) $epoch,
        'n' => $nonce === null ? bin2hex(random_bytes(8)) : $nonce,
    ]));
    return $payload . '.' . cms_b64e(hash_hmac('sha256', $payload, cms_secret(true), true));
}

function cms_https()
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
}

function cms_cookie($value, $ttl)
{
    $c = CMS_COOKIE . '=' . $value . '; Path=/; HttpOnly; SameSite=Lax';
    $c .= $ttl > 0 ? '; Max-Age=' . (int) $ttl : '; Max-Age=0';
    if (cms_https()) {
        $c .= '; Secure';
    }
    header('Set-Cookie: ' . $c, false);
}

function cms_login_as($login, $nonce = null)
{
    $users = cms_users();
    $ep = isset($users[$login]['epoch']) ? (int) $users[$login]['epoch'] : 0;
    $token = cms_token($login, $ep, $nonce);
    cms_cookie($token, CMS_TTL);
    $parts = explode('.', $token);
    $d = json_decode(cms_b64d($parts[0]), true);
    $GLOBALS['cms_session'] = ['login' => $login, 'nonce' => (string) $d['n'], 'exp' => (int) $d['e']];
    return $GLOBALS['cms_session'];
}

function cms_logout()
{
    cms_cookie('', 0);
    $GLOBALS['cms_session'] = null;
}

// Кто вошёл (или null). Подпись, срок и «поколение» пароля проверяются при каждом запросе.
function cms_session()
{
    if (array_key_exists('cms_session', $GLOBALS)) {
        return $GLOBALS['cms_session'];
    }
    $GLOBALS['cms_session'] = null;
    $t = isset($_COOKIE[CMS_COOKIE]) ? (string) $_COOKIE[CMS_COOKIE] : '';
    if ($t === '' || strlen($t) > 600) {
        return null;
    }
    $secret = cms_secret(false);
    $parts = explode('.', $t);
    if ($secret === '' || count($parts) !== 2) {
        return null;
    }
    if (!hash_equals(cms_b64e(hash_hmac('sha256', $parts[0], $secret, true)), $parts[1])) {
        return null;
    }
    $d = json_decode(cms_b64d($parts[0]), true);
    if (!is_array($d) || !isset($d['u'], $d['e'], $d['p'], $d['n']) || !is_string($d['u']) || (int) $d['e'] < time()) {
        return null;
    }
    $users = cms_users();
    if (!isset($users[$d['u']])) {
        return null;
    }
    $ep = isset($users[$d['u']]['epoch']) ? (int) $users[$d['u']]['epoch'] : 0;
    if ($ep !== (int) $d['p']) {
        return null;
    }
    return $GLOBALS['cms_session'] = ['login' => $d['u'], 'nonce' => (string) $d['n'], 'exp' => (int) $d['e']];
}

function cms_csrf($sess)
{
    return cms_b64e(hash_hmac('sha256', 'csrf|' . $sess['login'] . '|' . $sess['nonce'], cms_secret(true), true));
}

// Запрос пришёл с нашего же сайта (заголовок Origin, а без него — Referer).
function cms_same_origin()
{
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower((string) $_SERVER['HTTP_HOST']) : '';
    $src = '';
    if (!empty($_SERVER['HTTP_ORIGIN'])) {
        $src = (string) $_SERVER['HTTP_ORIGIN'];
    } elseif (!empty($_SERVER['HTTP_REFERER'])) {
        $src = (string) $_SERVER['HTTP_REFERER'];
    }
    if ($src === '') {
        return true;
    }
    $p = parse_url($src);
    if (!is_array($p) || empty($p['host'])) {
        return false;
    }
    return strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '') === $host;
}

function cms_ip()
{
    return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
}

// Счётчик неудачных попыток входа за окно времени (по адресу посетителя).
function cms_fails($key, $window, $add = false, $clear = false)
{
    $name = 'rl/' . substr(hash('sha256', $key), 0, 32);
    $lock = cms_lock();
    $d = cms_read($name, []);
    $now = time();
    $old = (isset($d['t']) && is_array($d['t'])) ? $d['t'] : [];
    $times = [];
    foreach ($old as $t) {
        if ((int) $t > $now - $window) {
            $times[] = (int) $t;
        }
    }
    if ($clear) {
        $times = [];
    }
    if ($add) {
        $times[] = $now;
    }
    try {
        if ($times !== $old) {
            if ($times) {
                cms_write($name, ['t' => $times]);
            } else {
                cms_delete($name);
            }
        }
    } catch (Exception $e) {
        // счётчик — не критично
    }
    cms_unlock($lock);
    return count($times);
}

// ---------- Очистка текста ----------

function cms_utf8($s)
{
    return (is_string($s) && preg_match('//u', $s) === 1) ? $s : '';
}

function cms_strip_ctl($s)
{
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\xE2\x80[\xA8\xA9]/', '', $s);
}

// Пробелы: любая цепочка, где есть обычный пробел или перевод строки, — один пробел; одиночный неразрывный остаётся.
function cms_ws($s)
{
    return preg_replace('/[\x{00A0} ]*[ \t\r\n][\x{00A0} \t\r\n]*/u', ' ', $s);
}

function cms_trim($s)
{
    return preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $s);
}

function cms_esc_text($s)
{
    return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
}

function cms_attr_esc($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function cms_decode($s)
{
    return html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function cms_cut($s, $max)
{
    if (strlen($s) <= $max) {
        return $s;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $max, 'UTF-8');
    }
    preg_match('/^.{0,' . (int) min($max, 30000) . '}/us', $s, $m);
    return $m[0];
}

// Обычный текст (без оформления) → безопасный HTML.
function cms_plain($text, $max = 3000)
{
    return cms_esc_text(cms_cut(cms_trim(cms_ws(cms_strip_ctl(cms_utf8((string) $text)))), $max));
}

function cms_plain_from_html($html)
{
    return cms_plain(cms_decode(preg_replace('/<[^>]*>/', '', (string) $html)), 100000);
}

// Чистый текст из HTML (для подписей, описаний фото и т. п.)
function cms_text_of($html)
{
    return cms_decode(preg_replace('/<[^>]*>/', '', (string) $html));
}

function cms_attr_value($attrs, $name)
{
    if (preg_match('/(?:^|\s)' . preg_quote($name, '/') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $attrs, $m)) {
        if (isset($m[3]) && $m[3] !== '') {
            return $m[3];
        }
        if (isset($m[2]) && $m[2] !== '') {
            return $m[2];
        }
        return $m[1];
    }
    return null;
}

function cms_attr_has($attrs, $name)
{
    return preg_match('/(?:^|\s)' . preg_quote($name, '/') . '(?=[\s=\/]|$)/i', $attrs) === 1;
}

// Ссылки: только http(s), почта, телефон и адреса этого сайта. javascript: и прочее отбрасываются.
function cms_url_ok($u)
{
    $u = trim((string) $u);
    if ($u === '' || preg_match('/[\x00-\x1F\x7F<>"]/', $u)) {
        return '';
    }
    if (preg_match('#^(https?://[^\s/]+|mailto:[^\s]+|tel:[+0-9() -]+)#i', $u)) {
        return $u;
    }
    if (preg_match('#^(\#[\w.-]*|/(?!/)\S*|[\w.-]+\.html(\#[\w.-]*)?)$#u', $u)) {
        return $u;
    }
    return '';
}

function cms_text_seg($s)
{
    return cms_esc_text(cms_ws(cms_strip_ctl(cms_decode($s))));
}

// Текст с оформлением → безопасный HTML. Разрешено: жирный (b), курсив (em), ссылки, перенос строки;
// в режиме $block — ещё абзацы и списки. Остальные теги убираются (текст в них остаётся), скрипты — целиком.
function cms_rich($html, $block)
{
    static $inline = ['b' => 'b', 'strong' => 'b', 'i' => 'em', 'em' => 'em', 'a' => 'a', 'br' => 'br'];
    static $blocks = ['p' => 'p', 'div' => 'p', 'ul' => 'ul', 'ol' => 'ol', 'li' => 'li'];
    static $drop = ['script' => 1, 'style' => 1, 'template' => 1, 'iframe' => 1, 'object' => 1, 'embed' => 1, 'svg' => 1,
        'math' => 1, 'noscript' => 1, 'textarea' => 1, 'select' => 1, 'title' => 1, 'head' => 1, 'button' => 1,
        'canvas' => 1, 'video' => 1, 'audio' => 1, 'xml' => 1, 'frameset' => 1];
    static $lineEnds = ['p' => 1, 'div' => 1, 'li' => 1, 'tr' => 1, 'h1' => 1, 'h2' => 1, 'h3' => 1, 'h4' => 1, 'h5' => 1, 'h6' => 1,
        'blockquote' => 1, 'pre' => 1, 'section' => 1, 'article' => 1];
    $allowed = $block ? $inline + $blocks : $inline;
    $html = cms_strip_ctl(cms_utf8((string) $html));
    $out = '';
    $stack = [];
    $n = strlen($html);
    $i = 0;
    $closeTo = function ($k) use (&$stack, &$out) {
        while (count($stack) > $k) {
            $out .= '</' . array_pop($stack) . '>';
        }
    };
    while ($i < $n) {
        $lt = strpos($html, '<', $i);
        if ($lt === false) {
            $lt = $n;
        }
        if ($lt > $i) {
            $out .= cms_text_seg(substr($html, $i, $lt - $i));
            $i = $lt;
            continue;
        }
        if (substr($html, $i, 4) === '<!--') {
            $e = strpos($html, '-->', $i + 4);
            $i = $e === false ? $n : $e + 3;
            continue;
        }
        if (!preg_match('/<(\/?)([a-zA-Z][a-zA-Z0-9:-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/A', $html, $m, 0, $i)) {
            if (preg_match('/<[!?\/][^>]*>/A', $html, $m2, 0, $i)) {
                $i += strlen($m2[0]);
            } else {
                $out .= '&lt;';
                $i++;
            }
            continue;
        }
        $i += strlen($m[0]);
        $close = $m[1] === '/';
        $tag = strtolower($m[2]);
        $attrs = $m[3];
        if (isset($drop[$tag])) {
            if (!$close && substr(rtrim($attrs), -1) !== '/') {
                $e = stripos($html, '</' . $tag, $i);
                if ($e === false) {
                    $i = $n;
                } else {
                    $g = strpos($html, '>', $e);
                    $i = $g === false ? $n : $g + 1;
                }
            }
            continue;
        }
        if (!isset($allowed[$tag])) {
            if (!$block && $close && isset($lineEnds[$tag])) {
                $out .= '<br>';
            }
            continue;
        }
        $t = $allowed[$tag];
        if ($t === 'br') {
            if (!$close) {
                $out .= '<br>';
            }
            continue;
        }
        if ($close) {
            for ($k = count($stack) - 1; $k >= 0 && $stack[$k] !== $t; $k--) {
                // ищем открытый тег
            }
            if ($k >= 0) {
                $closeTo($k);
            }
            continue;
        }
        if ($t === 'p' || $t === 'ul' || $t === 'ol') {
            // блок закрывает открытые абзац и оформление; список может стоять внутри пункта списка
            for ($k = count($stack) - 1; $k >= 0 && in_array($stack[$k], ['b', 'em', 'a', 'p'], true); $k--) {
                // ищем, до какого уровня закрывать
            }
            $closeTo($k + 1);
        }
        if ($t === 'li') {
            $k = count($stack) - 1;
            while ($k >= 0 && !in_array($stack[$k], ['ul', 'ol'], true)) {
                $k--;
            }
            if ($k < 0) {
                $closeTo(0);
                $out .= '<ul>';
                $stack[] = 'ul';
            } else {
                $closeTo($k + 1);
            }
        }
        if ($t === 'a') {
            $pos = array_search('a', $stack, true);
            if ($pos !== false) {
                $closeTo($pos);
            }
            $href = cms_url_ok(cms_decode((string) cms_attr_value($attrs, 'href')));
            if ($href === '') {
                continue;
            }
            $tagOut = '<a href="' . cms_attr_esc($href) . '"';
            if (preg_match('#^https?://#i', $href)) {
                $tagOut .= ' target="_blank" rel="noopener"';
            }
            if (cms_attr_has($attrs, 'data-co-phone')) {
                $tagOut .= ' data-co-phone';
            }
            if (cms_attr_has($attrs, 'data-co-email')) {
                $tagOut .= ' data-co-email';
            }
            $out .= $tagOut . '>';
            $stack[] = 'a';
            continue;
        }
        if (($t === 'b' || $t === 'em') && in_array($t, $stack, true)) {
            continue;
        }
        $out .= '<' . $t . '>';
        $stack[] = $t;
    }
    $closeTo(0);
    // порядок: пустое оформление и пустые абзацы убираем, пробелы вокруг блоков и по краям — тоже
    do {
        $prev = $out;
        $out = preg_replace('#<(b|em)></\1>#', '', $out);
        $out = preg_replace('#<(b|em)>([ \x{00A0}]+)</\1>#u', '$2', $out);
        $out = preg_replace('#<a [^>]*></a>#', '', $out);
        if ($block) {
            $out = preg_replace('#<p>(?:\s|<br>)*</p>|<li>(?:\s|<br>)*</li>|<(ul|ol)>\s*</\1>#', '', $out);
        }
    } while ($out !== $prev);
    if ($block) {
        $out = preg_replace('#\s*(</?(?:p|ul|ol|li)>)\s*#', '$1', $out);
        $out = preg_replace('#(?:<br>\s*)+(</(?:p|li)>)#', '$1', $out);
        $out = preg_replace('#(<(?:p|li)>)(?:\s*<br>)+#', '$1', $out);
    }
    $out = preg_replace('#(?:<br>\s*){3,}#', '<br><br>', $out);
    $out = preg_replace('#^(?:[\s\x{00A0}]|<br>)+|(?:[\s\x{00A0}]|<br>)+$#u', '', $out);
    return $out;
}

// ---------- Ответы ----------

function cms_json($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cms_fail($code, $message, $status = 400, $extra = [])
{
    cms_json(['ok' => false, 'error' => $code, 'message' => $message] + $extra, $status);
}

function cms_no_cache_headers()
{
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: frame-ancestors 'none'");
    header('Referrer-Policy: same-origin');
}

function cms_asset_v($path)
{
    $f = CMS_ROOT . '/' . ltrim($path, '/');
    return is_file($f) ? (string) filemtime($f) : '0';
}
