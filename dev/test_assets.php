<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
// php dev/test_assets.php — the precompiled front-end: every icon, class and dynamic class the views use must be in assets/.
define('APP_ROOT', dirname(__DIR__));
function check(bool $ok, string $w): void { echo ($ok ? "ok   " : "FAIL ") . $w . "\n"; if (!$ok) $GLOBALS['failed'] = true; }
$views = glob(APP_ROOT . '/views/*.php'); $ui = (string)file_get_contents(APP_ROOT . '/assets/ui.js');
$src = $ui . implode('', array_map('file_get_contents', $views)) . (string)file_get_contents(APP_ROOT . '/src/helpers.php');
$css = (string)file_get_contents(APP_ROOT . '/assets/app.css'); $flat = str_replace('\\', '', $css);

// ---- icons
preg_match('/window\.__ICONS=(\{.*\});/s', (string)file_get_contents(APP_ROOT . '/assets/icons.js'), $m);
$icons = json_decode($m[1] ?? '{}', true) ?: [];
check(count($icons) > 50 && filesize(APP_ROOT . '/assets/icons.js') < 60000, 'icons.js is a small subset (' . count($icons) . ' icons, ' . round(filesize(APP_ROOT . '/assets/icons.js') / 1024) . ' KB)');
preg_match_all("/(?:(?:UI\.i|\bi)\(\s*['\"]|data-lucide=\")([a-z][a-z0-9-]+)/", $src, $mm);        // quoted literals only
$used = array_unique($mm[1]); $missing = array_values(array_filter($used, fn($i) => !isset($icons[$i])));
check(!$missing, 'every icon referenced by UI.i()/data-lucide exists in icons.js' . ($missing ? ': ' . implode(', ', $missing) : ''));
preg_match_all("/\?\s*'([a-z][a-z0-9]*(?:-[a-z0-9]+)+)'\s*:\s*'([a-z][a-z0-9]*(?:-[a-z0-9]+)+)'/", $src, $tern);   // UI.i(cond ? 'a-b' : 'c-d')
$tm = array_values(array_filter(array_unique(array_merge($tern[1], $tern[2])), fn($i) => !isset($icons[$i]) && preg_match('/^(toggle|check|x|chevron|folder|circle|triangle|shield|file|arrow|wifi|plug|loader|download|upload)/', $i)));
check(!$tm, 'icons chosen by a conditional exist too' . ($tm ? ': ' . implode(', ', $tm) : ''));

// ---- css coverage: class tokens written literally in views/ui.js
$custom = '/^(js-|sel$|pick$|cb$|open$|fix$|rb$|c-|chev$|group$|peer$|lucide$|code$|card|btn|badge|input|label|hint|muted|tbl|font-mono|apexcharts)/';
$miss = [];
preg_match_all('/class="([^"$]*)"/', $src, $cm);
foreach ($cm[1] as $attr) foreach (preg_split('/\s+/', trim($attr)) as $t) {
    if ($t === '' || str_contains($t, '<?') || preg_match($custom, $t) || !preg_match('/[-:\[]/', $t)) continue;
    if (!str_contains($flat, '.' . $t)) $miss[$t] = true;
}
check(!$miss, 'every Tailwind class written in the views is in app.css' . ($miss ? ': ' . implode(', ', array_slice(array_keys($miss), 0, 12)) : ''));
foreach (['badge-green', 'badge-red', 'badge-yellow', 'badge-orange', 'badge-blue', 'badge-purple', 'badge-gray', 'btn-primary', 'btn-danger', 'input', 'card', 'tbl', 'code'] as $c) if (!str_contains($flat, ".$c")) $miss[$c] = true;
check(!array_intersect_key($miss, array_flip(['badge-green', 'btn-primary', 'card', 'input'])), 'component classes (card, btn, badge-*, input, tbl) are in app.css');
foreach (['border-green-500', 'bg-red-50', 'text-blue-800', 'dark:text-green-300', 'text-orange-600'] as $c) check(str_contains($flat, '.' . $c), "run-time class $c is safelisted (toasts/health pick it from a variable)");
check(str_contains($css, '.dark'), 'dark-mode variant compiled');

// ---- no CDN runtime compiler any more
$layout = (string)file_get_contents(APP_ROOT . '/views/layout.php');
check(!str_contains($layout, '@tailwindcss/browser') && !str_contains($layout, 'text/tailwindcss') && !str_contains($layout, 'lucide@') && !str_contains($layout, 'apexcharts@'), 'the layout no longer loads Tailwind\'s browser compiler, full Lucide or ApexCharts from a CDN');
check(substr_count($layout, 'asset(') >= 3 && filesize(APP_ROOT . '/assets/app.css') < 120000, 'layout uses versioned local assets; app.css is ' . round(filesize(APP_ROOT . '/assets/app.css') / 1024) . ' KB');
check(!str_contains($ui, 'createIcons'), 'ui.js has no icon re-scan loop (lucide.createIcons)');

// ---- portal front end: parses, and every icon it draws exists in icons.js
$pj = (string)file_get_contents(APP_ROOT . '/assets/portal.js'); $icons = (string)file_get_contents(APP_ROOT . '/assets/icons.js');
preg_match_all("/\\bic\\('([a-z0-9-]+)'/", $pj, $m); $missing = array_filter(array_unique($m[1]), fn($i) => !str_contains($icons, '"' . $i . '":'));
check(!$missing, 'every icon portal.js uses is in icons.js' . ($missing ? ' (missing: ' . implode(', ', $missing) . ')' : ''));
check(is_file(APP_ROOT . '/public/assets/portal.js') && md5_file(APP_ROOT . '/public/assets/portal.js') === md5_file(APP_ROOT . '/assets/portal.js'), 'public/assets/portal.js is current');
check(md5_file(APP_ROOT . '/public/assets/app.css') === md5_file(APP_ROOT . '/assets/app.css'), 'public/assets/app.css is current');
exit(empty($GLOBALS['failed']) ? 0 : 1);
