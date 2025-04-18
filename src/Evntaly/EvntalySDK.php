<?php

namespace Evntaly;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class EvntalySDK
{
    /**
     * Base URL for Evntaly API
     */
    private const BASE_URL = "https://app.evntaly.com";

    /**
     * @var string The developer secret for authenticating API requests
     */
    private $developerSecret;

    /**
     * @var string The project token for identifying the project
     */
    private $projectToken;

    /**
     * @var bool Flag to enable or disable event tracking
     */
    private $trackingEnabled = true;

    /**
     * @var Client Guzzle HTTP client instance
     */
    private $client;

    /**
     * Initialize the SDK with a developer secret and project token.
     *
     * @param string $developerSecret The secret key provided by Evntaly
     * @param string $projectToken The token identifying your Evntaly project
     */
    public function __construct(string $developerSecret, string $projectToken)
    {
        $this->developerSecret = $developerSecret;
        $this->projectToken = $projectToken;
        $this->client = new Client([
            'base_uri' => self::BASE_URL,
            'http_errors' => true,
            'timeout' => 10,
        ]);
    }

    /**
     * Check if the API usage limit allows further tracking.
     *
     * @return array|false Response data if successful, false if limit is reached or an error occurs
     */
    public function checkLimit() : array
    {
        $url = "/prod/api/v1/account/check-limits/{$this->developerSecret}";
        $headers = [
            'Content-Type' => 'application/json',
        ];

        try {
            $response = $this->client->get($url, [
                'headers' => $headers
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['limitReached'])) {
                return [
                    'success' => false,
                    'error' => 'Unexpected response format',
                    'response' => $data
                ];
            }

            return [
                'success' => true,
                'limitReached' => $data['limitReached'],
                'response' => $data
            ];
        } catch (Exception | GuzzleException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Track an event by sending it to the Evntaly API.
     *
     * @param array $eventData Associative array of event details (e.g., title, description, sessionID)
     * @return array Response data
     */
    public function track(array $eventData): array
    {
        if (!$this->trackingEnabled) {
            return [
                'success' => false,
                'error' => 'Tracking is disabled'
            ];
        }

        $limitCheck = $this->checkLimit();
        if ($limitCheck['success'] === false) {
            return [
                'success' => false,
                'error' => 'Failed to check usage limits',
                'details' => $limitCheck
            ];
        }

        if ($limitCheck['limitReached']) {
            return [
                'success' => false,
                'error' => 'Usage limit reached'
            ];
        }

        $url = "/prod/api/v1/register/event";
        $headers = [
            'Content-Type' => 'application/json',
            'secret' => $this->developerSecret,
            'pat' => $this->projectToken,
        ];

        try {
            $response = $this->client->post($url, [
                'headers' => $headers,
                'json' => $eventData
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);
            return [
                'success' => true,
                'data' => $responseData
            ];
        } catch (Exception | GuzzleException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Identify a user in the Evntaly system.
     *
     * @param array $userData Associative array of user details (e.g., id, email, full_name)
     * @return array Response data
     */
    public function identifyUser(array $userData): array
    {
        $url = "/prod/api/v1/register/user";
        $headers = [
            'Content-Type' => 'application/json',
            'secret' => $this->developerSecret,
            'pat' => $this->projectToken,
        ];

        try {
            $response = $this->client->post($url, [
                'headers' => $headers,
                'json' => $userData
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);
            return [
                'success' => true,
                'data' => $responseData
            ];
        } catch (Exception | GuzzleException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Disable event tracking.
     *
     * @return array Status information
     */
    public function disableTracking(): array
    {
        $this->trackingEnabled = false;
        return [
            'success' => true,
            'message' => 'Tracking disabled'
        ];
    }

    /**
     * Enable event tracking.
     *
     * @return array Status information
     */
    public function enableTracking(): array
    {
        $this->trackingEnabled = true;
        return [
            'success' => true,
            'message' => 'Tracking enabled'
        ];
    }
}
