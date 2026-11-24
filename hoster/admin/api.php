<?php
// API кабинета администратора — его вызывает редактор на странице /?cms=1.
// Только для вошедших: кука входа + заголовок X-CMS-CSRF + запрос с этого же сайта. Ответы — JSON.
define('MIP_CMS', 1);
require dirname(__DIR__) . '/cms/lib.php';
require dirname(__DIR__) . '/cms/render.php';

$action = isset($_GET['a']) ? (string) $_GET['a'] : '';
$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
if ($method !== 'POST' && $action !== 'export') {
    cms_fail('method', 'Нужен POST-запрос', 405);
}
$sess = cms_session();
if (!$sess) {
    cms_fail('auth', 'Время входа истекло — войдите снова', 401);
}
if (!cms_same_origin()) {
    cms_fail('origin', 'Запрос пришёл не с этого сайта', 403);
}
$csrf = isset($_SERVER['HTTP_X_CMS_CSRF']) ? (string) $_SERVER['HTTP_X_CMS_CSRF'] : '';
if ($csrf === '' && $action === 'export' && isset($_GET['csrf'])) {
    $csrf = (string) $_GET['csrf'];
}
// «token» — новый CSRF-ключ после повторного входа в другой вкладке (ответ может прочитать только этот же сайт)
if ($action === 'token') {
    cms_json(['ok' => true, 'csrf' => cms_csrf($sess), 'login' => $sess['login']]);
}
if (!hash_equals(cms_csrf($sess), $csrf)) {
    cms_fail('csrf', 'Страница редактора устарела — обновите её', 403);
}
// вход продлевается сам, пока администратор работает (метка та же — CSRF не меняется)
if ($sess['exp'] - time() < 6 * 3600) {
    $sess = cms_login_as($sess['login'], $sess['nonce']);
}

$in = [];
if ($action !== 'upload' && $action !== 'export') {
    $raw = (string) file_get_contents('php://input', false, null, 0, 3000000);
    $in = $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($in)) {
        cms_fail('json', 'Неверный запрос');
    }
}

function api_str($v, $max)
{
    return is_string($v) ? cms_cut(cms_trim(cms_ws(cms_strip_ctl(cms_utf8($v)))), $max) : '';
}

function api_img_path($v, $dirs)
{
    $v = is_string($v) ? trim($v) : '';
    return ($v === '' || preg_match('#^(' . $dirs . ')/[A-Za-z0-9._-]+\.(jpe?g|png|webp|svg)$#', $v)) ? $v : '';
}

