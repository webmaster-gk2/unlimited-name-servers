#!/usr/local/php81/bin/php -q
<?php

// Configuration

define("CONFIG", array(
    "soa_timeout" => 15,
    "ttl" => 86400,
    "nameservers" => ""
));



// Configurações iniciais

set_error_handler('logError');
set_exception_handler('logException');

define("LOG_FILE", '/var/log/messages');

logMessage("Script started with arguments: " . json_encode($argv));

try {
    $input = getPassedData($argv); // Passa os argumentos do CLI para a função
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
 * Retrieves and parses data from CLI arguments in DirectAdmin hook.
 *
 * @param array $argv CLI arguments passed from the DirectAdmin hook.
 * @return array Data with domain and context for processing.
 */
function getPassedData($argv)
{
    // DirectAdmin passes the domain as the second argument in dns_create_post.sh
    $domain = $argv[1] ?? null;
    if (!$domain) {
        throw new Exception("No domain passed to hook script.");
    }
    return ['context' => ['event' => 'DNS::Create'], 'data' => ['domain' => $domain]];
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
    $errorMsg = getCurrentTimestamp() . " unlimited-name-servers: Error [$errno]: $errstr in $errfile on line $errline" . PHP_EOL;
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
    $errorMsg = getCurrentTimestamp() . " unlimited-name-servers: Uncaught Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine() . PHP_EOL;
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
    file_put_contents(LOG_FILE, getCurrentTimestamp() . " unlimited-name-servers: $message" . PHP_EOL, FILE_APPEND);
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
    $domainName = $input['data']['domain'];

    if (!$domainName) {
        $error = "Domain name was not found in the input data";
        logMessage($error);
        throw new Exception($error);
    }

    logMessage("Processing event: $event for domain: $domainName");

    switch ($event) {
        case 'DNS::Create':
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
    $ttl  = CONFIG['ttl'];
    $nameservers = getNameserver();

    // Obtém os registros da zona no DirectAdmin
    $zoneData = readRegistry($domain);
    $parsedOptions = [
        'dname' => $domain,
        'ttl' => $ttl,
        'type' => 'NS',
        'data' => $nameservers
    ];

    $nameservers = removeDuplicate($zoneData, $parsedOptions);
    foreach ($nameservers as $nameserver) {
        $output = addCommand($domain, $nameserver, $ttl);
        logMessage(print_r($output, true));
    }

    return true;
}

/**
 * Adiciona um registro NS diretamente no arquivo de zona do domínio.
 *
 * @param string $domain O domínio ao qual o registro NS será adicionado.
 * @param string $nameserver O nameserver a ser adicionado.
 * @param int $ttl O TTL para o registro DNS.
 * @return string Mensagem de sucesso ao adicionar o registro.
 */
function addCommand($domain, $nameserver, $ttl)
{
    $zoneFilePath = "/var/named/{$domain}.db";
    
    if (!file_exists($zoneFilePath)) {
        logMessage("Zone file not found for domain: $domain", 'error');
        throw new Exception("Zone file not found for domain: $domain");
    }
    
    $nsRecord = "{$domain}. {$ttl} IN NS {$nameserver}.";

    // Verifica se o registro já existe
    $zoneData = file_get_contents($zoneFilePath);
    if (strpos($zoneData, $nsRecord) !== false) {
        logMessage("NS record already exists for domain: $domain with nameserver: $nameserver");
        return "NS record already exists";
    }

    // Adiciona o novo registro NS ao final do arquivo de zona
    file_put_contents($zoneFilePath, PHP_EOL . $nsRecord, FILE_APPEND);

    // Reinicia o serviço DNS para aplicar as mudanças
    shell_exec('systemctl restart named &');

    logMessage("NS record added for domain: $domain with nameserver: $nameserver");
    return "NS record added successfully";
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
            continue; // Pula registros incompletos
        }

        logMessage(print_r($registry, true));

        // Verifica se o tipo de registro e outros critérios básicos são válidos
        if (
            $parsedOptions['type'] == $registry['type'] &&
            $parsedOptions['ttl'] == $registry['ttl']
        ) {
            $nsdname = trim($registry['nsdname']); // Assegura que o valor não é nulo antes de usar trim
            if (($key = array_search($nsdname, $nameservers)) !== false) {
                unset($nameservers[$key]);
            }
        }
    }
    logMessage(print_r($nameservers, true));
    return $nameservers;
}

/**
 * Obtém a URL de autenticação da API do DirectAdmin executando o comando apropriado.
 *
 * @return string A URL de autenticação para a API do DirectAdmin.
 */
function getApiUrl()
{
    // Executar o comando para obter a URL da API com autenticação embutida
    $command = '/usr/local/directadmin/directadmin api-url --user=admin';
    $apiUrl = shell_exec($command);

    // Verificar se a execução foi bem-sucedida e retornar a URL
    if ($apiUrl === null) {
        logMessage("Erro ao obter a URL da API do DirectAdmin.");
        return null; // Retorna null caso haja erro ao obter a URL
    }

    // Limpar a URL (remover quebras de linha, espaços extras)
    return trim($apiUrl);
}

/**
 * Lê os registros DNS de um domínio diretamente do arquivo de zona.
 *
 * @param string $domain O domínio para o qual os registros DNS serão lidos.
 * @return array Um array com os registros DNS da zona do domínio.
 */
function readRegistry($domain)
{
    $zoneFilePath = "/var/named/{$domain}.db";
    
    if (!file_exists($zoneFilePath)) {
        logMessage("Zone file not found for domain: $domain", 'error');
        throw new Exception("Zone file not found for domain: $domain");
    }
    
    $zoneData = file_get_contents($zoneFilePath);
    
    if ($zoneData === false) {
        logMessage("Failed to read zone file for domain: $domain", 'error');
        throw new Exception("Failed to read zone file for domain: $domain");
    }
    
    return parseZoneData($zoneData);
}

/**
 * Analisa os dados do arquivo de zona para extrair os registros DNS.
 *
 * @param string $zoneData O conteúdo do arquivo de zona.
 * @return array Um array de registros DNS.
 */
function parseZoneData($zoneData)
{
    $records = [];
    $lines = explode(PHP_EOL, $zoneData);
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, ';') === 0) {
            continue; // Ignora linhas vazias e comentários
        }
        
        $parts = preg_split('/\s+/', $line);
        if (count($parts) >= 4) {
            $records[] = [
                'name' => $parts[0],
                'ttl' => $parts[1],
                'type' => $parts[2],
                'nsdname' => $parts[3],
            ];
        }
    }
    
    return $records;
}
