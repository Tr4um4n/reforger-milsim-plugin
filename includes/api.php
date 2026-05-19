<?php
/**
 * api.php — Steam + Reforger unified scraper
 * -------------------------------------------------------------
 * Ejemplos:
 *   /api.php?action=dependencies&id=3010275991
 *   /api.php?action=dependencies&id=66B69C25F4FA5A79
 *   /api.php?action=details&id=3010275991
 *   /api.php?action=scenarios&id=66B69C25F4FA5A79
 *   /api.php?action=multi  (POST JSON con lista de mods)
 * -------------------------------------------------------------
 */

header('Content-Type: application/json; charset=utf-8');

// 🚀 Carga dinámica de WordPress para acceder a get_option
$wp_root = __DIR__;
for ($i = 0; $i < 6; $i++) {
    if (file_exists($wp_root . '/wp-load.php')) {
        require_once($wp_root . '/wp-load.php');
        break;
    }
    $wp_root = dirname($wp_root);
}

// 🔐 Steam Web API Key (https://steamcommunity.com/dev/apikey)
$STEAM_API_KEY = "TU_API_KEY_AQUI";
if (function_exists('get_option')) {
    $db_key = get_option('rmm_steam_api_key');
    if (!empty($db_key)) {
        $STEAM_API_KEY = $db_key;
    }
}

// 🐞 Debug mode
$DEBUG = isset($_GET['debug']) && $_GET['debug'] == '1';

// ─────────────────────────────────────────────────────────────
// UTILIDADES BÁSICAS
// ─────────────────────────────────────────────────────────────

function json_out($arr) {
    echo json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function is_steam_id($id) {
    return preg_match('/^\d{6,20}$/', $id);
}

function is_reforger_uuid($id) {
    return preg_match('/^[0-9A-Fa-f]{16}$/', $id);
}

function http_get($url, $timeout = 15, $follow = true) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'Accept: text/html,application/json;q=0.9,*/*;q=0.8'
        ]
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    return [$body, $err, $info];
}

// ─────────────────────────────────────────────────────────────
// STEAM API HANDLER
// ─────────────────────────────────────────────────────────────

