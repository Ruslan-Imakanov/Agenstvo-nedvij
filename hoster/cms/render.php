<?php
// Страница сайта = шаблон cms/page.tpl (это public/index.html) + правки из кабинета.
// Шаблон разбирается на элементы; меняются только элементы с ключами data-edit*, всё остальное
// копируется из шаблона байт в байт — без правок страница совпадает с index.html.
//
// Ключи в разметке public/index.html:
//   data-edit="ключ"             — текст элемента; data-edit-rich — можно жирный, курсив, ссылки; ="block" — ещё абзацы и списки
//   data-edit-list="ключ"        — список: пункты data-edit-item, поля в них data-edit-field="имя", номер пункта data-edit-auto,
//                                  образец нового пункта <template data-edit-template>
//   data-edit-img="ключ"         — фото (у первого экрана ещё data-edit-img-preload и data-edit-img-meta)
//   data-edit-attr="ключ"        — атрибут content у <meta> (заголовок и описание для поиска)
//   data-edit-hide-empty="ключ"  — блок скрыт, пока список «ключ» пуст
//   data-co-phone, data-co-email — общий телефон и почта компании (из окна «Контакты»)
// Ключи seo.* и nav.* (пункты меню, стоят в трёх меню сразу) правятся в окне «Меню и поиск».
if (!defined('MIP_CMS')) {
    http_response_code(404);
    exit;
}

function cms_tpl()
{
    static $t = null;
    if ($t === null) {
        $t = is_file(CMS_TPL) ? (string) @file_get_contents(CMS_TPL) : '';
        if ($t === '') {
            throw new RuntimeException('Нет шаблона страницы cms/page.tpl');
        }
    }
    return $t;
}

function cms_attrs_parse($s)
{
    $out = [];
    if (trim($s) === '' || trim($s) === '/') {
        return $out;
    }
    preg_match_all('/([^\s"\'>\/=]+)(\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/', $s, $mm, PREG_SET_ORDER);
    foreach ($mm as $m) {
        $name = strtolower($m[1]);
        if (isset($out[$name])) {
            continue;
        }
        if (!isset($m[2]) || $m[2] === '') {
            $out[$name] = true;
            continue;
        }
        if (isset($m[5]) && $m[5] !== '') {
            $v = $m[5];
        } elseif (isset($m[4]) && $m[4] !== '') {
            $v = $m[4];
        } else {
            $v = isset($m[3]) ? $m[3] : '';
        }
        $out[$name] = cms_decode($v);
    }
    return $out;
}

function cms_start_tag($tag, $attrs)
{
    $s = '<' . $tag;
    foreach ($attrs as $k => $v) {
        if ($v === null || $v === false) {
            continue;
        }
        $s .= ' ' . $k . ($v === true ? '' : '="' . cms_attr_esc($v) . '"');
    }
    return $s . '>';
}

// Шаблон страницы, разобранный один раз за запрос
function cms_doc()
{
    static $doc = null;
    if ($doc === null) {
        $doc = cms_parse(cms_tpl());
    }
    return $doc;
}

