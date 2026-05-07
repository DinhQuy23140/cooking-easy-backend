<?php

namespace App\Services;

use Google\Auth\Credentials\ServiceAccountCredentials;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Arr;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\InvalidArgumentException;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use RuntimeException;
use Throwable;

class FirebaseService
{
    private Messaging $messaging;

    private ServiceAccountCredentials $credentials;

    private Client $httpClient;

    private string $projectId;

    public function __construct()
    {
        if (!class_exists(Factory::class)) {
            throw new RuntimeException(
                'Firebase SDK is not installed. Install kreait/firebase-php for this Laravel runtime.'
            );
        }

        $serviceAccountPath = config('services.firebase.credentials');
        $projectId = config('services.firebase.project_id');

        if (empty($serviceAccountPath) || empty($projectId)) {
            throw new RuntimeException(
                'Firebase is not configured. Set FIREBASE_PROJECT_ID and FIREBASE_CREDENTIALS in .env.'
            );
        }

        $resolvedCredentialsPath = $this->resolveCredentialsPath($serviceAccountPath);
        $this->projectId = $projectId;
        $this->httpClient = new Client([
            'base_uri' => 'https://firestore.googleapis.com',
            'timeout' => 20,
        ]);

        try {
            $serviceAccount = json_decode((string) file_get_contents($resolvedCredentialsPath), true);
            $this->credentials = new ServiceAccountCredentials(
                ['https://www.googleapis.com/auth/datastore'],
                $serviceAccount
            );

            $factory = (new Factory())
                ->withServiceAccount($resolvedCredentialsPath)
                ->withProjectId($projectId);

            $this->messaging = $factory->createMessaging();
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException(
                'Invalid Firebase service account JSON. Download a valid Service Account key from Firebase Console.'
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Failed to initialize Firebase SDK: '.$exception->getMessage());
        }
    }

    public function saveMessage(array $data): array
    {
        $response = $this->createDocument('messages', $data);
        $id = $this->extractDocumentId(Arr::get($response, 'name', ''));

        return array_merge($data, ['id' => $id]);
    }

    public function saveConversationMessage(string $conversationId, array $data): array
    {
        $response = $this->createDocument(sprintf('conversations/%s/messages', $conversationId), $data);
        $id = $this->extractDocumentId(Arr::get($response, 'name', ''));

        return array_merge($data, ['id' => $id]);
    }

    public function upsertConversation(string $conversationId, array $data): void
    {
        $path = sprintf('conversations/%s', $conversationId);
        $this->patchDocument($path, $data);
    }

    public function getUserTokens(string $userId): array
    {
        $document = $this->getDocument(sprintf('users/%s', $userId));
        if (empty($document)) {
            return [];
        }

        $values = Arr::get($document, 'fields.fcm_tokens.arrayValue.values', []);
        $arrayTokens = array_map(static function (array $item): string {
            return (string) Arr::get($item, 'stringValue', '');
        }, $values);
        $singleToken = (string) Arr::get($document, 'fields.fcmToken.stringValue', '');

        $tokens = array_merge($arrayTokens, [$singleToken]);

        return array_values(array_unique(array_filter($tokens)));
    }

    public function getUserProfile(string $userId): array
    {
        $document = $this->getDocument(sprintf('users/%s', $userId));
        if (empty($document)) {
            return [
                'name' => 'Chef',
                'avatar' => '',
            ];
        }

        $name = $this->stringField($document, 'fullName');
        if ($name === '') {
            $name = $this->stringField($document, 'nickname');
        }
        if ($name === '') {
            $name = $this->stringField($document, 'username');
        }

        return [
            'name' => $name !== '' ? $name : 'Chef',
            'avatar' => $this->stringField($document, 'avatarUrl'),
        ];
    }

    public function createCall(string $callId, array $data): void
    {
        $this->patchDocument(sprintf('calls/%s', $callId), $data);
    }

    public function updateCallStatus(string $callId, string $status): void
    {
        $this->patchDocument(sprintf('calls/%s', $callId), [
            'status' => $status,
            'updatedAt' => (int) (microtime(true) * 1000),
        ]);
    }

    public function addSignalingMessage(string $callId, array $data): array
    {
        $response = $this->createDocument(sprintf('calls/%s/signaling', $callId), $data);

        return array_merge($data, ['id' => $this->extractDocumentId(Arr::get($response, 'name', ''))]);
    }

    public function getCall(string $callId): array
    {
        $document = $this->getDocument(sprintf('calls/%s', $callId));
        if (empty($document)) {
            return [];
        }

        return [
            'callId' => $this->stringField($document, 'callId'),
            'callerId' => $this->stringField($document, 'callerId'),
            'receiverId' => $this->stringField($document, 'receiverId'),
            'type' => $this->stringField($document, 'type'),
            'status' => $this->stringField($document, 'status'),
            'createdAt' => (int) Arr::get($document, 'fields.createdAt.integerValue', 0),
            'updatedAt' => (int) Arr::get($document, 'fields.updatedAt.integerValue', 0),
        ];
    }

    public function getConversationUnreadCount(string $conversationId): array
    {
        $document = $this->getDocument(sprintf('conversations/%s', $conversationId));
        if (empty($document)) {
            return [];
        }

        $fields = Arr::get($document, 'fields.unreadCount.mapValue.fields', []);
        $result = [];
        foreach ($fields as $uid => $rawValue) {
            $intValue = Arr::get($rawValue, 'integerValue');
            $doubleValue = Arr::get($rawValue, 'doubleValue');
            $result[$uid] = (int) ($intValue ?? $doubleValue ?? 0);
        }

        return $result;
    }

    public function sendFCM(string $token, array $payload): void
    {
        $notification = Notification::create(
            Arr::get($payload, 'notification.title', 'New Message'),
            Arr::get($payload, 'notification.body', '')
        );

        $message = CloudMessage::withTarget('token', $token)
            ->withNotification($notification)
            ->withData(Arr::get($payload, 'data', []));

        $this->messaging->send($message);
    }

    private function resolveCredentialsPath(string $path): string
    {
        $isAbsolutePath = preg_match('/^[A-Za-z]:\\\\/', $path) === 1 || str_starts_with($path, '/');
        $resolvedPath = $isAbsolutePath ? $path : base_path($path);

        if (!file_exists($resolvedPath)) {
            throw new RuntimeException(sprintf('Firebase credentials file not found: %s', $resolvedPath));
        }

        return $resolvedPath;
    }

    private function createDocument(string $collectionPath, array $data): array
    {
        $url = sprintf('/v1/projects/%s/databases/(default)/documents/%s', $this->projectId, $collectionPath);

        return $this->requestFirestore('POST', $url, [
            'json' => [
                'fields' => $this->toFirestoreFields($data),
            ],
        ]);
    }

    private function patchDocument(string $documentPath, array $data): array
    {
        $url = sprintf('/v1/projects/%s/databases/(default)/documents/%s', $this->projectId, $documentPath);

        return $this->requestFirestore('PATCH', $url, [
            'json' => [
                'fields' => $this->toFirestoreFields($data),
            ],
        ]);
    }

    private function getDocument(string $documentPath): array
    {
        $url = sprintf('/v1/projects/%s/databases/(default)/documents/%s', $this->projectId, $documentPath);

        try {
            return $this->requestFirestore('GET', $url);
        } catch (RuntimeException $exception) {
            if (str_contains($exception->getMessage(), '404')) {
                return [];
            }

            throw $exception;
        }
    }

    private function requestFirestore(string $method, string $url, array $options = []): array
    {
        try {
            $token = $this->credentials->fetchAuthToken()['access_token'] ?? null;
            if (!$token) {
                throw new RuntimeException('Unable to fetch Firebase access token.');
            }

            $response = $this->httpClient->request($method, $url, array_merge_recursive([
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Content-Type' => 'application/json',
                ],
            ], $options));

            return json_decode((string) $response->getBody(), true) ?? [];
        } catch (GuzzleException $exception) {
            throw new RuntimeException('Firestore request failed: '.$exception->getMessage());
        }
    }

