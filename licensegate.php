<?php

/**
MIT License

Copyright (c) 2018-2019 Stepan Fedotov <stepan@crident.com>

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
**/

if(!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use Illuminate\Database\Capsule\Manager as Capsule;

function licensegate_GetHostname(array $params) {
    $hostname = $params['serverhostname'];
    if ($hostname === '') throw new Exception('Could not find the panel\'s hostname - did you configure server group for the product?');

    // For whatever reason, WHMCS converts some characters of the hostname to their literal meanings (- => dash, etc) in some cases
    foreach([
        'DOT' => '.',
        'DASH' => '-',
    ] as $from => $to) {
        $hostname = str_replace($from, $to, $hostname);
    }

    if(ip2long($hostname) !== false) $hostname = 'http://' . $hostname;
    else $hostname = ($params['serversecure'] ? 'https://' : 'http://') . $hostname;

    return rtrim($hostname, '/');
}

function licensegate_API(array $params, $endpoint, array $data = [], $method = "GET", $dontLog = false) {
    $url = licensegate_GetHostname($params) . '/admin/licenses' . $endpoint;

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($curl, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
    curl_setopt($curl, CURLOPT_USERAGENT, "License-Gate");
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_POSTREDIR, CURL_REDIR_POST_301);
    curl_setopt($curl, CURLOPT_TIMEOUT, 5);

    $headers = [
        "Authorization: " . $params['serverpassword'],
    ];

    if($method === 'POST' || $method === 'PATCH') {
        $jsonData = json_encode($data);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $jsonData);
        array_push($headers, "Content-Type: application/json");
    }

    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($curl);
    $responseData = json_decode($response, true);
    $responseData['status_code'] = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    
    if($responseData['status_code'] === 0 && !$dontLog) logModuleCall("License-Gate", "CURL ERROR", curl_error($curl), "");

    curl_close($curl);

    if(!$dontLog) logModuleCall("License-Gate", $method . " - " . $url,
        isset($data) ? json_encode($data) : "",
        print_r($responseData, true));

    return $responseData;
}

function licensegate_Error($func, $params, Exception $err) {
    logModuleCall("License-Gate", $func, $params, $err->getMessage(), $err->getTraceAsString());
}

function licensegate_MetaData() {
    return [
        "DisplayName" => "License-Gate",
        "APIVersion" => "1.1",
        "RequiresServer" => true,
    ];
}

function licensegate_ConfigOptions() {
    return [
        "notes" => [
            "FriendlyName" => "License Notes",
            "Description" => "",
            "Type" => "text",
            "Size" => 10,
        ],
        "vlimit" => [
            "FriendlyName" => "Max Tokens",
            "Description" => "",
            "Type" => "text",
            "Size" => 10,
        ],
        "limit" => [
            "FriendlyName" => "IP Limit",
            "Description" => "",
            "Type" => "text",
            "Default" => "1",
            "Size" => 10,
        ],
        "vtokens" => [
            "FriendlyName" => "Validation Tokens",
            "Description" => "",
            "Type" => "text",
            "Size" => 10,
        ],
        "scope" => [
            "FriendlyName" => "License Scope",
            "Description" => "",
            "Type" => "text",
            "Size" => 10,
        ],
        "rinterval" => [
            "FriendlyName" => "Replenish Interval",
            "Description" => "",
            "Type" => "dropdown",
            "Options" => [
                "TEN_SECONDS" => "10 Seconds",
                "MINUTE" => "1 Minute",
                "HOUR" => "Hourly",
                "DAY" => "Daily",
            ],
        ],
    ];
}

function licensegate_GetOption(array $params, $id, $default = NULL) {
    $options = licensegate_ConfigOptions();

    $friendlyName = $options[$id]['FriendlyName'];
    if(isset($params['configoptions'][$friendlyName]) && $params['configoptions'][$friendlyName] !== '') {
        return $params['configoptions'][$friendlyName];
    } else if(isset($params['configoptions'][$id]) && $params['configoptions'][$id] !== '') {
        return $params['configoptions'][$id];
    } else if(isset($params['customfields'][$friendlyName]) && $params['customfields'][$friendlyName] !== '') {
        return $params['customfields'][$friendlyName];
    } else if(isset($params['customfields'][$id]) && $params['customfields'][$id] !== '') {
        return $params['customfields'][$id];
    }

    $found = false;
    $i = 0;
    foreach(licensegate_ConfigOptions() as $key => $value) {
        $i++;
        if($key === $id) {
            $found = true;
            break;
        }
    }

    if($found && isset($params['configoption' . $i]) && $params['configoption' . $i] !== '') {
        return $params['configoption' . $i];
    }

    return $default;
}