// Разбор HTML: список элементов с позициями в тексте (начало, конец открывающего тега,
// начало закрывающего, конец) и детьми; индексы ключей data-edit*. bad — число несбалансированных тегов.
function cms_parse($html)
{
    $void =['area' => 1, 'base' => 1, 'br' => 1, 'col' => 1, 'embed' => 1, 'hr' => 1, 'img' => 1, 'input' => 1, 'link' => 1,
        'meta' => 1, 'param' => 1, 'source' => 1, 'track' => 1, 'wbr' => 1];
    $raw = ['script' => 1, 'style' => 1, 'title' => 1, 'textarea' => 1];
    $els = [];
    $roots = [];
    $stack = [];
    $bad = 0;
    $n = strlen($html);
    $off = 0;
    $re = '/<!--.*?-->|<(\/?)([a-zA-Z][a-zA-Z0-9-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/s';
    while ($off < $n && preg_match($re, $html, $m, PREG_OFFSET_CAPTURE, $off)) {
        $pos = $m[0][1];
        $off = $pos + strlen($m[0][0]);
        if (count($m) < 3) {
            continue;   // комментарий
        }
        $tag = strtolower($m[2][0]);
        if ($m[1][0] === '/') {
            for ($k = count($stack) - 1; $k >= 0 && $els[$stack[$k]]['t'] !== $tag; $k--) {
                // ищем открытый элемент
            }
            if ($k < 0) {
                $bad++;
                continue;
            }
            while (count($stack) - 1 > $k) {
                $id = array_pop($stack);
                $els[$id]['c'] = $els[$id]['e'] = $pos;
                $bad++;
            }
            $id = array_pop($stack);
            $els[$id]['c'] = $pos;
            $els[$id]['e'] = $off;
            continue;
        }
        $attrs = $m[3][0];
        $id = count($els);
        $parent = $stack ? $stack[count($stack) - 1] : -1;
        $els[] = ['t' => $tag, 's' => $pos, 'o' => $off, 'c' => $off, 'e' => $off, 'a' => $attrs, 'p' => $parent, 'k' => []];
        if ($parent >= 0) {
            $els[$parent]['k'][] = $id;
        } else {
            $roots[] = $id;
        }
        if (isset($void[$tag]) || substr(rtrim($attrs), -1) === '/') {
            continue;
        }
        if (isset($raw[$tag])) {
            $j = stripos($html, '</' . $tag, $off);
            if ($j === false) {
                $els[$id]['c'] = $els[$id]['e'] = $n;
                $off = $n;
                $bad++;
            } else {
                $g = strpos($html, '>', $j);
                $els[$id]['c'] = $j;
                $els[$id]['e'] = $off = ($g === false ? $n : $g + 1);
            }
            continue;
        }
        $stack[] = $id;
    }
    foreach ($stack as $id) {
        $els[$id]['c'] = $els[$id]['e'] = $n;
        $bad++;
    }
    // индексы ключей
    $texts = $lists = $imgs = $attrKeys = [];
    foreach ($els as $id => $e) {
        if (strpos($e['a'], 'data-edit') === false) {
            continue;
        }
        $a = cms_attrs_parse($e['a']);
        $els[$id]['A'] = $a;
        if (isset($a['data-edit']) && is_string($a['data-edit'])) {
            $texts[$a['data-edit']][] = $id;
        }
        if (isset($a['data-edit-attr']) && is_string($a['data-edit-attr'])) {
            $attrKeys[$a['data-edit-attr']][] = $id;
        }
        if (isset($a['data-edit-list']) && is_string($a['data-edit-list'])) {
            $lists[$a['data-edit-list']] = $id;
        }
        if (isset($a['data-edit-img']) && is_string($a['data-edit-img'])) {
            $imgs[$a['data-edit-img']][] = $id;
        }
    }
    return ['html' => $html, 'els' => $els, 'roots' => $roots, 'bad' => $bad,
        'texts' => $texts, 'lists' => $lists, 'imgs' => $imgs, 'attrKeys' => $attrKeys];
}

function cms_attrs_of($id)
{
    $doc = cms_doc();
    $e = $doc['els'][$id];
    return isset($e['A']) ? $e['A'] : cms_attrs_parse($e['a']);
}

function cms_raw_inner($id)
{
    $doc = cms_doc();
    $e = $doc['els'][$id];
    return substr($doc['html'], $e['o'], $e['c'] - $e['o']);
}

function cms_mode($a)
{
    if (!isset($a['data-edit-rich'])) {
        return 'plain';
    }
    return $a['data-edit-rich'] === 'block' ? 'block' : 'inline';
}

function cms_canon($html, $mode)
{
    return $mode === 'plain' ? cms_plain_from_html($html) : cms_rich($html, $mode === 'block');
}