function steam_get_details($id, $api_key) {
    $api = "https://api.steampowered.com/ISteamRemoteStorage/GetPublishedFileDetails/v1/";
    $post = [
        'key' => $api_key,
        'itemcount' => 1,
        'publishedfileids[0]' => $id
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $api,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    if ($error || ($info['http_code'] ?? 0) !== 200) {
        return [
            'error' => 'Steam API error',
            'curl_error' => $error,
            'http_code' => $info['http_code'] ?? 0
        ];
    }

    $json = json_decode($response, true);
    if (!isset($json['response']['publishedfiledetails'][0])) {
        return ['error' => 'Sin resultados de Steam API', 'raw' => $json];
    }

    return $json['response']['publishedfiledetails'][0];
}

function steam_dependencies_payload($details) {
    $deps = [];
    if (!empty($details['required_items'])) {
        foreach ($details['required_items'] as $it) {
            $deps[] = [
                'modId' => $it['publishedfileid'] ?? null,
                'name' => $it['title'] ?? 'Desconocido'
            ];
        }
    }
    return [
        'item' => [
            'id' => $details['publishedfileid'] ?? null,
            'title' => $details['title'] ?? 'Sin título',
            'file_url' => $details['file_url'] ?? null,
            'type' => 'steam'
        ],
        'dependencies' => $deps
    ];
}

// ─────────────────────────────────────────────────────────────
// REFORGER SCRAPER
// ─────────────────────────────────────────────────────────────

function reforger_scrape_details_and_deps($uuid, $debug = false) {
    $base = "https://reforger.armaplatform.com";
    $url = "$base/workshop/$uuid";
    [$html, $err, $info] = http_get($url, 20, true);

    if ($err || empty($html)) {
        return ['error' => 'No se pudo obtener HTML', 'curl_error' => $err];
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xp = new DOMXPath($dom);

    // === TÍTULO ===
    $title = '';
    $node = $xp->query("//section//h1");
    if ($node && $node->length > 0) {
        $title = trim($node->item(0)->textContent);
    }

    // === AUTOR ===
    $author = '';
    $author_nodes = $xp->query("//section//*[starts-with(normalize-space(.), 'by ')]");
    if ($author_nodes && $author_nodes->length > 0) {
        $author = trim($author_nodes->item(0)->textContent);
        $author = preg_replace('/^by\s+/i', '', $author);
    }

    // === IMAGEN ===
    $image = '';
    $img_nodes = $xp->query("//figure[contains(@class, 'aspect-w-16')]/img");
    if ($img_nodes && $img_nodes->length > 0) {
        $image = $img_nodes->item(0)->getAttribute('src');
    }

    // === DEPENDENCIAS ===
    $deps = [];
    foreach ($xp->query("//section//*[contains(@class,'py-8')]//a[@href] | //section//a[@href]") as $a) {
        $href = $a->getAttribute('href');
        if (preg_match('#^/workshop/([0-9A-Fa-f]{16})#', $href, $m)) {
            $depId = strtoupper($m[1]);
            if (strcasecmp($depId, $uuid) !== 0) {
                $deps[] = ['modId' => $depId, 'name' => trim($a->textContent)];
            }
        }
    }

    // === ESCENARIOS ===
    $scenarios_url = "$url/scenarios";
    [$html2, $err2] = http_get($scenarios_url, 20, true);
    $scenarios = [];

    if (!$err2 && !empty($html2)) {
        $dom2 = new DOMDocument();
        @$dom2->loadHTML($html2);
        $xp2 = new DOMXPath($dom2);

        $articles = $xp2->query("//section//div[contains(@class,'grid')]//article");
        foreach ($articles as $art) {
            $name = trim($xp2->evaluate("string(.//h2)", $art));
            $desc = trim($xp2->evaluate("string(.//p)", $art));
            $infoBlock = trim($xp2->evaluate("string(.//dl)", $art));
            $img = $xp2->evaluate("string(.//img/@src)", $art);

            preg_match('/Scenario ID\s*([A-Za-z0-9_\.\/]+)/', $infoBlock, $sid);
            preg_match('/Game mode\s*([A-Za-z0-9_]+)/', $infoBlock, $gm);
            preg_match('/Player count\s*(\d+)/', $infoBlock, $pc);

            $scenarios[] = [
                'name' => $name,
                'description' => $desc,
                'scenarioId' => $sid[1] ?? null,
                'gamemode' => $gm[1] ?? null,
                'players' => isset($pc[1]) ? (int)$pc[1] : null,
                'image' => $img ? "https://reforger.armaplatform.com$img" : null
            ];
        }
    }

    return [
        'item' => [
            'id' => strtoupper($uuid),
            'title' => $title ?: 'Sin título',
            'author' => $author ?: '',
            'image' => $image ?: '',
            'url' => $info['url'] ?? $url,
            'type' => 'reforger'
        ],
        'dependencies' => $deps,
        'scenarios' => $scenarios
    ];
}

// ─────────────────────────────────────────────────────────────
// ROUTER
// ─────────────────────────────────────────────────────────────

$action = $_GET['action'] ?? null;
$id = $_GET['id'] ?? null;

if (!$action) {
    json_out([
        'error' => 'Falta parámetro action. Usa ?action=[dependencies|details|scenarios|multi]'
    ]);
}

// ─────────────────────────────────────────────────────────────
// ACCIÓN: MULTI (NUEVA)
// Analiza un JSON con varios mods y devuelve:
//  - dependencias faltantes
//  - qué mod requiere cada una
// ─────────────────────────────────────────────────────────────

if ($action === 'multi') {
    // Acepta JSON crudo en el body o parámetro "mods"
    $raw = file_get_contents('php://input');
    if (!$raw) {
        if (isset($_POST['mods'])) {
            $raw = $_POST['mods'];
        } elseif (isset($_GET['mods'])) {
            $raw = $_GET['mods'];
        }
    }

    if (!$raw) {
        json_out(['error' => 'No se ha recibido JSON de mods. Envía un array JSON en el body o parámetro "mods".']);
    }

    $mods = json_decode($raw, true);
    if (!is_array($mods)) {
        json_out(['error' => 'JSON de mods no válido. Debe ser un array de objetos con modId y name.']);
    }

    // Normalizar lista de mods instalados
    $installed = [];
    foreach ($mods as $m) {
        $mid = strtoupper(trim($m['modId'] ?? ''));
        if (!$mid) continue;
        $installed[$mid] = [
            'modId' => $mid,
            'name'  => $m['name'] ?? null
        ];
    }

    if (empty($installed)) {
        json_out(['error' => 'No se han encontrado modId válidos en el JSON recibido.']);
    }

    set_time_limit(120);

    $missingGlobal = [];  // clave: depModId => [modId, name, requiredBy[]]
    $modsAnalyzed = [];   // info por mod
    $perModErrors = [];

    foreach ($installed as $mid => $info) {
        $srcId   = $mid;
        $srcName = $info['name'] ?: $srcId;

        $deps = [];
        $error = null;

        // Resolver dependencias según tipo de ID
        if (is_steam_id($srcId)) {
            $details = steam_get_details($srcId, $GLOBALS['STEAM_API_KEY']);
            if (isset($details['error'])) {
                $error = $details['error'];
            } else {
                $payload = steam_dependencies_payload($details);
                $deps = $payload['dependencies'] ?? [];
                @file_put_contents(__DIR__ . "/mods_{$srcId}.json", json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
        } elseif (is_reforger_uuid($srcId)) {
            $payload = reforger_scrape_details_and_deps($srcId, $GLOBALS['DEBUG']);
            if (isset($payload['error'])) {
                $error = $payload['error'];
            } else {
                $deps = $payload['dependencies'] ?? [];
                @file_put_contents(__DIR__ . "/mods_{$srcId}.json", json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
        } else {
            $error = 'Formato de ID no válido';
        }

        $missingForThisMod = [];

        if ($error) {
            $modsAnalyzed[] = [
                'modId' => $srcId,
                'name'  => $srcName,
                'error' => $error
            ];
            $perModErrors[] = [
                'modId' => $srcId,
                'name'  => $srcName,
                'error' => $error
            ];
            continue;
        }

        foreach ($deps as $dep) {
            $depId = strtoupper(trim($dep['modId'] ?? ''));
            if (!$depId) continue;

            $depName = $dep['name'] ?? $depId;

            // Si ya está instalado, no es "faltante"
            if (isset($installed[$depId])) {
                continue;
            }

            $missingForThisMod[] = $depId;

            if (!isset($missingGlobal[$depId])) {
                $missingGlobal[$depId] = [
                    'modId'      => $depId,
                    'name'       => $depName,
                    'requiredBy' => []
                ];
            }

            $missingGlobal[$depId]['requiredBy'][] = [
                'modId' => $srcId,
                'name'  => $srcName
            ];
        }

        $modsAnalyzed[] = [
            'modId'               => $srcId,
            'name'                => $srcName,
            'dependenciesTotal'   => count($deps),
            'missingDependencies' => $missingForThisMod
        ];
    }

    // Ordenar faltantes por nombre para legibilidad
    $missingList = array_values($missingGlobal);
    usort($missingList, function($a, $b) {
        $an = $a['name'] ?? $a['modId'];
        $bn = $b['name'] ?? $b['modId'];
        return strcmp($an, $bn);
    });

    $summary = [
        'modsCount'      => count($installed),
        'missingCount'   => count($missingList),
        'errorsCount'    => count($perModErrors),
        'generatedAt'    => date('c')
    ];

    json_out([
        'summary'             => $summary,
        'installedMods'       => array_values($installed),
        'missingDependencies' => $missingList,
        'modsAnalyzed'        => $modsAnalyzed,
        'errors'              => $perModErrors
    ]);
}

// ─────────────────────────────────────────────────────────────
// ACCIÓN: DEPENDENCIES
// ─────────────────────────────────────────────────────────────

if ($action === 'dependencies') {
    if (!$id) {
        json_out(['error' => 'Falta parámetro id para action=dependencies']);
    }

    if (is_steam_id($id)) {
        $details = steam_get_details($id, $STEAM_API_KEY);
        if (isset($details['error'])) json_out($details);
        $payload = steam_dependencies_payload($details);
        @file_put_contents(__DIR__ . "/mods_{$id}.json", json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        json_out($payload);
    } elseif (is_reforger_uuid($id)) {
        $payload = reforger_scrape_details_and_deps($id, $DEBUG);
        @file_put_contents(__DIR__ . "/mods_{$id}.json", json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        json_out([
            'item' => $payload['item'],
            'dependencies' => $payload['dependencies']
        ]);
    } else {
        json_out(['error' => 'Formato de ID no válido']);
    }
}

// ─────────────────────────────────────────────────────────────
// ACCIÓN: DETAILS
// ─────────────────────────────────────────────────────────────

if ($action === 'details') {
    if (!$id) {
        json_out(['error' => 'Falta parámetro id para action=details']);
    }

    if (is_steam_id($id)) {
        json_out(steam_get_details($id, $STEAM_API_KEY));
    } elseif (is_reforger_uuid($id)) {
        $payload = reforger_scrape_details_and_deps($id, $DEBUG);
        json_out($payload['item']);
    } else {
        json_out(['error' => 'Formato de ID no válido']);
    }
}

// ─────────────────────────────────────────────────────────────
// ACCIÓN: SCENARIOS
// ─────────────────────────────────────────────────────────────

if ($action === 'scenarios') {
    if (!$id) {
        json_out(['error' => 'Falta parámetro id para action=scenarios']);
    }

    if (is_reforger_uuid($id)) {
        $data = reforger_scrape_details_and_deps($id, $DEBUG);
        json_out($data['scenarios'] ?? []);
    } else {
        json_out(['error' => 'Solo UUID Reforger soportados para escenarios']);
    }
}

json_out(['error' => 'Acción no válida. Usa ?action=dependencies|details|scenarios|multi']);