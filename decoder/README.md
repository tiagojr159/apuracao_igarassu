# Boletins de Urna por seção

O ambiente de produção usa apenas PHP 7.4+ e Apache. `bu_asn.php` faz a leitura
BER/ASN.1 necessária para abrir o envelope do BU e extrair votos nominais. Não
há chamada a Python nem necessidade de instalar pacote Python no servidor.

`dados_mapa.php?consulta=secoes-candidato` consulta a configuração EA16 de PE,
os EA18 de cada seção e os arquivos BU indicados nesses EA18. O processamento é
feito em lotes e os BUs baixados ficam em `data/cache_tse_2026_bu/` por uma hora.
O cache exige permissão de escrita nessa pasta e o Apache precisa conseguir
fazer requisições HTTPS de saída para `resultados.tse.jus.br`.

O parser foi conferido contra `data/test_section_0085_0002.bu`: extraiu 133
registros e os identificadores e contagens coincidiram com o JSON diagnóstico.
O schema de referência V2 fica em `bu_v2.asn1`; a estrutura de voto usada está
descrita em `TotalVotosCargo` e `TotalVotosVotavel`.

Seções agregadas não têm votação independente no TSE: aparecem vinculadas à
seção principal, sem duplicar os votos. Se o EA18 ainda não tiver BU totalizado,
o modal informa que aquela seção está pendente/indisponível.
