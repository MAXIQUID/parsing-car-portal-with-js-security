<?php

//
// https://2kata.ru/_novikov/index.php
//

class CarParser
{
    // Constants for configuration and readability
    private const COOKIES_FILE_NAME = 'cookies_file.txt';
    private const COPART_COOKIES_SUFFIX = '.copart.txt';
    private const IAAI_COOKIES_SUFFIX = '.iaai.txt';
    private const NODEJS_COOKIE_SCRIPT_IAAI = 'cookie.js';
    private const NODEJS_COOKIE_SCRIPT_COPART = 'cookieCopart.js';
    private const MIN_COOKIE_VALUE_LENGTH = 20; // Used in cookies_parse_from_file

    // Public properties (consider making private with getters if stricter control is needed)
    public string $incomeUrl;
    public int $curlDebug = 0;

    // Private properties for internal state
    private string $fileCookiesPath;
    private string $currentLotId = '';
    private string $currentCookiesFileContent = '';
    private string $currentCookiesFromNodeJs = '';
    private string $currentJsonResponse = '';

    /**
     * Constructor for CarParser.
     *
     * @param string $incomeUrl The URL of the car listing to parse.
     */
    public function __construct(string $incomeUrl)
    {
        $this->incomeUrl = $incomeUrl;

        // Determine base cookie file path based on environment
        if ($_SERVER['HTTP_HOST'] === 'car-parse.loc') {
            $this->fileCookiesPath = 'C:/OSPanel/domains/car-parse.loc/' . self::COOKIES_FILE_NAME;
        } else {
            $this->fileCookiesPath = __DIR__ . '/' . self::COOKIES_FILE_NAME;
        }

        // Route to the appropriate parser based on the URL
        if (str_contains($this->incomeUrl, 'copart.com')) {
            $this->fileCookiesPath .= self::COPART_COOKIES_SUFFIX;
            // Load existing cookies content at construction for Copart specific logic
            $this->currentCookiesFileContent = $this->parseCookiesFromFile();
            echo $this->handleCopart();
        } elseif (str_contains($this->incomeUrl, 'iaai.com')) {
            $this->fileCookiesPath .= self::IAAI_COOKIES_SUFFIX;
            echo $this->handleIaai();
        } else {
            echo json_encode([
                'error' => 1,
                'error_desc' => 'Wrong URL',
                'url' => $this->incomeUrl,
            ]);
        }
    }

