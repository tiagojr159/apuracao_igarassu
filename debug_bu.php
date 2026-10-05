<?php
/**
 * ASN.1 BER/DER structural inspector for a TSE BU.
 *
 * This deliberately reports universal tags and context-specific tag numbers only.
 * Meaningful field names and election values require the matching official TSE
 * schema; do not infer them from tag order.
 * Compatible with PHP 7.4+.
 */

function bu_read_byte(string $data, int &$offset, int $end): int
{
    if ($offset >= $end) {
        throw new RuntimeException('Unexpected end of ASN.1 value.');
    }
    return ord($data[$offset++]);
}

function bu_read_length(string $data, int &$offset, int $end): int
{
    $first = bu_read_byte($data, $offset, $end);
    if (($first & 0x80) === 0) {
        return $first;
    }
    $count = $first & 0x7f;
    if ($count === 0) {
        throw new RuntimeException('Indefinite BER length is not supported by this diagnostic.');
    }
    if ($count > 4 || $offset + $count > $end) {
        throw new RuntimeException('Invalid or unsupported ASN.1 length.');
    }
    $length = 0;
    for ($i = 0; $i < $count; $i++) {
        $length = ($length << 8) | bu_read_byte($data, $offset, $end);
    }
    return $length;
}

function bu_tag_name(int $class, int $tag): string
{
    $classes = array('UNIVERSAL', 'APPLICATION', 'CONTEXT', 'PRIVATE');
    $universal = array(
        1 => 'BOOLEAN', 2 => 'INTEGER', 3 => 'BIT STRING', 4 => 'OCTET STRING',
        5 => 'NULL', 6 => 'OBJECT IDENTIFIER', 10 => 'ENUMERATED', 12 => 'UTF8 STRING',
        16 => 'SEQUENCE', 17 => 'SET', 18 => 'NUMERIC STRING', 19 => 'PRINTABLE STRING',
        20 => 'T61 STRING', 21 => 'VIDEOTEX STRING', 22 => 'IA5 STRING',
        23 => 'UTC TIME', 24 => 'GENERALIZED TIME', 26 => 'VISIBLE STRING',
        27 => 'GENERAL STRING', 28 => 'UNIVERSAL STRING', 30 => 'BMP STRING'
    );
    if ($class === 0 && isset($universal[$tag])) {
        return $universal[$tag];
    }
    return $classes[$class] . ' TAG ' . $tag;
}

function bu_printable_value(int $class, int $tag, string $value): string
{
    if ($class !== 0) {
        return '';
    }
    if ($tag === 2 || $tag === 10) {
        $number = 0;
        $size = strlen($value);
        if ($size > 0 && $size <= 4) {
            for ($i = 0; $i < $size; $i++) {
                $number = ($number << 8) | ord($value[$i]);
            }
            if ((ord($value[0]) & 0x80) !== 0) {
                $number -= 1 << ($size * 8);
            }
            return ' = ' . $number;
        }
    }
    if (in_array($tag, array(12, 18, 19, 20, 21, 22, 23, 24, 26, 27), true)) {
        $text = preg_replace('/[^\x20-\x7e]/', '.', $value);
        return ' = "' . substr($text, 0, 100) . (strlen($text) > 100 ? '…' : '') . '"';
    }
    return '';
}

function bu_print_tree(string $data, int $start, int $end, int $depth, int &$nodes): void
{
    $offset = $start;
    while ($offset < $end) {
        if (++$nodes > 20000) {
            throw new RuntimeException('Diagnostic node limit reached.');
        }
        $elementStart = $offset;
        $identifier = bu_read_byte($data, $offset, $end);
        $class = ($identifier >> 6) & 0x03;
        $constructed = ($identifier & 0x20) !== 0;
        $tag = $identifier & 0x1f;
        if ($tag === 0x1f) {
            $tag = 0;
            do {
                $part = bu_read_byte($data, $offset, $end);
                if ($tag > 0x0fffffff) {
                    throw new RuntimeException('ASN.1 tag number is too large.');
                }
                $tag = ($tag << 7) | ($part & 0x7f);
            } while (($part & 0x80) !== 0);
        }
        $length = bu_read_length($data, $offset, $end);
        $valueStart = $offset;
        $valueEnd = $valueStart + $length;
        if ($valueEnd > $end) {
            throw new RuntimeException('ASN.1 value extends beyond its parent.');
        }
        $name = bu_tag_name($class, $tag);
        echo str_repeat('  ', $depth) . $name . ($constructed ? ' [' : '') . ' (length ' . $length . ')';
        if (!$constructed) {
            echo bu_printable_value($class, $tag, substr($data, $valueStart, $length));
        }
        echo PHP_EOL;
        if ($constructed) {
            if ($depth >= 64) {
                throw new RuntimeException('ASN.1 nesting depth limit reached.');
            }
            bu_print_tree($data, $valueStart, $valueEnd, $depth + 1, $nodes);
            echo str_repeat('  ', $depth) . ']' . PHP_EOL;
        }
        $offset = $valueEnd;
        if ($offset <= $elementStart) {
            throw new RuntimeException('ASN.1 decoder did not advance.');
        }
    }
}

if ($argc < 2 || !is_file($argv[1])) {
    fwrite(STDERR, "Uso: php debug_bu.php caminho/do/arquivo.bu\n");
    exit(2);
}

$bytes = file_get_contents($argv[1]);
if ($bytes === false || $bytes === '') {
    fwrite(STDERR, "Não foi possível ler o arquivo BU ou ele está vazio.\n");
    exit(1);
}

echo "ASN.1 estrutural (nomes semânticos dependem do schema oficial 2026)\n";
echo 'Arquivo: ' . $argv[1] . ' (' . strlen($bytes) . " bytes)\n";
try {
    $nodeCount = 0;
    bu_print_tree($bytes, 0, strlen($bytes), 0, $nodeCount);
    echo 'Nós ASN.1: ' . $nodeCount . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Erro ao inspecionar ASN.1: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
