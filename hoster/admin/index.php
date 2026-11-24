<?php
// Вход в кабинет администратора: https://mip.com.kz/admin/
// Самый первый вход — создание администратора по одноразовому коду. Код лежит в файле
// httpdocs/cms/data/setup-code.php, который видно только в панели хостинга (Plesk → «Файлы»);
// на странице он не показывается. После создания администратора файл удаляется.
define('MIP_CMS', 1);
require dirname(__DIR__) . '/cms/lib.php';

const CMS_DUMMY_HASH = '$2y$10$2zwx6wV4q4XmukdRZCd/jeFV2RBoXtiGEnBdreHoCUcs0S0.Z.S5e';
const CMS_CODE_ABC = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

function admin_redirect($to)
{
    header('Location: ' . $to, true, 303);
    exit;
}

// Одноразовый код первого входа (без дефисов); файл создаётся при первом открытии страницы.
function admin_setup_code($create)
{
    $f = cms_path('setup-code');
    $raw = is_file($f) ? (string) @file_get_contents($f) : '';
    if (preg_match('/([A-Z2-9]{4})-([A-Z2-9]{4})-([A-Z2-9]{4})/', $raw, $m)) {
        return $m[1] . $m[2] . $m[3];
    }
    if (!$create) {
        return '';
    }
    $c = '';
    for ($i = 0; $i < 12; $i++) {
        $c .= substr(CMS_CODE_ABC, random_int(0, strlen(CMS_CODE_ABC) - 1), 1);
    }
    $pretty = substr($c, 0, 4) . '-' . substr($c, 4, 4) . '-' . substr($c, 8, 4);
    if (!is_dir(CMS_DATA)) {
        @mkdir(CMS_DATA, 0750, true);
    }
    $text = CMS_EXIT . "Код для создания первого администратора сайта:\n\n    " . $pretty . "\n\n"
        . "Введите его на странице /admin/ сайта вместе с новым логином и паролем.\n"
        . "После создания администратора этот файл удалится сам.\n";
    if (@file_put_contents($f, $text) === false) {
        return '';
    }
    @chmod($f, 0640);
    return $c;
}

cms_no_cache_headers();
header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$users = cms_users();
$setup = !$users;
$sess = cms_session();
if ($sess && !$setup) {
    admin_redirect('/?cms=1');
}

$err = '';
$login = '';
$rlKey = 'login|' . cms_ip();
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = cms_norm_login(isset($_POST['login']) ? $_POST['login'] : '');
    $pass = isset($_POST['password']) ? (string) $_POST['password'] : '';
    if (!cms_same_origin()) {
        $err = 'Запрос отклонён. Откройте страницу входа заново.';
    } elseif (cms_fails($rlKey, 900) >= 8) {
        $err = 'Слишком много неудачных попыток. Подождите 15 минут и попробуйте снова.';
    } elseif ($setup) {
        $code = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', isset($_POST['code']) ? (string) $_POST['code'] : ''));
        $real = admin_setup_code(false);
        $problem = cms_password_problem($pass);
        if ($real === '' || !hash_equals($real, $code)) {
            cms_fails($rlKey, 900, true);
            $err = 'Код не подходит. Скопируйте его из файла setup-code.php ещё раз.';
        } elseif (!cms_valid_login($login)) {
            $err = 'Логин — от 3 до 32 символов: латинские буквы, цифры, точка, дефис или подчёркивание.';
        } elseif ($problem !== '') {
            $err = $problem . '.';
        } elseif (!isset($_POST['password2']) || $pass !== (string) $_POST['password2']) {
            $err = 'Пароли не совпадают.';
        } else {
            $lock = cms_lock();
            $users = cms_users();
            if ($users) {
                cms_unlock($lock);
                $setup = false;
                $err = 'Администратор уже создан — войдите со своим логином и паролем.';
            } else {
                $now = date('c');
                $users[$login] = ['hash' => password_hash($pass, PASSWORD_DEFAULT), 'epoch' => 1, 'created' => $now, 'by' => '', 'last' => $now];
                cms_save_users($users);
                cms_delete('setup-code');
                cms_unlock($lock);
                cms_fails($rlKey, 900, false, true);
                cms_login_as($login);
                admin_redirect('/?cms=1');
            }
        }
    } else {
        $u = isset($users[$login]) ? $users[$login] : null;
        $ok = password_verify($pass, ($u && isset($u['hash'])) ? (string) $u['hash'] : CMS_DUMMY_HASH);
        if (!$u || !$ok) {
            cms_fails($rlKey, 900, true);
            usleep(random_int(250000, 600000));
            $err = 'Неверный логин или пароль.';
        } else {
            cms_fails($rlKey, 900, false, true);
            $lock = cms_lock();
            $users = cms_users();
            if (isset($users[$login])) {
                if (password_needs_rehash($users[$login]['hash'], PASSWORD_DEFAULT)) {
                    $users[$login]['hash'] = password_hash($pass, PASSWORD_DEFAULT);
                }
                $users[$login]['last'] = date('c');
                cms_save_users($users);
            }
            cms_unlock($lock);
            cms_login_as($login);
            admin_redirect('/?cms=1');
        }
    }
}