    /**
     * Handles parsing logic for Copart URLs.
     *
     * @return string JSON encoded result or error.
     */
    private function handleCopart(): string
    {
        preg_match('~/lot/([0-9]+)~', $this->incomeUrl, $matches);
        if (!isset($matches[1])) {
            $this->log('copart_bad.txt', ['error_desc' => 'Could not extract lot ID', 'url' => $this->incomeUrl]);
            return json_encode([
                'error' => 1,
                'error_desc' => 'Could not extract lot ID',
                'url' => $this->incomeUrl,
            ]);
        }
        $this->currentLotId = $matches[1];
        $allRequestedListDebug = [];

        // Attempt initial request with existing cookies (if any)
        $this->currentJsonResponse = $this->sendHttpRequest(
            "https://www.copart.com/public/data/lotdetails/solr/" . $this->currentLotId,
            "GET",
            $this->getCopartHeaders()
            // Cookies are handled by CURLOPT_COOKIEFILE/CURLOPT_COOKIEJAR
        );

        $allRequestedListDebug[] = [
            'name' => 'First step result (with existing file cookies)',
            'current_cookies_file_content' => $this->parseCookiesFromFile(),
            'current_cookies_from_nodejs' => 'N/A yet',
            'response' => $this->currentJsonResponse,
        ];

        // Check if the initial request was blocked or empty
        if ($this->isCopartBlocked($this->currentJsonResponse)) {
            // Attempt to get cookies/data using Node.js (Puppeteer)
            $nodeJsResult = $this->nodeJsRequestGetCookiesAndData();
            $this->currentCookiesFromNodeJs = $nodeJsResult['cookies'];
            $this->currentJsonResponse = $nodeJsResult['data'];

            $allRequestedListDebug[] = [
                'name' => 'Node.js (Puppeteer) attempt result',
                'current_cookies_file_content' => $this->parseCookiesFromFile(),
                'current_cookies_from_nodejs' => $this->currentCookiesFromNodeJs,
                'response' => $this->currentJsonResponse,
            ];

            // If Node.js already got the data, process it
            $parsedData = $this->tryParseCopartJson($this->currentJsonResponse);
            if ($parsedData !== null) {
                $this->log('copart_good.txt', [
                    'comment' => 'NODEJS handler worked and provided direct data',
                    'result' => $parsedData,
                    'current_cookies_file_content' => $this->parseCookiesFromFile(),
                    'current_cookies_from_nodejs' => $this->currentCookiesFromNodeJs,
                    'response' => $this->currentJsonResponse,
                    'debug' => $allRequestedListDebug,
                ]);
                return json_encode($parsedData);
            }

            // If Node.js provided new cookies but not direct data, try again with injected cookies
            if (!empty($this->currentCookiesFromNodeJs)) {
                $this->currentJsonResponse = $this->sendHttpRequest(
                    "https://www.copart.com/public/data/lotdetails/solr/" . $this->currentLotId,
                    "GET",
                    array_merge($this->getCopartHeaders(), ['cookie: ' . $this->currentCookiesFromNodeJs])
                );

                $allRequestedListDebug[] = [
                    'name' => 'Second step result (with Node.js injected cookies)',
                    'current_cookies_file_content' => $this->parseCookiesFromFile(),
                    'current_cookies_from_nodejs' => $this->currentCookiesFromNodeJs,
                    'response' => $this->currentJsonResponse,
                ];
            } else {
                // If Node.js failed to get any useful cookies, clear existing and report failure
                @unlink($this->fileCookiesPath);
                $this->log('copart_bad.txt', [
                    'error_desc' => 'Node.js did not provide useful cookies.',
                    'current_cookies_file_content' => $this->parseCookiesFromFile(),
                    'current_cookies_from_nodejs' => $this->currentCookiesFromNodeJs,
                    'response' => $this->currentJsonResponse,
                    'debug' => $allRequestedListDebug,
                ]);
                return json_encode([
                    'error' => 1,
                    'error_desc' => 'Failed to obtain necessary cookies from Node.js (potential ban).',
                    'url' => $this->incomeUrl,
                ]);
            }
        }

        // Final attempt to parse the JSON response
        $parsedData = $this->tryParseCopartJson($this->currentJsonResponse);
        if ($parsedData !== null) {
            $this->log('copart_good.txt', [
                'comment' => 'PHP handler worked (possibly after Node.js cookie injection)',
                'result' => $parsedData,
                'current_cookies_file_content' => $this->parseCookiesFromFile(),
                'current_cookies_from_nodejs' => $this->currentCookiesFromNodeJs,
                'response' => $this->currentJsonResponse,
                'debug' => $allRequestedListDebug,
            ]);
            return json_encode($parsedData);
        }

        // If no data was found after all attempts
        $this->log('copart_bad.txt', [
            'url' => $this->incomeUrl,
            'debug' => $allRequestedListDebug,
            'response' => $this->currentJsonResponse,
            'error_desc' => 'No data found in response after all attempts',
        ]);

        return json_encode([
            'error' => 1,
            'error_desc' => 'Failed to retrieve data from Copart (empty or unparsable response).',
            'url' => $this->incomeUrl,
        ]);
    }

    /**
     * Checks if the Copart response indicates a block.
     *
     * @param string $response The HTTP response body.
     * @return bool True if blocked, false otherwise.
     */
    private function isCopartBlocked(string $response): bool
    {
        return str_contains($response, '_Incapsula_Resource')
            || str_contains($response, 'Request unsuccessful')
            || str_contains($response, 'Hacking attempt')
            || trim($response) === ''
            || strlen($response) < self::MIN_COOKIE_VALUE_LENGTH; // Reusing constant for length check
    }

    /**
     * Attempts to parse the Copart JSON response for lot details.
     *
     * @param string $jsonResponse The JSON string.
     * @return array|null Parsed data array or null if data is not found.
     */
    private function tryParseCopartJson(string $jsonResponse): ?array
    {
        $json = json_decode($jsonResponse, true);
        if (isset($json['data']['lotDetails'])) {
            return [
                'year' => $json['data']['lotDetails']['lcy'] ?? null,
                'location' => $json['data']['lotDetails']['yn'] ?? null,
                'branchSeller' => $json['data']['lotDetails']['scn'] ?? null,
                'engine' => $json['data']['lotDetails']['egn'] ?? null,
                'fuel' => $json['data']['lotDetails']['ft'] ?? null,
                'error' => 0,
                'url' => $this->incomeUrl,
            ];
        }
        return null;
    }