// Контакты компании, специалисты и партнёры: проверка и приведение к виду site.config.js.
// Возвращает массив (только заданные поля), null — «как в site.config.js», строку — текст ошибки.
function api_config($c)
{
    if ($c === null || $c === []) {
        return null;
    }
    if (!is_array($c)) {
        return 'Неверные данные контактов';
    }
    $out = [];
    if (isset($c['company']) && is_array($c['company'])) {
        $co = [];
        foreach (['phone' => 40, 'email' => 120, 'hours' => 120] as $k => $max) {
            if (array_key_exists($k, $c['company'])) {
                $co[$k] = api_str($c['company'][$k], $max);
            }
        }
        if (isset($co['phone'])) {
            if ($co['phone'] === '') {
                unset($co['phone']);
            } else {
                $href = cms_tel_href($co['phone']);
                if (strlen($href) < 11) {
                    return 'Общий телефон компании не похож на номер';
                }
                $co['phoneHref'] = $href;
            }
        }
        if (isset($co['email']) && $co['email'] === '') {
            unset($co['email']);
        }
        if (isset($co['email']) && !filter_var($co['email'], FILTER_VALIDATE_EMAIL)) {
            return 'Почта компании указана с ошибкой';
        }
        if ($co) {
            $out['company'] = $co;
        }
    }
    if (isset($c['specialists']) && is_array($c['specialists'])) {
        $names = ['valuation' => 'оценки', 'replan' => 'перепланировки', 'deals' => 'риелтора'];
        foreach ($names as $dir => $label) {
            if (!isset($c['specialists'][$dir]) || !is_array($c['specialists'][$dir])) {
                continue;
            }
            $s = $c['specialists'][$dir];
            $o = [];
            $limits = ['title' => 40, 'role' => 80, 'about' => 160, 'name' => 120, 'phone' => 40, 'whatsapp' => 40,
                'telegram' => 64, 'instagram' => 64, 'email' => 120, 'address' => 200];
            foreach ($limits as $k => $max) {
                if (array_key_exists($k, $s)) {
                    $o[$k] = api_str($s[$k], $max);
                }
            }
            if (isset($o['title']) && $o['title'] === '') {
                unset($o['title']);
            }
            if (array_key_exists('photo', $s)) {
                $o['photo'] = api_img_path($s['photo'], 'uploads|team');
            }
            if (array_key_exists('avatarIcon', $s)) {
                $o['avatarIcon'] = (bool) $s['avatarIcon'];
            }
            if (isset($o['email']) && $o['email'] !== '' && !filter_var($o['email'], FILTER_VALIDATE_EMAIL)) {
                return 'Почта специалиста ' . $label . ' указана с ошибкой';
            }
            foreach (['phone', 'whatsapp'] as $k) {
                if (isset($o[$k]) && $o[$k] !== '' && strlen(cms_tel_href($o[$k])) < 11) {
                    return ($k === 'phone' ? 'Телефон' : 'WhatsApp') . ' специалиста ' . $label . ' не похож на номер';
                }
            }
            if ($o) {
                $out['specialists'][$dir] = $o;
            }
        }
    }
    if (array_key_exists('partners', $c)) {
        if (!is_array($c['partners']) || count($c['partners']) > 12) {
            return 'Слишком много партнёров (не больше 12)';
        }
        $list = [];
        foreach ($c['partners'] as $p) {
            if (!is_array($p)) {
                continue;
            }
            $arr = function ($v, $max, $limit) {
                $r = [];
                foreach (is_array($v) ? $v : [] as $x) {
                    $x = api_str($x, $max);
                    if ($x !== '' && count($r) < $limit) {
                        $r[] = $x;
                    }
                }
                return $r;
            };
            $o = [
                'name' => api_str(isset($p['name']) ? $p['name'] : '', 120),
                'role' => api_str(isset($p['role']) ? $p['role'] : '', 80),
                'logo' => api_img_path(isset($p['logo']) ? $p['logo'] : '', 'uploads|partners'),
                'lead' => api_str(isset($p['lead']) ? $p['lead'] : '', 300),
                'services' => $arr(isset($p['services']) ? $p['services'] : [], 200, 20),
                'address' => api_str(isset($p['address']) ? $p['address'] : '', 200),
                'phones' => $arr(isset($p['phones']) ? $p['phones'] : [], 40, 6),
                'email' => api_str(isset($p['email']) ? $p['email'] : '', 120),
            ];
            if ($o['name'] === '') {
                continue;
            }
            if ($o['email'] !== '' && !filter_var($o['email'], FILTER_VALIDATE_EMAIL)) {
                return 'Почта партнёра «' . $o['name'] . '» указана с ошибкой';
            }
            $list[] = $o;
        }
        $out['partners'] = $list;
    }
    return $out ? $out : null;
}

function api_history_write($content, $note, $keys)
{
    cms_write('history/' . sprintf('%06d', $content['rev']), [
        'rev' => $content['rev'], 'at' => $content['at'], 'by' => $content['by'], 'note' => $note,
        'keys' => array_slice($keys, 0, 80), 'content' => $content,
    ]);
    $files = glob(CMS_DATA . '/history/*.php');
    if (is_array($files) && count($files) > CMS_KEEP) {
        sort($files);
        foreach (array_slice($files, 0, count($files) - CMS_KEEP) as $f) {
            @unlink($f);
        }
    }
}

// Что изменилось между двумя наборами правок (для истории)
function api_diff($a, $b)
{
    $keys = [];
    foreach (['text', 'list', 'img'] as $part) {
        $all = array_unique(array_merge(array_keys($a[$part]), array_keys($b[$part])));
        foreach ($all as $k) {
            $x = isset($a[$part][$k]) ? $a[$part][$k] : null;
            $y = isset($b[$part][$k]) ? $b[$part][$k] : null;
            if ($x !== $y) {
                $keys[] = $part . ':' . $k;
            }
        }
    }
    if ($a['config'] != $b['config']) {
        $keys[] = 'config';
    }
    return $keys;
}

function api_note($keys)
{
    $n = ['text' => 0, 'list' => 0, 'img' => 0, 'config' => 0];
    foreach ($keys as $k) {
        $p = strpos($k, ':') === false ? $k : substr($k, 0, strpos($k, ':'));
        if (isset($n[$p])) {
            $n[$p]++;
        }
    }
    $parts = [];
    if ($n['text']) {
        $parts[] = 'тексты: ' . $n['text'];
    }
    if ($n['list']) {
        $parts[] = 'списки: ' . $n['list'];
    }
    if ($n['img']) {
        $parts[] = 'фото: ' . $n['img'];
    }
    if ($n['config']) {
        $parts[] = 'контакты и партнёры';
    }
    return $parts ? 'Изменены ' . implode(', ', $parts) : 'Без изменений';
}

