#!/usr/local/bin/ea-php81 -q
<?php

// Configurações iniciais

set_error_handler('logError');
set_exception_handler('logException');

define("LOG_FILE", __DIR__ . '/unlimited-name-servers.log');

define("CONFIG", configLoader());

logMessage("\n Execution started");

try {
    $input = getPassedData();
    processEvent($input);
    logMessage("Execution completed successfully");
    $response = [
        "result" => 0,
        "message" => "Hook executed successfully"
    ];
    echo json_encode($response);
    exit;
} catch (Exception $e) {
    logMessage("Exception: " . $e->getMessage());
    exit(1);
}

/** 
 * Funções de Carregamento e Preparação de Dados 
 */

/**
 * Loads the configuration from a specified configuration file.
 *
 * @return array Parsed configuration data as an associative array.
 * @throws Exception if the configuration file is not found.
 */
function configLoader()
{
    $configPath = __DIR__ . '/unlimited-name-servers.conf';

    if (!file_exists($configPath)) {
        throw new Exception("Config file not found in " . realpath(dirname($configPath)));
    }
    
    return parse_ini_file($configPath, true);
}


/**
 * Retrieves and parses data passed via standard input.
 *
 * @return array Decoded JSON data from input, with default structure if empty.
 */
function getPassedData()
{
    $raw_data = "";
    $stdin_fh = fopen('php://stdin', 'r');
    if (is_resource($stdin_fh)) {
        stream_set_blocking($stdin_fh, 0);
        while (($line = fgets($stdin_fh, 1024)) !== false) {
            $raw_data .= trim($line);
        }
        fclose($stdin_fh);
    }
    return $raw_data ? json_decode($raw_data, true) : ['context' => [], 'data' => [], 'hook' => []];
}

/**
 * Gets nameservers from the configuration and sanitizes them.
 *
 * @return array An array of cleaned nameservers.
 */
function getNameserver()
{
    $cleanedNameservers = preg_replace('/[^a-zA-Z0-9\.,\-]/', '', CONFIG['nameservers']);
    return explode(',', $cleanedNameservers);
}

/** 
 * Funções Auxiliares para Log e Manipulação de Exceções 
 */

/**
 * Gets the current timestamp for logging.
 *
 * @return string The current timestamp in the format [Y-m-d H:i:s].
 */
function getCurrentTimestamp()
{
    return date('[Y-m-d H:i:s]');
}

/**
 * Logs errors to the specified log file and exits.
 *
 * @param int $errno The error level.
 * @param string $errstr The error message.
 * @param string $errfile The filename where the error occurred.
 * @param int $errline The line number where the error occurred.
 * @return void
 */
function logError($errno, $errstr, $errfile, $errline)
{
    $errorMsg = getCurrentTimestamp() . "Error [$errno]: $errstr in $errfile on line $errline" . PHP_EOL;
    file_put_contents(LOG_FILE, $errorMsg, FILE_APPEND);
    exit(1);
}

/**
 * Logs uncaught exceptions to the specified log file and exits.
 *
 * @param Exception $exception The uncaught exception.
 * @return void
 */
function logException($exception)
{
    $errorMsg = getCurrentTimestamp() . "Uncaught Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine() . PHP_EOL;
    file_put_contents(LOG_FILE, $errorMsg, FILE_APPEND);
    exit(1);
}

/**
 * Logs a custom message to the specified log file.
 *
 * @param string $message The message to be logged.
 * @return void
 */
function logMessage($message)
{
    file_put_contents(LOG_FILE, getCurrentTimestamp() . "$message" . PHP_EOL, FILE_APPEND);
}


/** 
 * Funções Principais para Processamento de Eventos e DNS 
 */

/**
 * Processes the event based on input data and triggers necessary actions.
 *
 * @param array $input The input data containing event context and details.
 * @return void
 * @throws Exception If an unexpected event is encountered or input is invalid.
 */
function processEvent($input)
{
    $event = $input['context']['event'];
    $userName = $input['data']['user'];
    $stage = $input['context']['stage'];

    if (!$userName) {
        $error = "The username was not found in the input data";
        logMessage($error);
        throw new Exception($error);
    }

    logMessage("Processing event: $event on stage: $stage");

    switch ($event) {
        case 'Accounts::Create':
            $domainName = $input['data']['domain'];
            addNS($domainName);
            break;
        case 'Api2::AddonDomain::addaddondomain':
        case 'Api2::Park::park':
            $domainName = $input['data']['args']['newdomain'] ?? $input['data']['args']['domain'];
            addNS($domainName);
            break;
        default:
            $error = "Unexpected event: $event";
            logMessage($error);
            throw new Exception($error);
    }
}