// Пункты списка (прямые дети с data-edit-item) и образец из <template data-edit-template>.
function cms_list_parts($id)
{
    $doc = cms_doc();
    $items = [];
    $proto = null;
    foreach ($doc['els'][$id]['k'] as $k) {
        $a = cms_attrs_of($k);
        if (isset($a['data-edit-item'])) {
            $items[] = $k;
        } elseif ($doc['els'][$k]['t'] === 'template' && isset($a['data-edit-template']) && $doc['els'][$k]['k']) {
            $proto = $doc['els'][$k]['k'][0];
        }
    }
    return [$items, $proto];
}

// Поля пункта: элементы с data-edit-field внутри пункта (или сам пункт).
function cms_item_fields($id, &$out)
{
    $doc = cms_doc();
    $a = cms_attrs_of($id);
    if (isset($a['data-edit-field']) && is_string($a['data-edit-field'])) {
        $out[$a['data-edit-field']] = ['id' => $id, 'mode' => cms_mode($a)];
        return;
    }
    foreach ($doc['els'][$id]['k'] as $k) {
        cms_item_fields($k, $out);
    }
}

// Что можно править и как это выглядит без правок — для проверки сохранений.
function cms_schema()
{
    static $S = null;
    if ($S !== null) {
        return $S;
    }
    $doc = cms_doc();
    $S = ['text' => [], 'list' => [], 'img' => []];
    foreach ($doc['texts'] as $key => $ids) {
        $a = cms_attrs_of($ids[0]);
        $mode = cms_mode($a);
        $S['text'][$key] = ['mode' => $mode, 'def' => cms_canon(cms_raw_inner($ids[0]), $mode)];
    }
    foreach ($doc['attrKeys'] as $key => $ids) {
        if (!isset($S['text'][$key])) {
            $a = cms_attrs_of($ids[0]);
            $S['text'][$key] = ['mode' => 'plain', 'def' => cms_plain(isset($a['content']) ? (string) $a['content'] : '')];
        }
    }
    foreach ($doc['lists'] as $key => $id) {
        list($items, $proto) = cms_list_parts($id);
        $fields = [];
        foreach (array_merge($items, $proto === null ? [] : [$proto]) as $it) {
            $f = [];
            cms_item_fields($it, $f);
            foreach ($f as $name => $info) {
                $fields[$name] = $info['mode'];
            }
        }
        $def = [];
        foreach ($items as $i => $it) {
            $f = [];
            cms_item_fields($it, $f);
            $vals = [];
            foreach ($fields as $name => $mode) {
                $vals[$name] = isset($f[$name]) ? cms_canon(cms_raw_inner($f[$name]['id']), $mode) : '';
            }
            $def[] = ['src' => $i, 'f' => $vals];
        }
        $S['list'][$key] = ['fields' => $fields, 'def' => $def, 'count' => count($items), 'template' => $proto !== null];
    }
    foreach ($doc['imgs'] as $key => $ids) {
        $a = cms_attrs_of($ids[0]);
        $S['img'][$key] = ['src' => isset($a['src']) ? (string) $a['src'] : '', 'alt' => isset($a['alt']) ? (string) $a['alt'] : ''];
    }
    return $S;
}

// ---------- Вывод страницы ----------

function cms_inner(&$R, $id)
{
    $doc = cms_doc();
    $html = $doc['html'];
    if ($id < 0) {
        $kids = $doc['roots'];
        $from = 0;
        $to = strlen($html);
    } else {
        $e = $doc['els'][$id];
        $kids = $e['k'];
        $from = $e['o'];
        $to = $e['c'];
    }
    $out = '';
    $cur = $from;
    foreach ($kids as $k) {
        $ke = $doc['els'][$k];
        $out .= substr($html, $cur, $ke['s'] - $cur) . cms_emit($R, $k);
        $cur = $ke['e'];
    }
    return $out . substr($html, $cur, $to - $cur);
}

