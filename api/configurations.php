<?php

declare(strict_types=1);

const DATA_DIRECTORY = __DIR__ . "/../data";
const DATA_FILE = DATA_DIRECTORY . "/configurations.json";

const CONFIGURATION_FIELDS = [
    "annualKm",
    "fuelConsumption",
    "fuelPrice",
    "combustionMaintenance",
    "combustionRepairs",
    "electricityPrice",
    "electricConsumption",
    "electricMaintenance",
    "salePrice",
    "electricPurchasePrice",
    "movesAid",
    "irpfAid",
    "caeAid",
    "scrapAid",
    "downPayment",
    "installments",
    "apr",
    "fuelType",
];

header("Content-Type: application/json; charset=utf-8");

try {
    match ($_SERVER["REQUEST_METHOD"] ?? "GET") {
        "GET" => handle_get(),
        "POST" => handle_post(),
        default => respond(405, ["error" => "Método no permitido."]),
    };
} catch (Throwable $error) {
    respond(500, ["error" => "No se pudo acceder a las configuraciones guardadas."]);
}

function handle_get(): void
{
    $records = sorted_records(read_records());
    $id = trim((string) ($_GET["id"] ?? ""));

    if ($id !== "") {
        foreach ($records as $record) {
            if (($record["id"] ?? "") === $id) {
                respond(200, ["configuration" => client_record($record, true)]);
            }
        }

        respond(404, ["error" => "Configuración no encontrada."]);
    }

    respond(200, array_map(
        static fn(array $record): array => client_record($record, false),
        $records,
    ));
}

function handle_post(): void
{
    $payload = json_decode((string) file_get_contents("php://input"), true);

    if (!is_array($payload)) {
        respond(400, ["error" => "Solicitud JSON no válida."]);
    }

    $label = clean_label($payload["label"] ?? "");
    if ($label === "") {
        respond(422, ["error" => "La etiqueta es obligatoria."]);
    }

    $configuration = clean_configuration($payload["configuration"] ?? null);
    $record = [
        "id" => bin2hex(random_bytes(8)),
        "label" => $label,
        "createdAt" => current_timestamp(),
        "values" => $configuration,
    ];

    append_record($record);

    respond(201, ["configuration" => client_record($record, true)]);
}

function clean_label(mixed $value): string
{
    if (!is_scalar($value)) {
        return "";
    }

    $label = preg_replace("/\s+/u", " ", trim((string) $value)) ?? "";
    return truncate_text($label, 80);
}

function clean_configuration(mixed $value): array
{
    if (!is_array($value)) {
        respond(422, ["error" => "La configuración no es válida."]);
    }

    $configuration = [];
    foreach (CONFIGURATION_FIELDS as $field) {
        if (!array_key_exists($field, $value) || !is_scalar($value[$field])) {
            $configuration[$field] = "";
            continue;
        }

        $configuration[$field] = truncate_text(trim((string) $value[$field]), 60);
    }

    return $configuration;
}

function truncate_text(string $value, int $length): string
{
    if (function_exists("mb_substr")) {
        return mb_substr($value, 0, $length, "UTF-8");
    }

    return substr($value, 0, $length);
}

function current_timestamp(): string
{
    return (new DateTimeImmutable("now", new DateTimeZone("UTC")))->format("Y-m-d\TH:i:s.v\Z");
}

function read_records(): array
{
    ensure_storage_exists();
    $handle = fopen(DATA_FILE, "c+");

    if ($handle === false) {
        throw new RuntimeException("Cannot open configuration storage.");
    }

    try {
        if (!flock($handle, LOCK_SH)) {
            throw new RuntimeException("Cannot lock configuration storage.");
        }

        $contents = stream_get_contents($handle);
        return decode_records($contents === false ? "" : $contents);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function append_record(array $record): void
{
    ensure_storage_exists();
    $handle = fopen(DATA_FILE, "c+");

    if ($handle === false) {
        throw new RuntimeException("Cannot open configuration storage.");
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException("Cannot lock configuration storage.");
        }

        $contents = stream_get_contents($handle);
        $records = decode_records($contents === false ? "" : $contents);
        $records[] = $record;

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode(
            sorted_records($records),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
        fflush($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function decode_records(string $contents): array
{
    if (trim($contents) === "") {
        return [];
    }

    $records = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($records)) {
        return [];
    }

    return array_values(array_filter($records, static fn(mixed $record): bool => is_array($record)));
}

function sorted_records(array $records): array
{
    usort($records, static function (array $first, array $second): int {
        return strcmp((string) ($second["createdAt"] ?? ""), (string) ($first["createdAt"] ?? ""));
    });

    return $records;
}

function client_record(array $record, bool $include_values): array
{
    $client_record = [
        "id" => (string) ($record["id"] ?? ""),
        "label" => (string) ($record["label"] ?? "Sin etiqueta"),
        "createdAt" => (string) ($record["createdAt"] ?? ""),
    ];

    if ($include_values) {
        $client_record["values"] = is_array($record["values"] ?? null) ? $record["values"] : [];
    }

    return $client_record;
}

function ensure_storage_exists(): void
{
    if (!is_dir(DATA_DIRECTORY) && !mkdir(DATA_DIRECTORY, 0775, true) && !is_dir(DATA_DIRECTORY)) {
        throw new RuntimeException("Cannot create configuration storage directory.");
    }

    if (!file_exists(DATA_FILE)) {
        file_put_contents(DATA_FILE, "[]");
    }
}

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
