<?php
// Проверка движка кабинета перед выкладкой на hoster.kz:  php tools/cms-check.php <папка с распакованным архивом>
// 1) шаблон разбирается без ошибок; 2) без правок страница совпадает с шаблоном байт в байт;
// 3) «простые» поля не содержат тегов; 4) страница с правками во всех местах собирается без поломки разметки.
// Пишет только во временную папку — данные в <папка>/cms/data не трогает.
$root = isset($argv[1]) ? rtrim($argv[1], '/') : 'dist';
if (!is_file($root . '/cms/lib.php')) {
    fwrite(STDERR, "Нет $root/cms/lib.php — укажите папку с распакованным архивом\n");
    exit(2);
}
define('MIP_CMS', 1);
require $root . '/cms/lib.php';
require $root . '/cms/render.php';

$fails = 0;
function check($ok, $msg)
{
    global $fails;
    echo ($ok ? 'ок    ' : 'ОШИБКА ') . $msg . "\n";
    if (!$ok) {
        $fails++;
    }
}

$doc = cms_doc();
check($doc['bad'] === 0, 'шаблон разобран без несбалансированных тегов (' . $doc['bad'] . ')');
$empty = ['rev' => 0, 'at' => '', 'by' => '', 'text' => [], 'list' => [], 'img' => [], 'config' => null];
check(cms_render($empty, 'visitor') === cms_tpl(), 'без правок страница совпадает с шаблоном');

$S = cms_schema();
$plainTags = [];
foreach ($doc['texts'] as $key => $ids) {
    if (cms_mode(cms_attrs_of($ids[0])) === 'plain' && strpos(cms_raw_inner($ids[0]), '<') !== false) {
        $plainTags[] = $key;
    }
}
foreach ($doc['lists'] as $key => $id) {
    list($items) = cms_list_parts($id);
    foreach ($items as $it) {
        $f = [];
        cms_item_fields($it, $f);
        foreach ($f as $name => $info) {
            if ($info['mode'] === 'plain' && strpos(cms_raw_inner($info['id']), '<') !== false) {
                $plainTags[] = "$key.$name";
            }
        }
    }
}
check(!$plainTags, 'простые поля без тегов' . ($plainTags ? ': ' . implode(', ', array_unique($plainTags)) : ''));
check(count($S['text']) > 100 && count($S['list']) >= 10 && count($S['img']) >= 5,
    sprintf('ключей: тексты %d, списки %d, фото %d', count($S['text']), count($S['list']), count($S['img'])));

// правки во всех местах сразу
$c = $empty;
foreach ($S['text'] as $key => $t) {
    $c['text'][$key] = $t['mode'] === 'plain' ? cms_plain('Проверка ' . $key) : cms_rich('Проверка <b>' . $key . '</b>', $t['mode'] === 'block');
}
foreach ($S['list'] as $key => $L) {
    $rows = array_reverse($L['def']);
    array_pop($rows);
    $f = [];
    foreach ($L['fields'] as $name => $mode) {
        $f[$name] = $mode === 'plain' ? cms_plain('Новый ' . $name) : cms_rich('<p>Новый <em>' . $name . '</em></p>', $mode === 'block');
    }
    $rows[] = ['src' => $L['template'] ? -1 : 0, 'f' => $f];
    $c['list'][$key] = $rows;
}
foreach ($S['img'] as $key => $im) {
    $c['img'][$key] = ['src' => 'img/uploads/test-' . str_replace('.', '-', $key) . '.jpg', 'alt' => 'Фото ' . $key];
}
$c['config'] = ['company' => ['phone' => '+7 700 000 00 00', 'phoneHref' => '+77000000000', 'email' => 'test@example.com']];
foreach (['visitor', 'admin', 'edit'] as $mode) {
    $html = cms_render($c, $mode, ['login' => 'test', 'csrf' => 'x', 'rev' => 1]);
    $d = cms_parse($html);
    check($d['bad'] === 0, "страница с правками ($mode) без поломки разметки (" . $d['bad'] . ')');
    check(strpos($html, 'Проверка hero.lead') !== false && strpos($html, 'Проверка <b>hero.title</b>') !== false
        && strpos($html, 'img/uploads/test-hero.jpg') !== false
        && strpos($html, 'tel:+77000000000') !== false && strpos($html, 'window.SITE_OVERRIDES') !== false, "правки на месте ($mode)");
}
$edit = cms_render($c, 'edit', ['login' => 'test', 'csrf' => 'x', 'rev' => 1]);
check(strpos($edit, '/admin/editor.js') !== false && strpos($edit, 'window.CMS_BOOT') !== false && strpos($edit, 'data-edit-src="') !== false,
    'режим редактора: скрипт, данные и номера пунктов');
check(strpos(cms_render($c, 'visitor'), 'data-edit-src') === false && strpos(cms_render($c, 'visitor'), 'editor.js') === false,
    'у посетителей нет ничего от редактора');
check(strpos(cms_render($c, 'visitor'), 'id="extra" aria-label="Дополнительная информация" data-edit-hide-empty="extra">') !== false,
    'свои блоки показываются, когда они есть');

// очистка текста
check(cms_rich('<script>alert(1)</script><b onclick="x">Ж</b><a href="javascript:alert(1)">ссылка</a>', false) === '<b>Ж</b>ссылка',
    'очистка: скрипты, обработчики и javascript:-ссылки убираются');
check(cms_rich('<i>к</i> <strong>ж</strong> <a href="https://example.com">с</a>', false) === '<em>к</em> <b>ж</b> <a href="https://example.com" target="_blank" rel="noopener">с</a>',
    'очистка: курсив, жирный, внешняя ссылка');
check(cms_rich('<div>один</div><div>два<br></div><ul><li>три</li></ul>', true) === '<p>один</p><p>два</p><ul><li>три</li></ul>',
    'очистка: абзацы и списки в блоке');
check(cms_plain(" a\u{00A0}b  <c> & d ") === "a\u{00A0}b &lt;c&gt; &amp; d", 'очистка: простой текст');

echo $fails ? "\nОшибок: $fails\n" : "\nВсё в порядке\n";
exit($fails ? 1 : 0);