function api_users_list($sess)
{
    $out = [];
    foreach (cms_users() as $login => $u) {
        $out[] = ['login' => $login, 'created' => isset($u['created']) ? $u['created'] : '', 'last' => isset($u['last']) ? $u['last'] : '',
            'me' => $login === $sess['login']];
    }
    return $out;
}

switch ($action) {
    case 'state':
        $c = cms_content();
        cms_json(['ok' => true, 'rev' => $c['rev'], 'at' => $c['at'], 'by' => $c['by'], 'login' => $sess['login']]);
        break;

    case 'save':
        $S = cms_schema();
        $lock = cms_lock();
        $cur = cms_content();
        if (empty($in['force']) && isset($in['rev']) && (int) $in['rev'] !== $cur['rev']) {
            cms_unlock($lock);
            cms_fail('conflict', 'Пока вы редактировали, сайт сохранил другой администратор', 409,
                ['rev' => $cur['rev'], 'at' => $cur['at'], 'by' => $cur['by']]);
        }
        $new = $cur;
        $bad = [];
        foreach ((isset($in['text']) && is_array($in['text'])) ? $in['text'] : [] as $key => $v) {
            $key = (string) $key;
            if (!isset($S['text'][$key])) {
                continue;
            }
            if ($v === null) {
                unset($new['text'][$key]);
                continue;
            }
            $mode = $S['text'][$key]['mode'];
            if (!is_string($v) || strlen($v) > ($mode === 'block' ? 60000 : 12000)) {
                $bad[] = $key;
                continue;
            }
            $c = $mode === 'plain' ? cms_plain($v) : cms_rich($v, $mode === 'block');
            if ($c === $S['text'][$key]['def']) {
                unset($new['text'][$key]);
            } else {
                $new['text'][$key] = $c;
            }
        }
        foreach ((isset($in['list']) && is_array($in['list'])) ? $in['list'] : [] as $key => $items) {
            $key = (string) $key;
            if (!isset($S['list'][$key])) {
                continue;
            }
            if ($items === null) {
                unset($new['list'][$key]);
                continue;
            }
            $L = $S['list'][$key];
            if (!is_array($items) || count($items) > 100) {
                $bad[] = $key;
                continue;
            }
            $rows = [];
            foreach ($items as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $src = isset($row['src']) ? (int) $row['src'] : -1;
                if ($src < 0 || $src >= $L['count']) {
                    $src = $L['template'] ? -1 : $L['count'] - 1;
                }
                if ($src < -1 || ($src === -1 && !$L['template'])) {
                    continue;
                }
                $f = [];
                foreach ($L['fields'] as $name => $mode) {
                    $v = (isset($row['f'][$name]) && is_string($row['f'][$name])) ? $row['f'][$name] : '';
                    if (strlen($v) > ($mode === 'block' ? 60000 : 12000)) {
                        $bad[] = $key;
                        $v = '';
                    }
                    $f[$name] = $mode === 'plain' ? cms_plain($v) : cms_rich($v, $mode === 'block');
                }
                $rows[] = ['src' => $src, 'f' => $f];
            }
            if ($rows === $L['def']) {
                unset($new['list'][$key]);
            } else {
                $new['list'][$key] = $rows;
            }
        }
        foreach ((isset($in['img']) && is_array($in['img'])) ? $in['img'] : [] as $key => $im) {
            $key = (string) $key;
            if (!isset($S['img'][$key])) {
                continue;
            }
            if ($im === null) {
                unset($new['img'][$key]);
                continue;
            }
            $src = (is_array($im) && isset($im['src'])) ? (string) $im['src'] : '';
            if (!preg_match('#^img/uploads/[a-z0-9-]+\.(jpg|png)$#', $src) || !is_file(CMS_ROOT . '/' . $src)) {
                $bad[] = $key;
                continue;
            }
            $alt = isset($im['alt']) ? cms_text_of(cms_plain((string) $im['alt'], 300)) : $S['img'][$key]['alt'];
            $new['img'][$key] = ['src' => $src, 'alt' => $alt];
        }
        if (array_key_exists('config', $in)) {
            $cfg = api_config($in['config']);
            if (is_string($cfg)) {
                cms_unlock($lock);
                cms_fail('config', $cfg);
            }
            $new['config'] = $cfg;
        }
        if ($bad) {
            cms_unlock($lock);
            cms_fail('invalid', 'Не удалось сохранить: неверные данные (' . implode(', ', array_unique($bad)) . ')');
        }
        $keys = api_diff($cur, $new);
        if (!$keys) {
            cms_unlock($lock);
            cms_json(['ok' => true, 'rev' => $cur['rev'], 'at' => $cur['at'], 'by' => $cur['by'], 'changed' => 0]);
        }
        $new['rev'] = $cur['rev'] + 1;
        $new['at'] = date('c');
        $new['by'] = $sess['login'];
        try {
            cms_write('content', $new);
            api_history_write($new, api_note($keys), $keys);
        } catch (Exception $e) {
            cms_unlock($lock);
            cms_fail('write', $e->getMessage(), 500);
        }
        cms_unlock($lock);
        cms_json(['ok' => true, 'rev' => $new['rev'], 'at' => $new['at'], 'by' => $new['by'], 'changed' => count($keys)]);
        break;

    case 'upload':
        $max = 12 * 1048576;
        $len = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($len > $max) {
            cms_fail('big', 'Файл больше 12 МБ');
        }
        $data = file_get_contents('php://input', false, null, 0, $max + 1);
        if (!is_string($data) || $data === '') {
            cms_fail('empty', 'Файл не дошёл до сервера — попробуйте фото поменьше');
        }
        if (strlen($data) > $max) {
            cms_fail('big', 'Файл больше 12 МБ');
        }
        $dir = CMS_ROOT . '/img/uploads';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            cms_fail('write', 'Не удалось создать папку img/uploads', 500);
        }
        if (!is_dir(CMS_DATA)) {
            @mkdir(CMS_DATA, 0750, true);
        }
        $tmp = CMS_DATA . '/upload-' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $data) === false) {
            cms_fail('write', 'Не удалось сохранить файл на сервере', 500);
        }
        $info = @getimagesize($tmp);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            @unlink($tmp);
            cms_fail('type', 'Это не фотография в формате JPG или PNG');
        }
        if ($info[0] < 16 || $info[1] < 16 || $info[0] > 8000 || $info[1] > 8000) {
            @unlink($tmp);
            cms_fail('size', 'Слишком маленькое или слишком большое изображение');
        }
        $name = date('Ymd') . '-' . bin2hex(random_bytes(5)) . ($info[2] === IMAGETYPE_PNG ? '.png' : '.jpg');
        if (!@rename($tmp, $dir . '/' . $name)) {
            @unlink($tmp);
            cms_fail('write', 'Не удалось сохранить фото в img/uploads', 500);
        }
        @chmod($dir . '/' . $name, 0644);
        cms_json(['ok' => true, 'src' => 'img/uploads/' . $name, 'w' => $info[0], 'h' => $info[1]]);
        break;

    case 'history':
        $files = glob(CMS_DATA . '/history/*.php');
        $files = is_array($files) ? $files : [];
        rsort($files);
        $items = [];
        foreach (array_slice($files, 0, CMS_KEEP) as $f) {
            $hrec = cms_read('history/' . basename($f, '.php'), null);
            if (is_array($hrec) && isset($hrec['rev'])) {
                $items[] = ['rev' => (int) $hrec['rev'], 'at' => (string) $hrec['at'], 'by' => (string) $hrec['by'],
                    'note' => isset($hrec['note']) ? (string) $hrec['note'] : ''];
            }
        }
        $c = cms_content();
        cms_json(['ok' => true, 'items' => $items, 'rev' => $c['rev']]);
        break;

    case 'restore':
        $rev = isset($in['rev']) ? (int) $in['rev'] : -1;
        $lock = cms_lock();
        $cur = cms_content();
        if ($rev === 0) {
            $snap = ['text' => [], 'list' => [], 'img' => [], 'config' => null];
            $note = 'Сброс к исходному виду сайта';
        } else {
            $hrec = $rev > 0 ? cms_read('history/' . sprintf('%06d', $rev), null) : null;
            if (!is_array($hrec) || !isset($hrec['content']) || !is_array($hrec['content'])) {
                cms_unlock($lock);
                cms_fail('notfound', 'Такой версии нет в истории');
            }
            $snap = $hrec['content'];
            $note = 'Возврат к версии №' . $rev;
        }
        $new = [
            'rev' => $cur['rev'] + 1, 'at' => date('c'), 'by' => $sess['login'],
            'text' => (isset($snap['text']) && is_array($snap['text'])) ? $snap['text'] : [],
            'list' => (isset($snap['list']) && is_array($snap['list'])) ? $snap['list'] : [],
            'img' => (isset($snap['img']) && is_array($snap['img'])) ? $snap['img'] : [],
            'config' => (isset($snap['config']) && is_array($snap['config']) && $snap['config']) ? $snap['config'] : null,
        ];
        try {
            cms_write('content', $new);
            api_history_write($new, $note, api_diff($cur, $new));
        } catch (Exception $e) {
            cms_unlock($lock);
            cms_fail('write', $e->getMessage(), 500);
        }
        cms_unlock($lock);
        cms_json(['ok' => true, 'rev' => $new['rev']]);
        break;

    case 'export':
        $c = cms_content();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="mip-site-' . date('Y-m-d') . '-v' . $c['rev'] . '.json"');
        header('Cache-Control: no-store');
        echo json_encode(['site' => 'mip.com.kz', 'exported' => date('c'), 'content' => $c],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;

    case 'users':
        cms_json(['ok' => true, 'users' => api_users_list($sess)]);
        break;

    case 'user_add':
        $login = cms_norm_login(isset($in['login']) ? $in['login'] : '');
        $pass = isset($in['password']) ? (string) $in['password'] : '';
        if (!cms_valid_login($login)) {
            cms_fail('login', 'Логин — от 3 до 32 символов: латинские буквы, цифры, точка, дефис или подчёркивание');
        }
        $problem = cms_password_problem($pass);
        if ($problem !== '') {
            cms_fail('password', $problem);
        }
        $lock = cms_lock();
        $users = cms_users();
        if (isset($users[$login])) {
            cms_unlock($lock);
            cms_fail('exists', 'Такой логин уже есть');
        }
        if (count($users) >= 10) {
            cms_unlock($lock);
            cms_fail('limit', 'Не больше 10 администраторов');
        }
        $users[$login] = ['hash' => password_hash($pass, PASSWORD_DEFAULT), 'epoch' => 1, 'created' => date('c'), 'by' => $sess['login'], 'last' => ''];
        cms_save_users($users);
        cms_unlock($lock);
        cms_json(['ok' => true, 'users' => api_users_list($sess)]);
        break;

    case 'user_del':
        $login = cms_norm_login(isset($in['login']) ? $in['login'] : '');
        if ($login === $sess['login']) {
            cms_fail('self', 'Себя удалить нельзя — это может сделать другой администратор');
        }
        $lock = cms_lock();
        $users = cms_users();
        if (!isset($users[$login])) {
            cms_unlock($lock);
            cms_fail('notfound', 'Нет такого администратора');
        }
        unset($users[$login]);
        cms_save_users($users);
        cms_unlock($lock);
        cms_json(['ok' => true, 'users' => api_users_list($sess)]);
        break;

    case 'password':
        $old = isset($in['old']) ? (string) $in['old'] : '';
        $pass = isset($in['password']) ? (string) $in['password'] : '';
        $problem = cms_password_problem($pass);
        if ($problem !== '') {
            cms_fail('password', $problem);
        }
        $lock = cms_lock();
        $users = cms_users();
        $me = $sess['login'];
        if (!isset($users[$me]) || !password_verify($old, (string) $users[$me]['hash'])) {
            cms_unlock($lock);
            cms_fail('old', 'Текущий пароль указан неверно');
        }
        $users[$me]['hash'] = password_hash($pass, PASSWORD_DEFAULT);
        $users[$me]['epoch'] = (isset($users[$me]['epoch']) ? (int) $users[$me]['epoch'] : 0) + 1;
        cms_save_users($users);
        cms_unlock($lock);
        $sess = cms_login_as($me);
        cms_json(['ok' => true, 'csrf' => cms_csrf($sess)]);
        break;

    case 'logout':
        cms_logout();
        cms_json(['ok' => true]);
        break;

    case 'logout_all':
        $lock = cms_lock();
        $users = cms_users();
        $me = $sess['login'];
        if (isset($users[$me])) {
            $users[$me]['epoch'] = (isset($users[$me]['epoch']) ? (int) $users[$me]['epoch'] : 0) + 1;
            cms_save_users($users);
        }
        cms_unlock($lock);
        cms_logout();
        cms_json(['ok' => true]);
        break;

    default:
        cms_fail('action', 'Неизвестное действие', 404);
}
