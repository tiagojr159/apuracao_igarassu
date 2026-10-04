<?php
declare(strict_types=1);

//   dsgsdfdsf The interface stays in one shared template; each city has its own URL.
$cities = ['Igarassu', 'Itapissuma', 'Abreu e Lima', 'Paulista', 'Olinda', 'Recife', 'Jaboatão dos Guararapes', 'Camaragibe', 'Santa Cruz do Capibaribe', 'Toritama', 'Cabo de Santo Agostinho', 'Ipojuca', 'Caruaru', 'Goiana', 'São Lourenço da Mata', 'Japão', 'Pernambuco'];
$requestedCity = isset($_GET['cidade']) ? (string) $_GET['cidade'] : 'Igarassu';
$selectedCity = in_array($requestedCity, $cities, true) ? $requestedCity : 'Igarassu';
$template = file_get_contents(__DIR__ . '/index.html');
if ($template === false) {
    http_response_code(500);
    exit('Não foi possível carregar a interface do mapa.');
}
$template = str_replace('<html lang="pt-BR">', '<html lang="pt-BR" data-city="' . htmlspecialchars($selectedCity, ENT_QUOTES, 'UTF-8') . '">', $template);
header('Content-Type: text/html; charset=UTF-8');
echo $template;