function licensegate_TestConnection(array $params) {
    $solutions = [
        0 => "Check module debug log for more detailed error.",
        401 => "Authorization header either missing or not provided.",
        403 => "Double check the password (which should be the API Key).",
        404 => "Result not found.",
        422 => "Validation error.",
        500 => "Panel errored, check panel logs.",
    ];

    $err = "";
    try {
        $response = licensegate_API($params, '/key/WHMCS', [], 'GET');

        if($response['status_code'] !== 200) {
            $status_code = $response['status_code'];
            $err = "Invalid status_code received: " . $status_code . ". Possible solutions: "
                . (isset($solutions[$status_code]) ? $solutions[$status_code] : "None.");
        } else {
            $err = "Authentication successful";
        }
    } catch(Exception $e) {
        licensegate_Error(__FUNCTION__, $params, $e);
        $err = $e->getMessage();
    }

    return [
        "success" => $err === "",
        "error" => $err,
    ];
}

function licensegate_GetKey($params, $licenseKey) {
    $target = '/key/' . $licenseKey;

    try {
        $response = licensegate_API($params, $target, [], 'GET');

        // Log raw response for debugging
        logModuleCall("License-Gate", "GetKey Raw Response", json_encode($response), "");

        // Ensure response is an array
        if (!is_array($response)) {
            $response = json_decode(json_encode($response), true); // Convert object to array
        }

        if (!isset($response['id'])) {
            return ["success" => false, "error" => "ID not found in response"];
        }

        return [
            "success" => true,
            "data" => $response,
        ];
    } catch (Exception $e) {
        logModuleCall("License-Gate", "GetKey Exception", $e->getMessage(), $e->getTraceAsString());
        return ["success" => false, "error" => $e->getMessage()];
    }
}


function licensegate_GenerateKey($inputString) {
    $licenseHashed = md5($inputString);
    $licenseObfuscated = substr($licenseHashed, 0, 12) 
                        . substr(strrev($licenseHashed), 10, 4) 
                        . strrev(substr($licenseHashed, 20, 12));
    $licenseFormatted = substr($licenseObfuscated, 0, 5) . '-' . 
                        substr($licenseObfuscated, 5, 5) . '-' . 
                        substr($licenseObfuscated, 10, 4) . '-' . 
                        substr($licenseObfuscated, 14, 7) . '-' . 
                        substr($licenseObfuscated, 21, 7);
    return strtoupper($licenseFormatted);
}

function licensegate_CreateAccount(array $params)
{
    try {
        $name = $params['clientsdetails']['firstname'] . ' ' . $params['clientsdetails']['lastname'];
        $active = true;
        $notes = licensegate_GetOption($params, 'notes', 'Created From WHMCS');
        $limit = licensegate_GetOption($params, 'limit');
        $scope = licensegate_GetOption($params, 'scope');
        $vtokens = licensegate_GetOption($params, 'vtokens', $limit * 3);
        $vlimit = licensegate_GetOption($params, 'vlimit', $vtokens * 3);
        $rinterval = licensegate_GetOption($params, 'rinterval', 'HOUR');
        $endpoint = "";

        $inputString = $params['serviceid'] . '-' . $params['username'];
        $licenseKey = licensegate_GenerateKey($inputString);

        $data = [
            "active" => $active,
            "name" => $name,
            "notes" => $notes,
            "ipLimit" => $limit,
            "licenseScope" => $scope,
            "expirationDate" => "9999-12-31T23:59:59",
            "validationPoints" => $vtokens,
            "validationLimit" => $vlimit,
            "replenishAmount" => $vtokens,
            "replenishInterval" => $rinterval,
            "licenseKey" => $licenseKey,
        ];

        $response = licensegate_API($params, $endpoint, $data, "POST");

        if ($response['status_code'] !== 201) {
            throw new Exception("Failed to execute command. Status code: {$response['status_code']}");
        }
    } catch (Exception $e) {
        return $e->getMessage();
    }

    return 'success';
}

