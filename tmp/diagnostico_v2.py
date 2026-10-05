import json
from pathlib import Path
import asn1tools

ROOT = Path(__file__).resolve().parent.parent
spec_path = ROOT / "tmp" / "dataUrnas-br" / "spec" / "v2" / "bu.asn1"
bu_path = ROOT / "data" / "test_section_0085_0002.bu"

compiler = asn1tools.compile_files([str(spec_path)], codec="ber")
raw = bu_path.read_bytes()
envelope = compiler.decode("EntidadeEnvelopeGenerico", raw)
bu = compiler.decode("EntidadeBoletimUrna", envelope["conteudo"])

def json_safe(value):
    if isinstance(value, bytes):
        return {"__bytes_hex__": value.hex()}
    if isinstance(value, tuple):
        return [json_safe(part) for part in value]
    if isinstance(value, list):
        return [json_safe(part) for part in value]
    if isinstance(value, dict):
        return {key: json_safe(part) for key, part in value.items()}
    return value


def unwrap_choice(value):
    if isinstance(value, tuple) and len(value) == 2:
        return value[1]
    return value


def enum_member(value):
    value = unwrap_choice(value)
    if isinstance(value, tuple) and len(value) == 2:
        return value[1]
    return value


cargo_codes = {
    "presidente": 1, "vicePresidente": 2, "governador": 3,
    "viceGovernador": 4, "senador": 5, "deputadoFederal": 6,
    "deputadoEstadual": 7, "deputadoDistrital": 8,
    "primeiroSuplenteSenador": 9, "segundoSuplenteSenador": 10,
    "prefeito": 11, "vicePrefeito": 12, "vereador": 13,
}
cargo_names = {
    1: "Presidente", 2: "Vice-Presidente", 3: "Governador",
    4: "Vice-Governador", 5: "Senador", 6: "Deputado Federal",
    7: "Deputado Estadual", 8: "Deputado Distrital",
    9: "Primeiro Suplente de Senador", 10: "Segundo Suplente de Senador",
}
tipo_voto_names = {
    "nominal": "NOMINAL", "branco": "BRANCO", "nulo": "NULO",
    "legenda": "LEGENDA", "cargoSemCandidato": "CARGO_SEM_CANDIDATO",
}

identificacao = bu["identificacaoSecao"]
mz = identificacao["municipioZona"]
rows = []
elections = []
for election in bu["resultadosVotacaoPorEleicao"]:
    election_id = unwrap_choice(election["idEleicao"])
    election_summary = {
        "id_eleicao": election_id,
        "eleitores_aptos": election["qtdEleitoresAptos"],
        "eleitores_aptos_secao": election["qtdEleitoresAptosSecao"],
        "eleitores_aptos_tte": election["qtdEleitoresAptosTTE"],
        "cargos": [],
    }
    for result in election["resultadosVotacao"]:
        cargo_type = enum_member(result["tipoCargo"])
        for total in result["totaisVotosCargo"]:
            cargo_member = enum_member(total["codigoCargo"])
            if isinstance(cargo_member, str):
                cargo_code = cargo_codes.get(cargo_member)
            else:
                cargo_code = cargo_member
            cargo_result = {
                "codigo_cargo": cargo_code,
                "cargo": cargo_names.get(cargo_code, str(cargo_member)),
                "tipo_cargo": cargo_type,
                "ordem_impressao": total["ordemImpressao"],
                "votos": [],
            }
            for vote in total["votosVotaveis"]:
                vote_type = enum_member(vote["tipoVoto"])
                votable = vote.get("identificacaoVotavel") or {}
                row = {
                    "codigo_eleicao": election_id,
                    "codigo_cargo": cargo_code,
                    "cargo": cargo_names.get(cargo_code, str(cargo_member)),
                    "tipo_cargo": cargo_type,
                    "tipo_voto": tipo_voto_names.get(vote_type, str(vote_type)),
                    "numero": votable.get("codigo"),
                    "partido": votable.get("partido"),
                    "votos": vote["quantidadeVotos"],
                }
                rows.append(row)
                cargo_result["votos"].append(row)
            election_summary["cargos"].append(cargo_result)
    elections.append(election_summary)

