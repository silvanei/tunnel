<?php

declare(strict_types=1);

namespace S3\Tunnel\Shared\GitHub;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

readonly class GitHubService
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client();
    }

    public function accessToken(string $authCode): ?string
    {
        try {
            $response = $this->client->post('https://github.com/login/oauth/access_token', [
                'form_params' => [
                    'client_id' => getenv('GITHUB_TOKEN'),
                    'client_secret' => getenv('GITHUB_TOKEN_SECRET'),
                    'code' => $authCode,
                ],
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);
        } catch (GuzzleException) {
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $token = (array)json_decode($response->getBody()->getContents(), true);
        if ($token['error']) {
            return null;
        }

        return $token['access_token'];
    }

    public function user(string $accessToken): ?User
    {
        try {
            $response = $this->client->get('https://api.github.com/user', [
                'headers' => [
                    'Authorization' => "Bearer $accessToken",
                    'Accept' => 'application/json',
                ]
            ]);
        } catch (GuzzleException) {
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        /** @var array{avatar_url: ?string, name: ?string} $body */
        $body = (array)json_decode($response->getBody()->getContents(), true);
        return new User(
            $body['avatar_url'] ?? '',
            $body['name'] ?? '',
            $accessToken
        );
    }

    public function validateToken(string $accessToken): bool
    {
        return $this->user($accessToken) !== null;
    }
}