// Содержимое ссылки с общим телефоном/почтой: текст в самой ссылке или в её <b> (телефон) / <span> (почта).
function cms_co_inner(&$R, $id, $childTag, $text)
{
    $doc = cms_doc();
    $e = $doc['els'][$id];
    if (!$e['k']) {
        return cms_esc_text($text);
    }
    $html = $doc['html'];
    $out = '';
    $cur = $e['o'];
    $done = false;
    foreach ($e['k'] as $k) {
        $ke = $doc['els'][$k];
        $out .= substr($html, $cur, $ke['s'] - $cur);
        if (!$done && $ke['t'] === $childTag) {
            $out .= substr($html, $ke['s'], $ke['o'] - $ke['s']) . cms_esc_text($text) . substr($html, $ke['c'], $ke['e'] - $ke['c']);
            $done = true;
        } else {
            $out .= cms_emit($R, $k);
        }
        $cur = $ke['e'];
    }
    return $out . substr($html, $cur, $e['c'] - $cur);
}

function cms_list_count(&$R, $key)
{
    if (isset($R['ov']['list'][$key]) && is_array($R['ov']['list'][$key])) {
        return count($R['ov']['list'][$key]);
    }
    $S = cms_schema();
    return isset($S['list'][$key]) ? $S['list'][$key]['count'] : 0;
}

function cms_list_inner(&$R, $id, $key)
{
    $doc = cms_doc();
    $html = $doc['html'];
    $e = $doc['els'][$id];
    list($items, $proto) = cms_list_parts($id);
    $ov = (isset($R['ov']['list'][$key]) && is_array($R['ov']['list'][$key])) ? $R['ov']['list'][$key] : null;
    $gen = [];
    if ($ov === null) {
        foreach ($items as $i => $it) {
            $gen[] = ['src' => $i, 'f' => null];
        }
    } else {
        foreach ($ov as $row) {
            if (is_array($row)) {
                $gen[] = ['src' => isset($row['src']) ? (int) $row['src'] : -1, 'f' => (isset($row['f']) && is_array($row['f'])) ? $row['f'] : []];
            }
        }
    }
    $sep = count($items) >= 2 ? substr($html, $doc['els'][$items[0]]['e'], $doc['els'][$items[1]]['s'] - $doc['els'][$items[0]]['e']) : '';
    $parts = [];
    foreach ($gen as $i => $g) {
        $src = $g['src'];
        if ($src >= 0 && $src < count($items)) {
            $pid = $items[$src];
        } elseif ($proto !== null) {
            $pid = $proto;
            $src = -1;
        } elseif ($items) {
            $pid = $items[count($items) - 1];
            $src = count($items) - 1;
        } else {
            continue;
        }
        $saved = [$R['fields'], $R['num'], $R['itemRoot'], $R['itemSrc']];
        $R['fields'] = $g['f'];
        $R['num'] = $i + 1;
        $R['itemRoot'] = $pid;
        $R['itemSrc'] = $src;
        $parts[] = cms_emit($R, $pid);
        list($R['fields'], $R['num'], $R['itemRoot'], $R['itemSrc']) = $saved;
    }
    $gens = implode($sep, $parts);
    // остальное содержимое контейнера (пробелы, <template>) остаётся на месте; пункты встают туда, где были
    $out = '';
    $cur = $e['o'];
    $placed = false;
    $itemSet = array_flip($items);
    foreach ($e['k'] as $k) {
        $ke = $doc['els'][$k];
        if (isset($itemSet[$k])) {
            if (!$placed) {
                $out .= substr($html, $cur, $ke['s'] - $cur) . $gens;
                $placed = true;
            }
            $cur = $ke['e'];
            continue;
        }
        $out .= substr($html, $cur, $ke['s'] - $cur) . cms_emit($R, $k);
        $cur = $ke['e'];
    }
    if (!$placed) {
        $out .= substr($html, $cur, $e['c'] - $cur) . $gens;
        return $out;
    }
    return $out . substr($html, $cur, $e['c'] - $cur);
}