/**
 * Adds NS records for a given domain by checking its SOA record.
 *
 * @param string $domain The domain for which NS records are added.
 * @return bool True if the NS records were added successfully.
 * @throws Exception if the SOA record is not found after the specified attempts.
 */
function addNS($domain)
{
    $maxTries = CONFIG['soa_timeout'];
    $ttl  = CONFIG['ttl'];
    for ($i = 0; $i < $maxTries; $i++) {
        $soaRecord = shell_exec("dig SOA $domain @localhost | grep SOA | tail -1 | awk '{print $7}'");
        $soaRecord = trim($soaRecord);
        if (empty($soaRecord)) {
            logMessage("The domain $domain does not have a SOA, the notify will be sent later. Number of tries: $i");
            sleep(1);
        } else {
            $nameservers = getNameserver();
            // Define os parâmetros para buscar o registro NS existente
            $zoneData = readRegistry($domain); // Função para obter os registros da zona
            $parsedOptions = [
                'dname' => $domain,
                'ttl' => $ttl,
                'type' => 'NS',
                'data' => $nameservers
            ];
            // Verifica se o registro NS já existe na zona
            $nameservers = removeDuplicate($zoneData, $parsedOptions);
            foreach ($nameservers as $nameserver) {
                $retries = 0;
                $output = addCommand($domain, $nameserver, $soaRecord, $ttl);
                logMessage(print_r($output, true));
                while ((getResult($output) == 0) && hasSerialNumberInReason($output) && $retries < 10) {
                    $soaRecord++;
                    $output = addCommand($domain, $nameserver, $soaRecord, $ttl);
                    $retries++;
                }
                if ($retries == 10) {
                    logMessage("SOA record doesn't update for $domain after $retries retries.");
                }
            }
            // Limpa o cache do cPanel
            exec("/usr/local/cpanel/scripts/updateuserdomains");
            return true;
        }
    }
    throw new Exception("SOA record not found for $domain after $maxTries tries.");
}


/**
 * Executes a command to add a nameserver to the specified domain.
 *
 * This function constructs a command to add a nameserver (NS record) to the DNS zone 
 * of a specified domain using the WHM API. It also includes an optional SOA record 
 * and TTL value for the new record.
 *
 * @param string $domain The domain to which the NS record will be added. This is the DNS zone name.
 * @param string $nameserver The nameserver to be added to the DNS zone. This value must be a fully qualified domain name (FQDN).
 * @param string $soaRecord The SOA record serial number to be used in the command. It is used to track the version of the DNS zone.
 * @param int $ttl The TTL (time-to-live) for the DNS record. This value determines how long the record is cached by DNS resolvers.
 * @return string The result of the command execution, typically the output or response from the WHM API.
 */
function addCommand($domain, $nameserver, $soaRecord, $ttl)
{
    $command = sprintf(
        "/usr/local/cpanel/bin/whmapi1 mass_edit_dns_zone zone='%s' serial='%s' add='{\"dname\":\"%s.\", \"ttl\":%d, \"record_type\":\"NS\", \"data\":[\"%s\"]}'",
        $domain,
        $soaRecord,
        $domain,
        $ttl,
        $nameserver
    );

    logMessage("Calling the command: " . $command);
    $output = shell_exec($command);

    return $output;
}


/**
 * Retrieves the SOA (Start of Authority) record for a specified domain using the dig command.
 *
 * This function executes the `dig` command to query the SOA record for a domain on the local server.
 * It checks if the domain is provided, handles errors in command execution, and parses the output
 * to extract the SOA serial number.
 *
 * @param string $domain The domain name to query for the SOA record.
 * @return string|null The SOA serial number if found; null otherwise.
 */