$codeReady = $setup ? admin_setup_code(true) !== '' : true;
$h = function ($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
};
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $setup ? 'Создание администратора' : 'Вход в кабинет' ?> — MEDIA Информ Плюс</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<style>
  :root { --brand: #27449E; --ink: #0F2142; --ink-2: #44567A; --line: #DCE6F3; --bg: #F4F8FD; --err: #C23B3B; }
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: var(--bg); color: var(--ink);
    font: 400 16px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
  .card { width: 100%; max-width: 440px; background: #fff; border: 1px solid var(--line); border-radius: 20px; padding: 32px 28px;
    box-shadow: 0 2px 6px rgba(15, 33, 66, .05), 0 12px 32px rgba(15, 33, 66, .07); }
  .logo { display: block; height: 40px; width: auto; margin-bottom: 22px; }
  h1 { margin: 0 0 8px; font-size: 24px; line-height: 1.25; }
  p { margin: 0 0 14px; color: var(--ink-2); }
  ol { margin: 0 0 18px; padding-left: 20px; color: var(--ink-2); font-size: 15px; }
  ol li + li { margin-top: 4px; }
  code { font: 600 14px/1 ui-monospace, Menlo, Consolas, monospace; background: #EEF3FA; padding: 2px 6px; border-radius: 6px; color: var(--ink); }
  label { display: block; margin: 14px 0 6px; font-size: 14px; font-weight: 600; color: var(--ink-2); }
  input { width: 100%; min-height: 48px; padding: 11px 14px; border: 1px solid #C9D7EA; border-radius: 12px; font: inherit; color: var(--ink); background: #fff; }
  input:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 4px rgba(79, 134, 201, .22); }
  .hint { margin: 6px 0 0; font-size: 13px; color: #74849F; }
  button { width: 100%; min-height: 50px; margin-top: 22px; border: 0; border-radius: 12px; background: var(--brand); color: #fff; cursor: pointer;
    font: 700 15px/1 system-ui, sans-serif; letter-spacing: .02em; }
  button:hover { background: #1C3480; }
  .err { margin: 16px 0 0; padding: 12px 14px; border-radius: 12px; background: #FBECEC; color: var(--err); font-size: 15px; }
  .back { display: inline-block; margin-top: 18px; color: var(--brand); text-decoration: none; font-size: 14px; }
  .back:hover { text-decoration: underline; }
</style>
</head>
<body>
<main class="card">
  <img class="logo" src="/img/logo.svg" alt="MEDIA Информ Плюс" width="138" height="40">
<?php if ($setup): ?>
  <h1>Создание администратора</h1>
  <p>Это первый вход в кабинет. Чтобы никто посторонний не создал администратора раньше вас, нужен код с хостинга:</p>
  <ol>
    <li>Откройте панель хостинга Plesk → «Файлы».</li>
    <li>Перейдите в папку <code>httpdocs/cms/data</code> и откройте файл <code>setup-code.php</code>.</li>
    <li>Скопируйте код вида <code>XXXX-XXXX-XXXX</code> и вставьте его ниже.</li>
  </ol>
  <?php if (!$codeReady): ?>
  <div class="err">Не удалось создать файл с кодом. Проверьте в Plesk, что у папки httpdocs/cms/data есть права на запись, и обновите страницу.</div>
  <?php endif; ?>
  <form method="post" action="/admin/" autocomplete="off">
    <label for="code">Код из файла setup-code.php</label>
    <input id="code" name="code" required autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="XXXX-XXXX-XXXX">
    <label for="login">Логин нового администратора</label>
    <input id="login" name="login" required value="<?= $h($login) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="например, admin">
    <p class="hint">Латинские буквы, цифры, точка, дефис, подчёркивание.</p>
    <label for="password">Пароль</label>
    <input id="password" name="password" type="password" required minlength="10" autocomplete="new-password">
    <p class="hint">Не короче 10 символов. Лучше — фраза из нескольких слов.</p>
    <label for="password2">Пароль ещё раз</label>
    <input id="password2" name="password2" type="password" required minlength="10" autocomplete="new-password">
    <?php if ($err !== ''): ?><div class="err" role="alert"><?= $h($err) ?></div><?php endif; ?>
    <button type="submit">Создать и войти</button>
  </form>
<?php else: ?>
  <h1>Кабинет администратора</h1>
  <p>Войдите, чтобы редактировать тексты, фото и контакты сайта.</p>
  <form method="post" action="/admin/">
    <label for="login">Логин</label>
    <input id="login" name="login" required value="<?= $h($login) ?>" autocomplete="username" autocapitalize="none" spellcheck="false">
    <label for="password">Пароль</label>
    <input id="password" name="password" type="password" required autocomplete="current-password">
    <?php if ($err !== ''): ?><div class="err" role="alert"><?= $h($err) ?></div><?php endif; ?>
    <button type="submit">Войти</button>
  </form>
<?php endif; ?>
  <a class="back" href="/">← Вернуться на сайт</a>
</main>
</body>
</html>