function cms_ld_json(&$R, $json)
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG;
    if ($R['phoneHref'] !== null) {
        $json = preg_replace_callback('/"telephone"\s*:\s*"[^"]*"/', function () use ($R, $flags) {
            return '"telephone":' . json_encode($R['phoneHref'], $flags);
        }, $json);
    }
    if ($R['email'] !== null) {
        $json = preg_replace_callback('/"email"\s*:\s*"[^"]*"/', function () use ($R, $flags) {
            return '"email":' . json_encode($R['email'], $flags);
        }, $json);
    }
    return $json;
}

function cms_emit(&$R, $id)
{
    $doc = cms_doc();
    $html = $doc['html'];
    $e = $doc['els'][$id];
    $open = substr($html, $e['s'], $e['o'] - $e['s']);
    $close = substr($html, $e['c'], $e['e'] - $e['c']);
    $t = $e['t'];
    if (!isset($e['A']) && strpos($e['a'], 'data-co-') === false && $t !== 'script' && $t !== 'head' && $t !== 'body') {
        return $open . cms_inner($R, $id) . $close;
    }
    $a = isset($e['A']) ? $e['A'] : cms_attrs_parse($e['a']);
    $ov = $R['ov'];
    $inner = null;
    $changed = false;
    $before = $after = '';

    if ($R['fields'] !== null && isset($a['data-edit-field']) && is_string($a['data-edit-field'])
        && isset($R['fields'][$a['data-edit-field']]) && is_string($R['fields'][$a['data-edit-field']])) {
        $inner = $R['fields'][$a['data-edit-field']];
    }
    if ($R['fields'] !== null && isset($a['data-edit-auto'])) {
        $inner = (string) $R['num'];
    }
    if ($R['edit'] && $id === $R['itemRoot']) {
        $a['data-edit-src'] = (string) $R['itemSrc'];
        $changed = true;
    }
    if (isset($a['data-edit-list']) && is_string($a['data-edit-list'])) {
        $L = $a['data-edit-list'];
        if ($R['edit'] || isset($ov['list'][$L])) {
            $inner = cms_list_inner($R, $id, $L);
        }
    }
    if (isset($a['data-edit']) && is_string($a['data-edit']) && isset($ov['text'][$a['data-edit']]) && is_string($ov['text'][$a['data-edit']])) {
        $inner = $ov['text'][$a['data-edit']];
    }
    if (isset($a['data-edit-attr']) && is_string($a['data-edit-attr']) && isset($ov['text'][$a['data-edit-attr']])) {
        $a['content'] = cms_text_of($ov['text'][$a['data-edit-attr']]);
        $changed = true;
    }
    foreach (['data-edit-img' => 'img', 'data-edit-img-preload' => 'preload', 'data-edit-img-meta' => 'meta'] as $attr => $kind) {
        if (!isset($a[$attr]) || !is_string($a[$attr]) || !isset($ov['img'][$a[$attr]]['src'])) {
            continue;
        }
        $im = $ov['img'][$a[$attr]];
        if ($kind === 'img') {
            $a['src'] = (string) $im['src'];
            unset($a['srcset'], $a['sizes']);
            if (isset($im['alt']) && is_string($im['alt'])) {
                $a['alt'] = $im['alt'];
            }
        } elseif ($kind === 'preload') {
            $a['href'] = (string) $im['src'];
            unset($a['imagesrcset'], $a['imagesizes']);
        } else {
            $a['content'] = (string) $im['src'];
        }
        $changed = true;
    }
    if (isset($a['data-edit-hide-empty'], $a['hidden']) && is_string($a['data-edit-hide-empty'])
        && ($R['edit'] || cms_list_count($R, $a['data-edit-hide-empty']) > 0)) {
        unset($a['hidden']);
        $changed = true;
    }
    if (isset($a['data-co-phone']) && $R['phone'] !== null) {
        $a['href'] = 'tel:' . $R['phoneHref'];
        $changed = true;
        if ($inner === null) {
            $inner = cms_co_inner($R, $id, 'b', $R['phone']);
        }
    }
    if (isset($a['data-co-email']) && $R['email'] !== null) {
        $a['href'] = 'mailto:' . $R['email'];
        $changed = true;
        if ($inner === null) {
            $inner = cms_co_inner($R, $id, 'span', $R['email']);
        }
    }
    if ($t === 'script') {
        $type = isset($a['type']) ? (string) $a['type'] : '';
        if ($type === 'application/ld+json' && ($R['phone'] !== null || $R['email'] !== null)) {
            $inner = cms_ld_json($R, substr($html, $e['o'], $e['c'] - $e['o']));
        }
        if ($type === 'module' && isset($a['src']) && $a['src'] === 'app.js') {
            $before = $R['beforeApp'];
            $after = $R['afterApp'];
        }
    }
    if ($inner === null) {
        $inner = cms_inner($R, $id);
    }
    if ($t === 'head') {
        $inner .= $R['headEnd'];
    }
    if ($t === 'body') {
        $inner .= $R['bodyEnd'];
    }
    return $before . ($changed ? cms_start_tag($t, $a) : $open) . $inner . $close . $after;
}

