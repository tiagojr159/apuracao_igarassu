<?php
/** Minimal BER reader and TSE BU V2 vote extractor. PHP 7.4+, no Python. */

function buBerRead(string $data, int &$offset, int $end): array
{
    if ($offset >= $end) throw new RuntimeException('ASN.1 truncado.');
    $identifier = ord($data[$offset++]);
    $class = ($identifier >> 6) & 3;
    $constructed = ($identifier & 0x20) !== 0;
    $tag = $identifier & 0x1f;
    if ($tag === 0x1f) {
        $tag = 0;
        do {
            if ($offset >= $end) throw new RuntimeException('Tag ASN.1 truncada.');
            $part = ord($data[$offset++]);
            $tag = ($tag << 7) | ($part & 0x7f);
            if ($tag > 0x0fffffff) throw new RuntimeException('Tag ASN.1 excessiva.');
        } while (($part & 0x80) !== 0);
    }
    if ($offset >= $end) throw new RuntimeException('Comprimento ASN.1 ausente.');
    $first = ord($data[$offset++]);
    if (($first & 0x80) === 0) $length = $first;
    else {
        $count = $first & 0x7f;
        if ($count === 0 || $count > 4 || $offset + $count > $end) throw new RuntimeException('Comprimento ASN.1 não suportado.');
        $length = 0;
        for ($i = 0; $i < $count; $i++) $length = ($length << 8) | ord($data[$offset++]);
    }
    $valueStart = $offset;
    $valueEnd = $valueStart + $length;
    if ($valueEnd > $end) throw new RuntimeException('Valor ASN.1 ultrapassa o limite.');
    $node = ['class' => $class, 'tag' => $tag, 'constructed' => $constructed, 'value' => substr($data, $valueStart, $length), 'children' => []];
    if ($constructed) {
        while ($offset < $valueEnd) $node['children'][] = buBerRead($data, $offset, $valueEnd);
        if ($offset !== $valueEnd) throw new RuntimeException('Sequência ASN.1 inconsistente.');
    } else $offset = $valueEnd;
    return $node;
}

function buBerTree(string $data): array
{
    $offset = 0;
    $node = buBerRead($data, $offset, strlen($data));
    if ($offset !== strlen($data)) throw new RuntimeException('Bytes extras depois do ASN.1.');
    return $node;
}

function buBerInteger(array $node): int
{
    $bytes = $node['value'];
    if ($bytes === '') return 0;
    $number = 0;
    for ($i = 0, $length = strlen($bytes); $i < $length; $i++) $number = ($number << 8) | ord($bytes[$i]);
    if ((ord($bytes[0]) & 0x80) !== 0) $number -= 1 << (strlen($bytes) * 8);
    return $number;
}

function buFindOctetString(array $node): ?string
{
    if ($node['class'] === 0 && $node['tag'] === 4 && !$node['constructed']) return $node['value'];
    foreach ($node['children'] as $child) {
        $found = buFindOctetString($child);
        if ($found !== null && strlen($found) > 1000) return $found;
    }
    return null;
}

function buUnwrapEnvelope(string $raw): string
{
    $tree = buBerTree($raw);
    $content = buFindOctetString($tree);
    if ($content === null) throw new RuntimeException('O envelope não contém payload BU ASN.1.');
    return $content;
}

function buDecodeVotes(string $raw): array
{
    $root = buBerTree(buUnwrapEnvelope($raw));
    $fields = $root['children'];
    // EntidadeBoletimUrna.resultadosVotacaoPorEleicao is the SEQUENCE OF at
    // field 7 or 8, depending on the optional attendance detail [1].
    $electionList = null;
    foreach (array_slice($fields, 7, 3, true) as $index => $field) {
        if ($field['class'] !== 0 || $field['tag'] !== 16 || !$field['constructed']) continue;
        $first = $field['children'][0] ?? null;
        if ($first && $first['class'] === 0 && $first['tag'] === 16 && count($first['children']) >= 5) {
            $electionList = $field;
            break;
        }
    }
    if (!$electionList) throw new RuntimeException('Não encontrei resultadosVotacaoPorEleicao no BU.');
    $cargoNames = [1 => 'Presidente', 2 => 'Vice-Presidente', 3 => 'Governador', 4 => 'Vice-Governador', 5 => 'Senador', 6 => 'Deputado Federal', 7 => 'Deputado Estadual', 8 => 'Deputado Distrital'];
    $voteNames = [1 => 'NOMINAL', 2 => 'BRANCO', 3 => 'NULO', 4 => 'LEGENDA', 5 => 'CARGO_SEM_CANDIDATO'];
    $results = [];
    foreach ($electionList['children'] as $election) {
        $e = $election['children'];
        $electionId = buBerInteger($e[0]['children'][0] ?? $e[0]);
        $attendanceList = $e[4]['children'] ?? [];
        foreach ($attendanceList as $result) {
            $r = $result['children'];
            $totals = $r[2]['children'] ?? [];
            foreach ($totals as $total) {
                $t = $total['children'];
                $cargoChoice = $t[0]['children'][0] ?? $t[0];
                $cargoCode = buBerInteger($cargoChoice);
                foreach (($t[2]['children'] ?? []) as $vote) {
                    $typeCode = null; $count = null; $candidateNumber = null; $party = null;
                    foreach ($vote['children'] as $field) {
                        if ($field['class'] === 2 && $field['tag'] === 1) $typeCode = buBerInteger($field);
                        elseif ($field['class'] === 2 && $field['tag'] === 2) $count = buBerInteger($field);
                        elseif ($field['class'] === 2 && $field['tag'] === 3) {
                            $ident = $field['children'];
                            if (isset($ident[0], $ident[1])) { $party = buBerInteger($ident[0]); $candidateNumber = buBerInteger($ident[1]); }
                        }
                    }
                    if ($typeCode === null || $count === null) continue;
                    $results[] = ['election' => $electionId, 'office_code' => $cargoCode,
                        'office' => $cargoNames[$cargoCode] ?? 'Cargo ' . $cargoCode,
                        'vote_type' => $voteNames[$typeCode] ?? 'OUTRO', 'number' => $candidateNumber,
                        'party' => $party, 'votes' => $count];
                }
            }
        }
    }
    if (!$results) throw new RuntimeException('O BU foi aberto, mas nenhum voto foi extraído.');
    return $results;
}

function buDecodeSectionIdentity(string $raw): array
{
    $fields = buBerTree(buUnwrapEnvelope($raw))['children'];
    $identity = $fields[3]['children'] ?? [];
    $municipalityZone = $identity[0]['children'] ?? [];
    if (!isset($municipalityZone[0], $municipalityZone[1], $identity[1], $identity[2])) {
        throw new RuntimeException('Identificação da seção ausente no BU.');
    }
    return [
        'municipality' => buBerInteger($municipalityZone[0]),
        'zone' => buBerInteger($municipalityZone[1]),
        'local' => buBerInteger($identity[1]),
        'section' => buBerInteger($identity[2]),
    ];
}