function getSOARecord($domain)
{
    // Verifica se o domínio foi fornecido
    if (empty($domain)) {
        logMessage("No domain provided. Exiting...", 'error');
        exit(1);
    }

    // Define o comando `dig` para buscar o registro SOA do domínio
    $command = "dig @127.0.0.1 $domain soa";

    // Executa o comando e captura a saída
    $digResult = shell_exec($command);

    // Verifica se houve erro ao executar o comando
    if (is_null($digResult)) {
        logMessage("Error executing dig command. Exiting...", 'error');
        exit(1);
    }

    // Inicializa variáveis para armazenar o registro SOA e monitorar a seção de resposta
    $soaRecord = null;
    $insideAnswerSection = false;

    // Converte o resultado em um array, separando cada linha
    $digResult = explode(PHP_EOL, $digResult);

    // Processa cada linha da saída para encontrar a seção de resposta e o registro SOA
    foreach ($digResult as $line) {
        // Detecta o início da "ANSWER SECTION"
        if (strpos($line, "ANSWER SECTION") !== false) {
            $insideAnswerSection = true;
            continue;
        }

        // Se dentro da "ANSWER SECTION", busca a linha do SOA
        if ($insideAnswerSection && strpos($line, "SOA") !== false) {
            // Filtra a linha para extrair somente os campos alfanuméricos
            $columns = preg_split('/[^a-zA-Z0-9.]+/', $line);
            $columns = array_filter($columns);
            $soaRecord = $columns[7] ?? null; // Serial do SOA
            break; // Encerra o loop após encontrar o SOA
        }
    }

    // Verifica se o registro SOA foi encontrado e se está no formato válido
    if ($soaRecord) {
        if (strlen($soaRecord) === 10) {
            return $soaRecord; // Retorna o registro SOA se válido
        } else {
            logMessage("SOA for domain: $domain not in valid form SOA: $soaRecord", 'error');
            exit(1); // Erro se o formato do SOA for inválido
        }
    } else {
        logMessage("SOA for domain: $domain not found! Exiting...", 'error');
        exit(1); // Erro se o SOA não for encontrado
    }
}

/**
 * Extrai o valor de "result" de uma string YAML.
 *
 * @param string $yamlString A string YAML contendo o campo "result".
 * @return int|null O valor do "result" como um inteiro, ou null se não encontrado.
 */
function getResult($yamlString)
{
    if (preg_match('/result:\s*(\d+)/', $yamlString, $matches)) {
        return (int) $matches[1];
    }
    return null;
}

/**
 * Verifica se a string "serial number" está presente no campo "reason" de uma string YAML.
 *
 * @param string $yamlString A string YAML contendo o campo "reason".
 * @return bool Retorna true se "serial number" estiver presente em "reason", caso contrário false.
 */
function hasSerialNumberInReason($yamlString)
{
    // Primeiro, extraímos o conteúdo do campo 'reason'
    if (preg_match('/reason:\s*(.*)/', $yamlString, $matches)) {
        $reason = trim($matches[1]);
        // Verificamos se a substring "serial number" está presente
        return strpos($reason, 'serial number') !== false;
    }
    return false;
}

/**
 * Procura um registro específico na zona com base nas opções fornecidas.
 *
 * @param array $zoneData O array contendo os registros da zona.
 * @param array $parsedOptions As opções de pesquisa, incluindo tipo, nome, TTL e nsdname.
 * @return array Retorna um array com todas os nameservers que não foram rencotrados
 */
function removeDuplicate($zoneData, $parsedOptions)
{
    $nameservers = $parsedOptions['data'];
    logMessage(print_r($parsedOptions, true));
    foreach ($zoneData as $registry) {

        if (
            !isset($registry['type']) ||
            !isset($registry['name']) ||
            !isset($registry['ttl']) ||
            !isset($registry['nsdname'])
        ) {
            continue;
        }

        logMessage(print_r($registry, true));


        // Verifica se o tipo de registro e outros critérios básicos são válidos
        if (
            $parsedOptions['type'] == $registry['type'] &&
            $parsedOptions['ttl'] == $registry['ttl']
        ) {
            if(($key = array_search($registry['nsdname'], $nameservers)) !== false) {
                unset($nameservers[$key]);
            }
        }
    }
    logMessage(print_r($nameservers,true));
    return $nameservers;
}
    


/**
 * Lê os registros de uma zona de domínio usando o comando WHMAPI.
 *
 * @param string $domain O nome do domínio a ser consultado.
 * @return array Um array contendo os registros de zona identificados, formatados como arrays associativos.
 */
function readRegistry($domain)
{
    $command = "/usr/local/cpanel/bin/whmapi1 dumpzone zone='$domain'";
    $registries = shell_exec($command);

    // Dividindo os registros com base em "Line:"
    $registries = preg_split('/(?=Line:)/', $registries);
    $identRegistries = [];

    foreach ($registries as &$registry) {
        $registry = explode(PHP_EOL, trim($registry)); // Divide o texto em linhas
        $registryData = [];

        foreach ($registry as &$line) {
            $line = trim($line); // Remove espaços em branco do início e do fim
            if (strpos($line, ':') !== false) { // Verifica se a linha contém ':'
                list($key, $value) = explode(':', $line, 2); // Divide a linha em chave e valor
                $registryData[trim($key)] = trim($value); // Adiciona ao array removendo espaços em branco
            }
        }

        // Adiciona o array associativo ao array principal, se não estiver vazio
        if (!empty($registryData)) {
            $identRegistries[] = $registryData;
        }
    }
    return $identRegistries;
}