    private function toFirestoreFields(array $data): array
    {
        $fields = [];
        foreach ($data as $key => $value) {
            $fields[$key] = $this->toFirestoreValue($value);
        }

        return $fields;
    }

    private function toFirestoreValue($value): array
    {
        if (is_string($value)) {
            return ['stringValue' => $value];
        }

        if (is_int($value)) {
            return ['integerValue' => $value];
        }

        if (is_float($value)) {
            return ['doubleValue' => $value];
        }

        if (is_bool($value)) {
            return ['booleanValue' => $value];
        }

        if ($value === null) {
            return ['nullValue' => null];
        }

        if (is_array($value)) {
            if ($this->isAssoc($value)) {
                $map = [];
                foreach ($value as $k => $v) {
                    $map[$k] = $this->toFirestoreValue($v);
                }

                return ['mapValue' => ['fields' => $map]];
            }

            return [
                'arrayValue' => [
                    'values' => array_map(fn ($item) => $this->toFirestoreValue($item), $value),
                ],
            ];
        }

        return ['stringValue' => (string) $value];
    }

    private function isAssoc(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }

    private function extractDocumentId(string $name): string
    {
        $parts = explode('/', $name);

        return (string) end($parts);
    }

    private function stringField(array $document, string $field): string
    {
        return (string) Arr::get($document, sprintf('fields.%s.stringValue', $field), '');
    }
}
