<?php
/*
 * Хозбот — сервер цен.
 * GET api.php?store=casadel&q=զուգարանի թուղթ&q2=туалетная бумага
 * Ответ: {"store":"casadel","ok":true,"url":"…","items":[{"name":"…","price":650,"url":"…"}]}
 * debug=1 — добавляет список попыток и сохраняет страницы магазинов в cache/raw_*.html
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
mb_internal_encoding('UTF-8');
@set_time_limit(60);

$STORES = [
  'casadel'     => ['base' => 'https://casadel.am',          'search' => ['/products/search?searchQuery={q}']],
  'parma'       => ['base' => 'https://parma.am',            'search' => ['/hy/product/search?text={q}', '/ru/product/search?text={q}']],
  'tntesakan'   => ['base' => 'https://tntesakan.am',        'search' => ['/hy/search?search={q}', '/hy/search?q={q}', '/hy/products?search={q}', '/search?q={q}']],
  'ycity'       => ['base' => 'https://www.yerevan-city.am', 'search' => ['/shop/search?search={q}', '/shop/search?q={q}', '/search?q={q}']],
  'sas'         => ['base' => 'https://www.sas.am',          'search' => ['/search/?q={q}', '/catalog/?q={q}', '/search?q={q}']],
  'supermarket' => ['base' => 'https://supermarket.am',      'search' => ['/search?q={q}', '/?s={q}&post_type=product', '/hy/search?q={q}', '/search?search={q}']],
  'jur'         => ['base' => 'https://jur.am',              'search' => ['/search?q={q}', '/?s={q}&post_type=product', '/hy/search?q={q}', '/search?search={q}']],
  'mymarket'    => ['base' => 'https://mymarket.am',         'search' => ['/search?q={q}', '/search?search={q}', '/?s={q}&post_type=product', '/search?text={q}']],
  'spasq'       => ['base' => 'https://spasq.am',            'search' => ['/hy/search?q={q}', '/hy/search?search={q}', '/hy/search?text={q}', '/search?q={q}']],
  'domus'       => ['base' => 'https://domus.am',            'search' => ['/search?q={q}', '/search?search={q}', '/search?keyword={q}', '/search?text={q}']],
];

$CACHE_DIR = __DIR__ . '/cache';
$CACHE_TTL = 3 * 3600;
$DEADLINE  = microtime(true) + 40;   // общий лимит на один магазин

/* ---------------- входные данные ---------------- */
$storeId = $_GET['store'] ?? '';
$terms = array_values(array_unique(array_filter(array_map('trim', [$_GET['q'] ?? '', $_GET['q2'] ?? '']), fn($t) => $t !== '' && mb_strlen($t) <= 80)));
$debug = !empty($_GET['debug']);

if ($storeId === 'list') { echo json_encode(array_keys($STORES)); exit; }
if (!isset($STORES[$storeId]) || !$terms) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'bad request']); exit; }
$store = $STORES[$storeId];

/* ---------------- кэш ---------------- */
$key = $CACHE_DIR . '/r_' . md5($storeId . '|' . implode('|', $terms)) . '.json';
if (!$debug && is_file($key) && time() - filemtime($key) < $CACHE_TTL) { readfile($key); exit; }

/* ---------------- загрузка страницы ---------------- */
function fetch_url($url) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4,
    CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_ENCODING => '',
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
    CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8', 'Accept-Language: hy-AM,hy;q=0.9,ru;q=0.8,en;q=0.7'],
  ]);
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
  $err = curl_error($ch);
  curl_close($ch);
  return [$code, $body === false ? '' : $body, $final, $err];
}

