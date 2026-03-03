<?php
/**
 * lib/TBank.php — T-Bank (Tinkoff) Acquiring API helpers
 *
 * tBankToken()   — SHA-256 token for request signing
 * tBankRequest() — cURL POST to securepay.tinkoff.ru/v2/{method}
 */

/**
 * Calculate T-Bank request token (SHA-256 of sorted concatenated values).
 * See: https://www.tbank.ru/kassa/dev/payments/#tag/Oplata/Token
 *
 * @param array  $params   Request parameters (without Token)
 * @param string $password Terminal password
 * @return string SHA-256 hex hash
 */
function tBankToken(array $params, string $password): string
{
    $params['Password'] = $password;
    // Exclude non-scalar / complex fields from hash calculation
    unset($params['Token'], $params['Receipt'], $params['DATA'], $params['Shops']);
    ksort($params);
    return hash('sha256', implode('', array_values($params)));
}

/**
 * Send request to T-Bank Acquiring API.
 *
 * @param string $method   API method name (e.g. 'Init', 'GetState')
 * @param array  $params   Request body parameters (without Token — added automatically)
 * @param string $password Terminal password for token calculation
 * @return array|null      Decoded JSON response or null on network error
 */
function tBankRequest(string $method, array $params, string $password): ?array
{
    $params['Token'] = tBankToken($params, $password);
    $body = json_encode($params, JSON_UNESCAPED_UNICODE);

    $url = 'https://securepay.tinkoff.ru/v2/' . rawurlencode($method);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $result = curl_exec($ch);
    $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($err) {
        error_log("TBank cURL error [{$method}]: {$err}");
        return null;
    }
    if (!$result) {
        error_log("TBank empty response [{$method}]: HTTP {$code}");
        return null;
    }
    $decoded = json_decode($result, true);
    if (!is_array($decoded)) {
        error_log("TBank invalid JSON [{$method}]: {$result}");
        return null;
    }
    return $decoded;
}