checks = {
    "municipio_24350": mz["municipio"] == 24350,
    "zona_85": mz["zona"] == 85,
    "secao_2": identificacao["secao"] == 2,
    "local_1066": identificacao["local"] == 1066,
    "fase_oficial": envelope["fase"] == "oficial",
    "tipo_envelope_bu": envelope["tipoEnvelope"] == "envelopeBoletimUrna",
    "eleicao_2026_presidente_6257": any(e["id_eleicao"] == 6257 for e in elections),
    "tem_resultados": bool(rows),
}
if not all(checks.values()):
    raise SystemExit("Falha nas validações do BU: " + json.dumps(checks, ensure_ascii=False))

semantic = {
    "uf": "PE",
    "municipio": mz["municipio"],
    "zona": identificacao["municipioZona"]["zona"],
    "secao": identificacao["secao"],
    "local_votacao": identificacao["local"],
    "id_pleito": unwrap_choice(envelope["cabecalho"]["idEleitoral"]),
    "fase": envelope["fase"],
    "data_hora_geracao": envelope["cabecalho"]["dataGeracao"],
    "data_hora_emissao": bu["dataHoraEmissao"],
    "identificacao_urna": json_safe(bu["urna"]),
    "eleitores_compareceram": bu["qtdEleitoresCompareceram"],
    "detalhamento_comparecimento": bu.get("detalhamentoComparecimento"),
    "eleicoes": elections,
    "validacoes": checks,
    "schema_referencia": "dataUrnas-br/spec/v2/bu.asn1 (V2, 2024+; validado contra BU 2026 por campos de identificacao e resultados)",
}
output_dir = ROOT / "output"
output_dir.mkdir(exist_ok=True)
(ROOT / "tmp" / "bu_0085_0002_v2_completo.json").write_text(
    json.dumps({
        "envelope": json_safe({k: v for k, v in envelope.items() if k != "conteudo"}),
        "bu": json_safe(bu),
    }, ensure_ascii=False, indent=2), encoding="utf-8"
)
(output_dir / "bu_0085_0002.json").write_text(
    json.dumps(semantic, ensure_ascii=False, indent=2), encoding="utf-8"
)
with (output_dir / "votos_0085_0002.csv").open("w", encoding="utf-8-sig", newline="") as csv_file:
    import csv
    writer = csv.DictWriter(csv_file, fieldnames=[
        "COD_CARGO", "CARGO", "TIPO_VOTO", "NUMERO", "PARTIDO", "VOTOS"
    ], delimiter=";")
    writer.writeheader()
    for row in rows:
        writer.writerow({
            "COD_CARGO": row["codigo_cargo"], "CARGO": row["cargo"],
            "TIPO_VOTO": row["tipo_voto"], "NUMERO": row["numero"],
            "PARTIDO": row["partido"], "VOTOS": row["votos"],
        })

print("VALIDACOES:", json.dumps(checks, ensure_ascii=False))
print("METADADOS:", json.dumps({
    "municipio": mz["municipio"], "zona": mz["zona"], "secao": identificacao["secao"],
    "local": identificacao["local"], "comparecimento": bu["qtdEleitoresCompareceram"],
    "versao_urna": bu["urna"].get("versaoVotacao"),
    "eleicoes": [e["id_eleicao"] for e in elections], "registros_voto": len(rows),
}, ensure_ascii=False))
for row in rows:
    print("VOTO:", row["codigo_cargo"], row["cargo"], row["tipo_voto"], row["numero"], row["partido"], row["votos"])
print("ARQUIVOS: output/bu_0085_0002.json, output/votos_0085_0002.csv")