function cms_json_script($var, $data)
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return '<script>window.' . $var . '=' . $json . ";</script>\n";
}

// Номер для ссылки tel: из того, как он записан («+7 714 31 4 05 09», «8 701 …»)
function cms_tel_href($phone)
{
    $d = preg_replace('/\D/', '', (string) $phone);
    if (strlen($d) === 11 && ($d[0] === '8' || $d[0] === '7')) {
        $d = substr($d, 1);
    }
    return strlen($d) === 10 ? '+7' . $d : ($d === '' ? '' : '+' . $d);
}

// Собрать страницу. $mode: 'visitor' | 'admin' (вошедший администратор смотрит сайт) | 'edit'
function cms_render($content, $mode, $boot = null)
{
    $cfg = $content['config'];
    $co = (is_array($cfg) && isset($cfg['company']) && is_array($cfg['company'])) ? $cfg['company'] : [];
    $phone = (isset($co['phone']) && is_string($co['phone']) && $co['phone'] !== '') ? $co['phone'] : null;
    $email = (isset($co['email']) && is_string($co['email']) && $co['email'] !== '') ? $co['email'] : null;
    $R = [
        'ov' => $content,
        'edit' => $mode === 'edit',
        'fields' => null,
        'num' => 0,
        'itemRoot' => -1,
        'itemSrc' => -1,
        'phone' => $phone,
        'phoneHref' => $phone === null ? null : ((isset($co['phoneHref']) && is_string($co['phoneHref']) && $co['phoneHref'] !== '') ? $co['phoneHref'] : cms_tel_href($phone)),
        'email' => $email,
        'beforeApp' => $cfg ? cms_json_script('SITE_OVERRIDES', $cfg) : '',
        'afterApp' => '',
        'headEnd' => '',
        'bodyEnd' => '',
    ];
    if ($mode === 'edit') {
        $R['headEnd'] = '<link rel="stylesheet" href="/admin/editor.css?v=' . cms_asset_v('admin/editor.css') . "\">\n";
        $R['afterApp'] = "\n" . cms_json_script('CMS_BOOT', $boot)
            . '<script type="module" src="/admin/editor.js?v=' . cms_asset_v('admin/editor.js') . '"></script>';
    } elseif ($mode === 'admin') {
        $R['bodyEnd'] = '<style>.cms-pill{position:fixed;left:12px;bottom:12px;z-index:95;display:inline-flex;align-items:center;gap:8px;'
            . 'padding:10px 14px;border-radius:12px;background:#0F2142;color:#fff;font:600 14px/1 system-ui,sans-serif;'
            . 'box-shadow:0 8px 24px rgba(15,33,66,.3);text-decoration:none}.cms-pill:hover{background:#27449E;color:#fff}'
            . '@media (max-width:760px){.cms-pill{bottom:calc(84px + env(safe-area-inset-bottom))}}</style>'
            . '<a class="cms-pill" href="/?cms=1">✎ Редактировать сайт</a>' . "\n";
    }
    return cms_inner($R, -1);
}
