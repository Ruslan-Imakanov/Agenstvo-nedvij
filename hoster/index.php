<?php
// Главная страница сайта на hoster.kz: шаблон cms/page.tpl (= public/index.html) + правки из кабинета
// администратора (/admin/). Если что-то пошло не так — отдаём шаблон как есть, сайт не ломается.
define('MIP_CMS', 1);
ob_start();
try {
    require __DIR__ . '/cms/lib.php';
    require __DIR__ . '/cms/render.php';

    $content = cms_content();
    $sess = cms_session();
    $edit = isset($_GET['cms']);

    if ($edit && !$sess) {
        header('Location: /admin/', true, 302);
        header('Cache-Control: no-store');
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    header('X-MIP-CMS: ' . $content['rev']);
    if ($edit) {
        cms_no_cache_headers();
        $boot = [
            'login' => $sess['login'],
            'csrf' => cms_csrf($sess),
            'rev' => $content['rev'],
            'at' => $content['at'],
            'by' => $content['by'],
            'api' => '/admin/api.php',
        ];
        $page = cms_render($content, 'edit', $boot);
    } else {
        $mode = $sess ? 'admin' : 'visitor';
        $etag = '"' . md5(filemtime(CMS_TPL) . '|' . filesize(CMS_TPL) . '|' . $content['rev'] . '|' . $content['at'] . '|' . $mode) . '"';
        header('Cache-Control: ' . ($sess ? 'private, no-cache' : 'no-cache'));
        header('ETag: ' . $etag);
        $inm = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) : '';
        if ($inm !== '' && ($inm === $etag || $inm === 'W/' . $etag)) {
            ob_end_clean();
            http_response_code(304);
            exit;
        }
        $page = cms_render($content, $mode);
    }
    ob_end_clean();
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'HEAD') {
        header('Content-Length: ' . strlen($page));
        exit;
    }
    echo $page;
} catch (Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    error_log('mip.com.kz index.php: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        header_remove('ETag');
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-MIP-CMS: fallback');
    }
    foreach ([__DIR__ . '/cms/page.tpl', __DIR__ . '/index.html'] as $f) {
        if (is_file($f)) {
            readfile($f);
            exit;
        }
    }
    http_response_code(503);
    echo 'Сайт обновляется — зайдите через минуту.';
}