function licensegate_SuspendAccount(array $params)
{
    try {
        // Generate key input
        $inputString = $params['serviceid'] . '-' . $params['username'];
        $keyResponse = licensegate_GetKey($params, licensegate_GenerateKey($inputString));

        // Validate API response
        $success = isset($keyResponse['success']) 
           && $keyResponse['success'] 
           && isset($keyResponse['data']['id']);

        if (!$success) {
            throw new Exception("Failed to check account. Response: " . json_encode($keyResponse));
        }


        // Construct endpoint
        $endpoint = '/' . $keyResponse['data']['id'];
        $name = $keyResponse['data']['name'];
        $notes = $keyResponse['data']['notes'];
        $limit = $keyResponse['data']['ipLimit'];
        $scope = $keyResponse['data']['licenseScope'];
        $vtokens = $keyResponse['data']['validationPoints'];
        $vlimit = $keyResponse['data']['validationLimit'];
        $rinterval = $keyResponse['data']['replenishInterval'];
        $licenseKey = $keyResponse['data']['licenseKey'];

        // Data payload
        $dataPayload = [
            "licenseKey" => $licenseKey,
            "active" => false,
            "name" => $name,
            "notes" => $notes,
            "ipLimit" => $limit,
            "licenseScope" => $scope,
            "expirationDate" => "9999-12-31T23:59:59",
            "validationPoints" => $vtokens,
            "validationLimit" => $vlimit,
            "replenishAmount" => $vtokens,
            "replenishInterval" => $rinterval,
        ];

        // Make API request
        $response = licensegate_API($params, $endpoint, $dataPayload, "PATCH");

        // Validate API response
        if (!isset($response['status_code']) || $response['status_code'] !== 200) {
            throw new Exception("Failed to suspend account. Response: " . json_encode($response));
        }

    } catch (Exception $e) {
        return "Error: " . $e->getMessage();
    }

    return "success";
}


function licensegate_UnsuspendAccount(array $params)
{
    try {
        // Generate key input
        $inputString = $params['serviceid'] . '-' . $params['username'];
        $keyResponse = licensegate_GetKey($params, licensegate_GenerateKey($inputString));

        // Validate API response
        $success = isset($keyResponse['success']) 
            && $keyResponse['success'] 
            && isset($keyResponse['data']['id']);

        if (!$success) {
            throw new Exception("Failed to check account. Response: " . json_encode($keyResponse));
        }

        // Construct endpoint
        $endpoint = '/' . $keyResponse['data']['id'];
        $name = $keyResponse['data']['name'];
        $notes = $keyResponse['data']['notes'];
        $limit = $keyResponse['data']['ipLimit'];
        $scope = $keyResponse['data']['licenseScope'];
        $vtokens = $keyResponse['data']['validationPoints'];
        $vlimit = $keyResponse['data']['validationLimit'];
        $rinterval = $keyResponse['data']['replenishInterval'];
        $licenseKey = $keyResponse['data']['licenseKey'];

        // Data payload
        $dataPayload = [
            "licenseKey" => $licenseKey,
            "active" => true,
            "name" => $name,
            "notes" => $notes,
            "ipLimit" => $limit,
            "licenseScope" => $scope,
            "expirationDate" => "9999-12-31T23:59:59",
            "validationPoints" => $vtokens,
            "validationLimit" => $vlimit,
            "replenishAmount" => $vtokens,
            "replenishInterval" => $rinterval,
        ];

        // Make API request
        $response = licensegate_API($params, $endpoint, $dataPayload, "PATCH");

        // Validate API response
        if (!isset($response['status_code']) || $response['status_code'] !== 200) {
            throw new Exception("Failed to suspend account. Response: " . json_encode($response));
        }

    } catch (Exception $e) {
        return "Error: " . $e->getMessage();
    }

    return "success";
}

function licensegate_TerminateAccount(array $params)
{
    try {
        $inputString = $params['serviceid'] . '-' . $params['username'];
        $keyResponse = licensegate_GetKey($params, licensegate_GenerateKey($inputString));

        if ($keyResponse['success']) {
            $endpoint = '/' . $keyResponse['data']['id'];
        } else {
            throw new Exception("Failed to check account. Status code: {$response['status_code']}");
        }

        $response = licensegate_API($params, $endpoint, [], "DELETE");

        if ($response['status_code'] !== 200) {
            throw new Exception("Failed to terminate account. Status code: {$response['status_code']}");
        }
    } catch (Exception $e) {
        return $e->getMessage();
    }

    return 'success';
}

function licensegate_ChangePassword(array $params)
{
    try {
        if($params['password'] === '') throw new Exception('The password cannot be empty.');
    } catch (Exception $e) {
        return $e->getMessage();
    }

    return 'success';
}

