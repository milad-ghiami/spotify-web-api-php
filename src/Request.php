<?php
namespace SpotifyWebAPI;

class Request
{
    const ACCOUNT_URL = 'https://accounts.spotify.com';
    const API_URL = 'https://api.spotify.com';

    protected $lastResponse = [];
    protected $options = [
        'curl_options' => [],
        'return_assoc' => false,
    ];

    /**
     * Constructor
     * Set options.
     *
     * @param array|object $options Optional. Options to set.
     */
    public function __construct($options = [])
    {
        $this->setOptions($options);
    }

    /**
     * Parse the response body and handle API errors.
     *
     * @param string $body The raw, unparsed response body.
     * @param int $status The HTTP status code, used to see if additional error handling is needed.
     *
     * @throws SpotifyWebAPIException
     * @throws SpotifyWebAPIAuthException
     *
     * @return array|object The parsed response body. Type is controlled by the `return_assoc` option.
     */
    protected function parseBody($body, $status)
    {
        $this->lastResponse['body'] = json_decode($body, $this->options['return_assoc']);

        if ($status >= 200 && $status <= 299) {
            return $this->lastResponse['body'];
        }

        $body = json_decode($body);
        $error = $body->error ?? null;

        if (isset($error->message) && isset($error->status)) {
            // API call error
            $exception = new SpotifyWebAPIException($error->message, $error->status);

            if (isset($error->reason)) {
                $exception->setReason($error->reason);
            }

            throw $exception;
        } elseif (isset($body->error_description)) {
            // Auth call error
            throw new SpotifyWebAPIAuthException($body->error_description, $status);
        } else {
            // Something went really wrong
            throw new SpotifyWebAPIException('An unknown error occurred.', $status);
        }
    }

    /**
     * Parse HTTP response headers.
     *
     * @param string $headers The raw, unparsed response headers.
     *
     * @return array Headers as key–value pairs.
     */
    protected function parseHeaders($headers)
    {
        $headers = str_replace("\r\n", "\n", $headers);
        $headers = explode("\n", $headers);

        array_shift($headers);

        $parsedHeaders = [];
        foreach ($headers as $header) {
            list($key, $value) = explode(':', $header, 2);

            $parsedHeaders[$key] = trim($value);
        }

        return $parsedHeaders;
    }

    /**
     * Make a request to the "account" endpoint.
     *
     * @param string $method The HTTP method to use.
     * @param string $uri The URI to request.
     * @param array $parameters Optional. Query string parameters or HTTP body, depending on $method.
     * @param array $headers Optional. HTTP headers.
     *
     * @throws SpotifyWebAPIException
     * @throws SpotifyWebAPIAuthException
     *
     * @return array Response data.
     * - array|object body The response body. Type is controlled by the `return_assoc` option.
     * - array headers Response headers.
     * - int status HTTP status code.
     * - string url The requested URL.
     */
    public function account($method, $uri, $parameters = [], $headers = [])
    {
        return $this->send($method, self::ACCOUNT_URL . $uri, $parameters, $headers);
    }

    /**
     * Make a request to the "api" endpoint.
     *
     * @param string $method The HTTP method to use.
     * @param string $uri The URI to request.
     * @param array $parameters Optional. Query string parameters or HTTP body, depending on $method.
     * @param array $headers Optional. HTTP headers.
     *
     * @throws SpotifyWebAPIException
     * @throws SpotifyWebAPIAuthException
     *
     * @return array Response data.
     * - array|object body The response body. Type is controlled by the `return_assoc` option.
     * - array headers Response headers.
     * - int status HTTP status code.
     * - string url The requested URL.
     */
    public function api($method, $uri, $parameters = [], $headers = [])
    {
        return $this->send($method, self::API_URL . $uri, $parameters, $headers);
    }

    /**
     * Get the latest full response from the Spotify API.
     *
     * @return array Response data.
     * - array|object body The response body. Type is controlled by the `return_assoc` option.
     * - array headers Response headers.
     * - int status HTTP status code.
     * - string url The requested URL.
     */
    public function getLastResponse()
    {
        return $this->lastResponse;
    }

    /**
     * Make a request to Spotify.
     * You'll probably want to use one of the convenience methods instead.
     *
     * @param string $method The HTTP method to use.
     * @param string $url The URL to request.
     * @param array $parameters Optional. Query string parameters or HTTP body, depending on $method.
     * @param array $headers Optional. HTTP headers.
     *
     * @throws SpotifyWebAPIException
     * @throws SpotifyWebAPIAuthException
     *
     * @return array Response data.
     * - array|object body The response body. Type is controlled by the `return_assoc` option.
     * - array headers Response headers.
     * - int status HTTP status code.
     * - string url The requested URL.
     */
    public function send($method, $url, $parameters = [], $headers = [])
    {
        // Reset any old responses
        $this->lastResponse = [];

        // Sometimes a stringified JSON object is passed
        if (is_array($parameters) || is_object($parameters)) {
            $parameters = http_build_query($parameters, null, '&');
        }

        $mergedHeaders = [];
        foreach ($headers as $key => $val) {
            $mergedHeaders[] = "$key: $val";
        }

        $options = [
            CURLOPT_CAINFO => __DIR__ . '/cacert.pem',
            CURLOPT_ENCODING => '',
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $mergedHeaders,
            CURLOPT_RETURNTRANSFER => true,
        ];

        $url = rtrim($url, '/');
        $method = strtoupper($method);

        switch ($method) {
            case 'DELETE': // No break
            case 'PUT':
                $options[CURLOPT_CUSTOMREQUEST] = $method;
                $options[CURLOPT_POSTFIELDS] = $parameters;

                break;
            case 'POST':
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = $parameters;

                break;
            default:
                $options[CURLOPT_CUSTOMREQUEST] = $method;

                if ($parameters) {
                    $url .= '/?' . $parameters;
                }

                break;
        }

        $options[CURLOPT_URL] = $url;

        $ch = curl_init();

        curl_setopt_array($ch, array_replace($options, $this->options['curl_options']));

        $response = curl_exec($ch);

        if (curl_error($ch)) {
            throw new SpotifyWebAPIException('cURL transport error: ' . curl_errno($ch) . ' ' .  curl_error($ch));
        }

        list($headers, $body) = explode("\r\n\r\n", $response, 2);

        // Skip the first set of headers for proxied requests
        if (preg_match('/^HTTP\/1\.\d 200 Connection established$/', $headers) === 1) {
            list($headers, $body) = explode("\r\n\r\n", $body, 2);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headers = $this->parseHeaders($headers);

        $this->lastResponse = [
            'headers' => $headers,
            'status' => $status,
            'url' => $url,
        ];

        // Run this separately since it might throw
        $this->parseBody($body, $status);

        curl_close($ch);

        return $this->lastResponse;
    }

    /**
     * Set options
     *
     * @param array|object $options Options to set.
     *
     * @return void
     */
    public function setOptions($options)
    {
        $this->options = array_merge($this->options, (array) $options);
    }
}