    /**
     * Provides standard headers for Copart requests.
     *
     * @return array
     */
    private function getCopartHeaders(): array
    {
        return [
            'authority: www.copart.com',
            'pragma: no-cache',
            'cache-control: no-cache',
            'upgrade-insecure-requests: 1',
            'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/86.0.4240.111 Safari/537.36',
            'accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
            'sec-fetch-site: none',
            'sec-fetch-mode: navigate',
            'sec-fetch-user: ?1',
            'sec-fetch-dest: document',
            'accept-language: en,ru;q=0.9,uk;q=0.8',
        ];
    }


    /**
     * Handles parsing logic for IAAI URLs.
     *
     * @return string JSON encoded result or error.
     */
    private function handleIaai(): string
    {
        // Execute Node.js script to get cookies
        $this->currentCookiesFromNodeJs = $this->getNodeJsCookies(self::NODEJS_COOKIE_SCRIPT_IAAI);
        if (empty($this->currentCookiesFromNodeJs)) {
            $this->log('iaai_bad.txt', ['error_desc' => 'Failed to get cookies from Node.js for IAAI', 'url' => $this->incomeUrl]);
            return json_encode([
                'error' => 1,
                'error_desc' => 'Failed to obtain cookies from Node.js for IAAI.',
                'url' => $this->incomeUrl,
            ]);
        }

        $htmlResponse = $this->sendHttpRequest(
            $this->incomeUrl,
            "GET",
            $this->getIaaiHeaders()
        );

        // Check for "Lot is not exist" scenario
        if (str_contains($htmlResponse, 'Object moved to')) {
            $this->log('iaai_bad.txt', ['error_desc' => 'Lot is not exist', 'url' => $this->incomeUrl]);
            return json_encode([
                'error' => 1,
                'error_desc' => 'Lot is not exist',
                'url' => $this->incomeUrl,
            ]);
        }

        $extractedData = $this->extractIaaiData($htmlResponse);

        if ($extractedData !== null) {
            $this->log('iaai_good.txt', ['result' => $extractedData]);
            return json_encode($extractedData);
        }

        $this->log('iaai_dont_see.txt', [
            'url' => $this->incomeUrl,
            'html' => $htmlResponse,
            'error_desc' => 'Could not extract all data from IAAI HTML',
        ]);
        return json_encode([
            'error' => 1,
            'error_desc' => 'Could not extract all data from IAAI HTML',
            'url' => $this->incomeUrl,
        ]);
    }

    /**
     * Extracts vehicle data from IAAI HTML response using regex.
     *
     * @param string $htmlResponse The HTML content from IAAI.
     * @return array|null Extracted data or null if not all fields are found.
     */
    private function extractIaaiData(string $htmlResponse): ?array
    {
        $data = [];
        if (preg_match('/"heading-2">(\d+)/', $htmlResponse, $m)) {
            $data['year'] = trim($m[1]);
        }
        if (preg_match('/Vehicle Location:<\/span>\s+<div\sclass="data-list__value">\s+<span>([^<]{5,45})</m', $htmlResponse, $m)) {
            $data['location'] = trim($m[1]);
        }
        if (preg_match('/Selling Branch:<\/span>\s+<span class="data-list__value">([^<]{5,45})<\/span>/m', $htmlResponse, $m)) {
            $data['branchSeller'] = trim($m[1]);
        }
        if (preg_match('/>([^<]+)<\/span>\s+<\/li>\s+<li class="data-list__item">\s+<span class="data-list__label">Transmission/m', $htmlResponse, $m)) {
            $data['engine'] = trim($m[1]);
        }
        if (preg_match('/Fuel Type:<\/span>\s+<span class="data-list__value">\s+([^<]+)/m', $htmlResponse, $m)) {
            $data['fuel'] = trim($m[1]);
        }

        // Check if all expected fields are present
        $requiredFields = ['year', 'location', 'branchSeller', 'engine', 'fuel'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                return null; // Not all data found
            }
        }

