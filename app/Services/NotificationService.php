<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationService
{
    private FirebaseService $firebaseService;

    public function __construct(FirebaseService $firebaseService)
    {
        $this->firebaseService = $firebaseService;
    }

    public function sendChatNotification(array $tokens, array $messageData, array $senderProfile = []): array
    {
        $senderName = (string) ($senderProfile['name'] ?? 'Chef');
        $senderAvatar = (string) ($senderProfile['avatar'] ?? '');

        $payload = [
            'notification' => [
                'title' => $senderName,
                'body' => $messageData['content'],
            ],
            'data' => [
                'type' => 'chat',
                'conversation_id' => $messageData['conversation_id'],
                'sender_id' => $messageData['sender_id'],
                // Keep these keys for Android client compatibility.
                'title' => $senderName,
                'body' => $messageData['content'],
                'otherUid' => $messageData['sender_id'],
                'otherName' => $senderName,
                'otherAvatar' => $senderAvatar,
            ],
        ];

        $results = [
            'success' => 0,
            'failed' => 0,
        ];

        if (empty($tokens)) {
            Log::info('No FCM tokens found for receiver.', [
                'conversation_id' => $messageData['conversation_id'],
                'receiver_id' => $messageData['receiver_id'] ?? null,
            ]);
        }

        foreach ($tokens as $token) {
            try {
                $this->firebaseService->sendFCM($token, $payload);
                $results['success']++;
            } catch (Throwable $exception) {
                $results['failed']++;

                Log::warning('Failed to send FCM notification.', [
                    'token' => $token,
                    'conversation_id' => $messageData['conversation_id'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        Log::info('FCM dispatch result.', [
            'conversation_id' => $messageData['conversation_id'],
            'success' => $results['success'],
            'failed' => $results['failed'],
        ]);

        return $results;
    }

    public function sendIncomingCallNotification(array $tokens, array $callData, array $callerProfile = []): array
    {
        $callerName = (string) ($callerProfile['name'] ?? 'Chef');
        $callerAvatar = (string) ($callerProfile['avatar'] ?? '');
        $body = sprintf('Incoming %s call', $callData['type']);

        $payload = [
            'notification' => [
                'title' => $callerName,
                'body' => $body,
            ],
            'data' => [
                'type' => 'incoming_call',
                'callId' => $callData['callId'],
                'callerId' => $callData['callerId'],
                'receiverId' => $callData['receiverId'],
                'callType' => $callData['type'],
                'callerName' => $callerName,
                'callerAvatar' => $callerAvatar,
            ],
        ];

        $results = ['success' => 0, 'failed' => 0];
        foreach ($tokens as $token) {
            try {
                $this->firebaseService->sendFCM($token, $payload);
                $results['success']++;
            } catch (Throwable $exception) {
                $results['failed']++;
                Log::warning('Failed to send incoming call notification.', [
                    'token' => $token,
                    'callId' => $callData['callId'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $results;
    }

    public function sendCallStatusNotification(array $tokens, array $callData, string $status): array
    {
        switch ($status) {
            case 'accepted':
                $statusText = 'Call accepted';
                break;
            case 'rejected':
                $statusText = 'Call rejected';
                break;
            case 'ended':
                $statusText = 'Call ended';
                break;
            default:
                $statusText = 'Call update';
                break;
        }

        $payload = [
            'notification' => [
                'title' => 'Call Status',
                'body' => $statusText,
            ],
            'data' => [
                'type' => 'call_status',
                'callId' => $callData['callId'] ?? '',
                'status' => $status,
                'callerId' => $callData['callerId'] ?? '',
                'receiverId' => $callData['receiverId'] ?? '',
                'callType' => $callData['type'] ?? '',
            ],
        ];

        $results = ['success' => 0, 'failed' => 0];
        foreach ($tokens as $token) {
            try {
                $this->firebaseService->sendFCM($token, $payload);
                $results['success']++;
            } catch (Throwable $exception) {
                $results['failed']++;
                Log::warning('Failed to send call status notification.', [
                    'token' => $token,
                    'callId' => $callData['callId'] ?? '',
                    'status' => $status,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $results;
    }
}