/* ---------------- разбор цены ---------------- */
const CUR_RE = '/֏|AMD|դր|драм|dram/iu';
function parse_price($text) {
  $text = str_replace("\xC2\xA0", ' ', $text);
  if (!preg_match(CUR_RE, $text)) return null;
  if (!preg_match('/\d[\d ,.\']*/u', $text, $m)) return null;
  $n = trim($m[0], " ,.'");
  $n = preg_replace('/[.,]\d{1,2}$/', '', $n);       // копейки
  $n = preg_replace('/\D/', '', $n);
  if ($n === '') return null;
  $v = (int)$n;
  return ($v >= 10 && $v <= 5000000) ? $v : null;
}
function num_price($v) {
  if (is_numeric($v)) { $v = (float)$v; return ($v >= 10 && $v <= 5000000) ? (int)round($v) : null; }
  if (is_string($v)) { $s = preg_replace('/[^\d.]/', '', str_replace([' ', ','], '', $v)); return is_numeric($s) ? num_price($s) : null; }
  if (is_array($v)) { foreach (['amount', 'value', 'price', 'current'] as $k) if (isset($v[$k])) return num_price($v[$k]); }
  return null;
}
function abs_url($href, $base) {
  if (!$href) return null;
  if (preg_match('#^https?://#i', $href)) return $href;
  if (str_starts_with($href, '//')) return 'https:' . $href;
  if (str_starts_with($href, '/')) return $base . $href;
  if (preg_match('#^(javascript|mailto|tel|\#)#i', $href)) return null;
  return $base . '/' . $href;
}
function clean($s) { return trim(preg_replace('/\s+/u', ' ', html_entity_decode((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8'))); }

/* ---------------- 1) JSON внутри страницы (JSON-LD, __NEXT_DATA__, __NUXT__ и т.п.) ---------------- */
function walk_json($data, $base, &$out, $depth = 0) {
  if ($depth > 14 || !is_array($data)) return;
  $name = null; $price = null; $url = null;
  foreach (['name', 'title', 'product_name', 'productName', 'name_hy', 'title_hy', 'nameHy', 'name_am'] as $k)
    if (isset($data[$k]) && is_string($data[$k]) && mb_strlen($data[$k]) >= 3) { $name = $data[$k]; break; }
  if ($name) {
    foreach (['price', 'final_price', 'finalPrice', 'sale_price', 'salePrice', 'discount_price', 'discountPrice', 'current_price', 'currentPrice', 'regular_price', 'retail_price', 'priceWithDiscount', 'lowPrice'] as $k)
      if (isset($data[$k]) && ($p = num_price($data[$k])) !== null) { $price = $price === null ? $p : min($price, $p); }
    if ($price === null && isset($data['offers'])) {
      $offers = isset($data['offers'][0]) ? $data['offers'] : [$data['offers']];
      foreach ($offers as $o) if (is_array($o)) foreach (['price', 'lowPrice'] as $k) if (isset($o[$k]) && ($p = num_price($o[$k])) !== null) $price = $price === null ? $p : min($price, $p);
    }
    foreach (['url', 'link', 'href', 'slug', 'permalink'] as $k) if (isset($data[$k]) && is_string($data[$k])) { $url = $data[$k]; break; }
    if ($price !== null) {
      if ($url && !preg_match('#^(https?:)?/#', $url) && $k === 'slug') $url = null;
      $out[] = ['name' => clean($name), 'price' => $price, 'url' => abs_url($url, $base)];
    }
  }
  foreach ($data as $v) if (is_array($v)) walk_json($v, $base, $out, $depth + 1);
}
function from_json($html, $base) {
  $out = [];
  if (preg_match_all('#<script[^>]*>(.*?)</script>#si', $html, $m)) {
    foreach ($m[1] as $s) {
      $s = trim($s);
      if ($s === '') continue;
      $cands = [];
      if ($s[0] === '{' || $s[0] === '[') $cands[] = $s;
      elseif (preg_match('/^(?:window\.)?[\w$.]+\s*=\s*(\{.*\}|\[.*\])\s*;?\s*$/s', $s, $mm)) $cands[] = $mm[1];
      foreach ($cands as $c) { $d = json_decode($c, true); if (is_array($d)) walk_json($d, $base, $out); }
    }
  }
  return $out;
}

/* ---------------- 2) Разбор HTML-карточек ---------------- */
function from_dom($html, $base) {
  $out = [];
  $dom = new DOMDocument();
  libxml_use_internal_errors(true);
  $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT);
  libxml_clear_errors();
  $xp = new DOMXPath($dom);
  foreach ($xp->query('//script|//style|//noscript|//svg|//header|//footer|//nav') as $n) $n->parentNode?->removeChild($n);

  // самые «глубокие» короткие элементы, в тексте которых есть число и знак валюты
  $priceNodes = [];
  $q = '//*[string-length(normalize-space(.)) < 45 and (contains(., "֏") or contains(., "դր") or contains(., "AMD") or contains(., "драм"))]';
  foreach ($xp->query($q) as $el) {
    $deeper = false;
    foreach ($el->childNodes as $c) if ($c instanceof DOMElement && parse_price($c->textContent) !== null) { $deeper = true; break; }
    if ($deeper) continue;
    $p = parse_price($el->textContent);
    if ($p !== null) $priceNodes[] = [$el, $p];
  }

  $cards = [];
  foreach ($priceNodes as [$el, $price]) {
    $node = $el; $found = null;
    for ($i = 0; $i < 7 && $node->parentNode instanceof DOMElement; $i++) {
      $node = $node->parentNode;
      $name = null; $url = null;
      foreach ($xp->query('.//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or contains(@class,"name") or contains(@class,"title")]', $node) as $h) {
        $t = clean($h->textContent);
        if (mb_strlen($t) >= 4 && mb_strlen($t) <= 200 && parse_price($t) === null) { $name = $t; break; }
      }
      foreach ($xp->query('.//a[@href]', $node) as $a) {
        $t = clean($a->getAttribute('title') ?: $a->textContent);
        $href = abs_url($a->getAttribute('href'), $base);
        if (!$url && $href && !preg_match('#/(cart|basket|login|wishlist|compare)#i', $href)) $url = $href;
        if (!$name && mb_strlen($t) >= 4 && mb_strlen($t) <= 200 && parse_price($t) === null) { $name = $t; $url = $href ?: $url; }
      }
      if (!$name) foreach ($xp->query('.//img[@alt]', $node) as $img) {
        $t = clean($img->getAttribute('alt')); if (mb_strlen($t) >= 4) { $name = $t; break; }
      }
      if ($name) { $found = [$node, $name, $url]; break; }
    }
    if (!$found) continue;
    $id = $found[0]->getNodePath();
    if (!isset($cards[$id]) || $price < $cards[$id]['price'])   // в карточке старая и новая цена — берём меньшую
      $cards[$id] = ['name' => $found[1], 'price' => $price, 'url' => $found[2]];
  }
  return array_values($cards);
}

/* ---------------- релевантность ---------------- */
function stems($term) {
  $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($term), -1, PREG_SPLIT_NO_EMPTY);
  $st = [];
  foreach ($words as $w) if (mb_strlen($w) >= 3 && !preg_match('/^\d+$/', $w)) $st[] = mb_substr($w, 0, max(3, min(5, mb_strlen($w) - 1)));
  return $st;
}
function score($name, $stems) {
  $n = mb_strtolower($name); $s = 0;
  foreach ($stems as $st) if (mb_strpos($n, $st) !== false) $s++;
  return $s;
}
function pick($items, $term) {
  $st = stems($term);
  if (!$st) return [];
  $seen = []; $res = [];
  foreach ($items as $it) {
    $sc = score($it['name'], $st);
    if ($sc === 0) continue;
    $k = mb_strtolower($it['name']) . '|' . $it['price'];
    if (isset($seen[$k])) continue;
    $seen[$k] = 1;
    $it['score'] = $sc;
    $res[] = $it;
  }
  $need = count($st) >= 2 ? 2 : 1;                      // если в запросе 2+ слова — сначала товары, где совпали оба
  $best = array_filter($res, fn($r) => $r['score'] >= $need);
  if ($best) $res = array_values($best);
  usort($res, fn($a, $b) => $b['score'] <=> $a['score'] ?: $a['price'] <=> $b['price']);
  return array_map(fn($r) => ['name' => mb_substr($r['name'], 0, 140), 'price' => $r['price'], 'url' => $r['url']], array_slice($res, 0, 6));
}

/* ---------------- основной цикл ---------------- */
$result = ['store' => $storeId, 'ok' => false, 'url' => null, 'items' => [], 'time' => date('c')];
$tried = [];
foreach ($terms as $term) {
  foreach ($store['search'] as $i => $tpl) {
    if (microtime(true) > $DEADLINE) break 2;
    $url = $store['base'] . str_replace('{q}', rawurlencode($term), $tpl);
    [$code, $html, $final, $err] = fetch_url($url);
    $all = [];
    if ($code >= 200 && $code < 400 && $html !== '') {
      $all = array_merge(from_json($html, $store['base']), from_dom($html, $store['base']));
    }
    $items = pick($all, $term);
    $tried[] = ['url' => $url, 'final' => $final, 'code' => $code, 'err' => $err, 'bytes' => strlen($html), 'candidates' => count($all), 'matched' => count($items),
                'sample' => $debug ? array_slice($all, 0, 5) : null];
    if ($debug && $html !== '') {
      $pretty = preg_replace('/>\s*</', ">\n<", $html);
      @file_put_contents("$CACHE_DIR/raw_{$storeId}_" . count($tried) . '.html', "<!-- $url ($code) -->\n" . substr($pretty, 0, 400000));
    }
    if ($items) { $result['ok'] = true; $result['url'] = $url; $result['items'] = $items; $result['term'] = $term; break 2; }
  }
}
if ($debug) $result['tried'] = $tried;
@file_put_contents("$CACHE_DIR/log.txt", date('c') . " $storeId " . json_encode(['terms' => $terms, 'ok' => $result['ok'], 'tried' => array_map(fn($t) => [$t['url'], $t['code'], $t['bytes'], $t['candidates'], $t['matched'], $t['err']], $tried)], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

$json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!$debug && $result['ok']) @file_put_contents($key, $json);
echo $json;