        $data['error'] = 0;
        $data['url'] = $this->incomeUrl;
        return $data;
    }

    /**
     * Provides standard headers for IAAI requests.
     *
     * @return array
     */
    private function getIaaiHeaders(): array
    {
        return [
            'Connection: keep-alive',
            'Pragma: no-cache',
            'Cache-Control: no-cache',
            'Upgrade-Insecure-Requests: 1',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/86.0.4240.111 Safari/537.36',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
            'Sec-Fetch-Site: same-origin',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-User: ?1',
            'Sec-Fetch-Dest: document',
            'Referer: https://www.iaai.com/VehicleSearch/SearchDetails?keyword=',
            'Accept-Language: en,ru;q=0.9,uk;q=0.8',
            'cookie: ' . $this->currentCookiesFromNodeJs, // Injected cookies from Node.js
        ];
    }


    /**
     * Sends an HTTP request using cURL.
     *
     * @param string $url The URL to request.
     * @param string $method The HTTP method (GET, POST).
     * @param array $headers An array of HTTP headers.
     * @param bool $useProxy Whether to use a proxy (hardcoded).
     * @param string $postFields The POST data string.
     * @return string The server response.
     */
    private function sendHttpRequest(
        string $url,
        string $method,
        array $headers,
        bool $useProxy = false,
        string $postFields = ''
    ): string {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        if ($method === "POST") {
            if ($this->curlDebug) {
                echo "*************** POST Request ***************\n";
            }
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        }

        // Hardcoded proxy - consider making configurable or dynamic
        if ($useProxy) {
            $proxy = "83.149.70.159:13012";
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Keep as true for security
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->fileCookiesPath);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->fileCookiesPath);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Debugging output for cURL
        if ($this->curlDebug) {
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        }

        $serverOutput = curl_exec($ch);

        if ($this->curlDebug) {
            echo "<textarea>";
            echo "\r\n\r\n************************* Curl $url Response: *************************\n" . $serverOutput;
            echo "</textarea>";
            echo "\r\n ************************ cURL Info for " . $url . " ************************\n\n";
            print_r(curl_getinfo($ch));
        }

        curl_close($ch);
        return (string)$serverOutput;
    }


    /**
     * Writes messages to a log file.
     *
     * @param string $fileName The name of the log file.
     * @param mixed $message The message to log (can be array, string, etc.).
     */
    private function log(string $fileName, mixed $message): void
    {
        $logDir = __DIR__ . "/logs/";
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        $fd = @fopen($logDir . $fileName, "a");
        if ($fd) {
            @fwrite($fd, date("Y-m-d H:i:s") . " -- " . print_r($message, true) . "\n");
            @fclose($fd);
        }
    }


    /**
     * Parses cookies from the cookie file into a string suitable for HTTP headers.
     *
     * @return string Formatted cookie string.
     */
    private function parseCookiesFromFile(): string
    {
        $this->currentCookiesFileContent = @file_get_contents($this->fileCookiesPath);
        $cookieString = '';
        // Regex to match "KEY VALUE" pairs, allowing for various characters
        preg_match_all('~([0-9a-zA-Z._-]+)\s+([0-9a-zA-Z=/\-_+.:,]+)$~im', $this->currentCookiesFileContent, $matches);

        if (!empty($matches[1])) {
            foreach ($matches[1] as $key => $name) {
                $value = $matches[2][$key];
                // Only include cookies with a reasonable length, heuristic to filter irrelevant ones
                if (strlen($value) >= self::MIN_COOKIE_VALUE_LENGTH || str_contains(strtolower($name), 'session')) {
                     $cookieString .= $name . '=' . $value . '; ';
                }
            }
        }
        return trim($cookieString, ' ;');
    }

    /**
     * Executes a Node.js script to get cookies for IAAI (generic cookie getter).
     *
     * @param string $scriptName The name of the Node.js script to execute.
     * @return string Formatted cookie string.
     */
    private function getNodeJsCookies(string $scriptName): string
    {
        $nodeJsCommand = '';
        $scriptPath = __DIR__ . '/' . $scriptName;

        if ($_SERVER['HTTP_HOST'] === 'car-parse.loc') {
            $nodeJsCommand = '"\Program Files\nodejs\node.exe" ' . $scriptPath;
        } else {
            $nodeJsCommand = 'node ' . $scriptPath;
        }

        $output = [];
        $returnVar = 0;
        // Escape the URL if it contains spaces or special characters for shell execution
        $escapedUrl = escapeshellarg($this->incomeUrl);

        // Execute the Node.js script to get cookies
        // The script is expected to output a JSON string of cookies
        exec("cd " . __DIR__ . " && " . $nodeJsCommand . " " . $escapedUrl . " 2>&1", $output, $returnVar);

        $this->log('nodejs_iaai_debug.txt', ['command' => "cd " . __DIR__ . " && " . $nodeJsCommand . " " . $escapedUrl, 'output' => $output, 'return_var' => $returnVar]);

        if ($returnVar !== 0 || empty($output[0])) {
            return ''; // Node.js script failed or returned no output
        }

        $cookiesData = json_decode($output[0], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($cookiesData)) {
            $this->log('nodejs_iaai_error.txt', ['error' => 'Node.js output is not valid JSON for cookies.', 'output' => $output]);
            return ''; // Invalid JSON
        }

        $cookieString = '';
        foreach ($cookiesData as $item) {
            if (isset($item['name']) && isset($item['value'])) {
                $cookieString .= $item['name'] . '=' . $item['value'] . "; ";
            }
        }
        return trim($cookieString, ' ;');
    }


    /**
     * Executes a Node.js script to get cookies AND/OR data for Copart.
     *
     * @return array An associative array containing 'cookies' (string) and 'data' (string).
     */
    private function nodeJsRequestGetCookiesAndData(): array
    {
        $nodeJsCommand = '';
        $scriptPath = __DIR__ . '/' . self::NODEJS_COOKIE_SCRIPT_COPART;

        if ($_SERVER['HTTP_HOST'] === 'car-parse.loc') {
            $nodeJsCommand = '"\Program Files\nodejs\node.exe" ' . $scriptPath;
        } else {
            $nodeJsCommand = 'node ' . $scriptPath;
        }

        // Escape arguments for shell execution
        $escapedLotId = escapeshellarg($this->currentLotId);
        $escapedCookies = escapeshellarg($this->parseCookiesFromFile());

        $command = "cd " . __DIR__ . " && " . $nodeJsCommand . " " . $escapedLotId . " " . $escapedCookies . " 2>&1";

        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);

        $this->log('nodejs_copart_debug.txt', ['command' => $command, 'output' => $output, 'return_var' => $returnVar]);

        $rawResponse = '';
        // Node.js Puppeteer might output deprecation warnings on $output[0]
        // Assuming the actual JSON data/cookies are in $output[1] based on original code's $out[1] usage
        if (isset($output[1])) {
            $rawResponse = $output[1];
        } elseif (isset($output[0])) {
            // Fallback if there's no deprecation warning and data is in $output[0]
            $rawResponse = $output[0];
        }

        $decodedResponse = json_decode($rawResponse, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decodedResponse)) {
            $this->log('nodejs_copart_error.txt', ['error' => 'Node.js output is not valid JSON.', 'raw_output' => $rawResponse, 'output_array' => $output, 'return_var' => $returnVar]);
            return ['cookies' => '', 'data' => '']; // Return empty on error
        }

        $extractedCookies = '';
        if (isset($decodedResponse['cookies']) && is_array($decodedResponse['cookies'])) {
            foreach ($decodedResponse['cookies'] as $item) {
                if (isset($item['name']) && isset($item['value'])) {
                    $extractedCookies .= $item['name'] . '=' . $item['value'] . "; ";
                }
            }
        }

        $extractedData = $decodedResponse['data'] ?? '';

        // The original code uses a regex to extract content between >{ and }<
        // This suggests the 'data' field might be an HTML string containing JSON
        // If the Node.js script can be made to return pure JSON for data, that would be better.
        // For now, retaining the original regex logic for data extraction from response
        preg_match('~>{(.*)}<~', $extractedData, $d);
        $finalData = $d[1] ?? '{}'; // Default to empty JSON object if not found

        return [
            'cookies' => trim($extractedCookies, ' ;'),
            'data' => '{' . $finalData . '}', // Re-wrap in braces if it was stripped
        ];
    }
}