function licensegate_ChangePackage(array $params)
{
    try {
        $active = true;
        $notes = licensegate_GetOption($params, 'notes', 'Created From WHMCS');
        $limit = licensegate_GetOption($params, 'limit');
        $scope = licensegate_GetOption($params, 'scope');
        $vtokens = licensegate_GetOption($params, 'vtokens', $limit * 3);
        $vlimit = licensegate_GetOption($params, 'vlimit', $vtokens * 3);
        $rinterval = licensegate_GetOption($params, 'rinterval', 'HOUR');

        $inputString = $params['serviceid'] . '-' . $params['username'];
        $keyResponse = licensegate_GetKey($params, licensegate_GenerateKey($inputString));

        if ($keyResponse['success']) {
            $endpoint = '/' . $keyResponse['data']['id'];
        } else {
            throw new Exception("Failed to check account. Status code: {$response['status_code']}");
        }
        $licenseKey = $keyResponse['data']['licenseKey'];

        $dataPayload = [
            "licenseKey" => $licenseKey,
            "active" => $active,
            "notes" => $notes,
            "ipLimit" => $limit,
            "licenseScope" => $scope,
            "expirationDate" => "9999-12-31T23:59:59",
            "validationPoints" => $vtokens,
            "validationLimit" => $vlimit,
            "replenishAmount" => $vtokens,
            "replenishInterval" => $rinterval,
        ];

        $response = licensegate_API($params, $endpoint, $dataPayload, "PATCH");

        if ($response['status_code'] !== 200) {
            throw new Exception("Failed to update account. Status code: {$response['status_code']}");
        }
    } catch (Exception $e) {
        return $e->getMessage();
    }

    return 'success';
}

function licensegate_Renew(array $params)
{
    try {
        $inputString = $params['serviceid'] . '-' . $params['username'];
        $keyResponse = licensegate_GetKey($params, licensegate_GenerateKey($inputString));
        if ($keyResponse['success']) {
            $endpoint = '/' . $keyResponse['data']['id'];
        } else {
            throw new Exception("Failed to check account. Status code: {$response['status_code']}");
        }

        $checker = licensegate_API($params, $endpoint, [], 'GET');

        if($checker['status_code'] == 200) {
            $active = true;
            $notes = licensegate_GetOption($params, 'notes', 'Created From WHMCS');
            $limit = licensegate_GetOption($params, 'limit');
            $scope = licensegate_GetOption($params, 'scope');
            $vtokens = licensegate_GetOption($params, 'vtokens', $limit * 3);
            $vlimit = licensegate_GetOption($params, 'vlimit', $vtokens * 3);
            $rinterval = licensegate_GetOption($params, 'rinterval', 'HOUR');

            $inputString = $params['serviceid'] . '-' . $params['username'];
            $endpoint = licensegate_GenerateKey($inputString);

            $dataPayload = [
                "licenseKey" => $licenseKey,
                "active" => $active,
                "notes" => $notes,
                "ipLimit" => $limit,
                "licenseScope" => $scope,
                "expirationDate" => "9999-12-31T23:59:59",
                "validationPoints" => $vtokens,
                "validationLimit" => $vlimit,
                "replenishAmount" => $vtokens,
                "replenishInterval" => $rinterval,
            ];

            $response = licensegate_API($params, $endpoint, $dataPayload, "PATCH");

            if ($response['status_code'] !== 200) {
                throw new Exception("Failed to execute command. Status code: {$response['status_code']}");
            }
        } else {
            $name = $params['clientsdetails']['firstname'] . ' ' . $params['clientsdetails']['lastname'];
            $active = true;
            $notes = licensegate_GetOption($params, 'notes', 'Created From WHMCS');
            $limit = licensegate_GetOption($params, 'limit');
            $scope = licensegate_GetOption($params, 'scope');
            $vtokens = licensegate_GetOption($params, 'vtokens', $limit * 3);
            $vlimit = licensegate_GetOption($params, 'vlimit', $vtokens * 3);
            $rinterval = licensegate_GetOption($params, 'rinterval', 'HOUR');
            $endpoint = "";

            $inputString = $params['serviceid'] . '-' . $params['username'];
            $licenseKey = licensegate_GenerateKey($inputString);

            $dataPayload = [
                "active" => $active,
                "name" => $name,
                "notes" => $notes,
                "ipLimit" => $limit,
                "licenseScope" => $scope,
                "expirationDate" => "9999-12-31T23:59:59",
                "validationPoints" => $vtokens,
                "validationLimit" => $vlimit,
                "replenishAmount" => $vtokens,
                "replenishInterval" => $rinterval,
                "licenseKey" => $licenseKey,
            ];

            $response = licensegate_API($params, $endpoint, $dataPayload, "POST");

            if ($response['status_code'] !== 201) {
                throw new Exception("Failed to execute command. Status code: {$response['status_code']}");
            }
        }
    } catch (Exception $e) {
        return $e->getMessage();
    }

    return 'success';
}

function licensegate_ClientArea($params) {
    $inputString = $params['serviceid'] . '-' . $params['username'];
    $licenseKey = licensegate_GenerateKey($inputString);

    return array(
        'tabOverviewReplacementTemplate' => 'templates/clientarea.tpl',
        'templateVariables' => array(
            'licensesKey' => $licenseKey
        ),
    );
}
