<?php
declare(strict_types=1);

// Interface compartilhada; cada município tem sua própria URL.
$cities = ['Igarassu', 'Itapissuma', 'Abreu e Lima', 'Paulista', 'Olinda', 'Recife', 'Jaboatão dos Guararapes', 'Camaragibe', 'Santa Cruz do Capibaribe', 'Toritama', 'Cabo de Santo Agostinho', 'Ipojuca', 'Caruaru', 'Goiana', 'São Lourenço da Mata', 'Japão', 'Pernambuco'];
$requestedCity = isset($_GET['cidade']) ? (string) $_GET['cidade'] : 'Igarassu';
$selectedCity = in_array($requestedCity, $cities, true) ? $requestedCity : 'Igarassu';
$template = file_get_contents(__DIR__ . '/index.html');
if ($template === false) {
    http_response_code(500);
    exit('Não foi possível carregar a interface do mapa.');
}
$template = str_replace('<html lang="pt-BR">', '<html lang="pt-BR" data-city="' . htmlspecialchars($selectedCity, ENT_QUOTES, 'UTF-8') . '">', $template);
$palette = <<<'CSS'
<style>
:root{--ink:#123b2b;--muted:#687b6e;--green:#087a3e;--lime:#f5d400;--line:#dce8dc;--paper:#fff;--bg:#f4f8ef}
body{background:var(--bg);color:var(--ink)}
.top{background:#fff;border-bottom-color:#d9e5d7}
.brand-mark{background:#e3f1df;color:#087a3e}
.live-dot{background:#f2cf00}
.eyebrow{color:#087a3e}
.city-nav button:hover,.city-nav button.active{background:#087a3e;border-color:#087a3e;color:#fff}
.map-card,.side-card{border-color:#dce8dc}
.badge{color:#155d36;background:#edf5ce}
#map{background:#e7efdc}
.city-texture{stroke:#087a3e;fill:#46a455}
.key-boundary{border-color:#087a3e;background:repeating-linear-gradient(135deg,#edf5ce 0 3px,#43a253 3px 4px)}
.key-municipal{background:repeating-linear-gradient(35deg,#edf5ce 0 2px,#43a253 2px 3px,#fff8c5 3px 5px)}
.key-line{background:#35a552}
.key-poll,.school-icon{background:#f5d400}
.school-icon{color:#17482d}
.election-tabs button.active,.municipal-election-tabs button.active{background:#edf5ce;border-color:#a9ca83;color:#155d36}
.election-close{background:#edf5e9;color:#155d36}
</style>
CSS;
$template = str_replace('</head>', $palette . "\n</head>", $template);
header('Content-Type: text/html; charset=UTF-8');
echo $template;
