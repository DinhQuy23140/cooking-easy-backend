<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Str;

class CallService
{
    private FirebaseService $firebaseService;

    private NotificationService $notificationService;

    public function __construct(FirebaseService $firebaseService, NotificationService $notificationService)
    {
        $this->firebaseService = $firebaseService;
        $this->notificationService = $notificationService;
    }

    public function initiate(string $callerId, string $receiverId, string $type): array
    {
        $now = Carbon::now()->valueOf();
        $callId = (string) Str::uuid();
        $callData = [
            'callId' => $callId,
            'callerId' => $callerId,
            'receiverId' => $receiverId,
            'type' => $type,
            'status' => 'ringing',
            'createdAt' => $now,
            'updatedAt' => $now,
            'expiresAt' => $now + 30000,
        ];

        $this->firebaseService->createCall($callId, $callData);
        $receiverTokens = $this->firebaseService->getUserTokens($receiverId);
        $callerProfile = $this->firebaseService->getUserProfile($callerId);
        $pushResult = $this->notificationService->sendIncomingCallNotification($receiverTokens, $callData, $callerProfile);

        return [
            'status' => 'success',
            'callId' => $callId,
            'call' => $callData,
            'notifications' => $pushResult,
        ];
    }

    public function accept(string $callId): array
    {
        $callData = $this->firebaseService->getCall($callId);
        $this->firebaseService->updateCallStatus($callId, 'accepted');
        if (!empty($callData['callerId'])) {
            $tokens = $this->firebaseService->getUserTokens($callData['callerId']);
            $this->notificationService->sendCallStatusNotification($tokens, $callData, 'accepted');
        }

        return ['status' => 'success', 'callId' => $callId];
    }

    public function reject(string $callId): array
    {
        $callData = $this->firebaseService->getCall($callId);
        $this->firebaseService->updateCallStatus($callId, 'rejected');
        if (!empty($callData['callerId'])) {
            $tokens = $this->firebaseService->getUserTokens($callData['callerId']);
            $this->notificationService->sendCallStatusNotification($tokens, $callData, 'rejected');
        }

        return ['status' => 'success', 'callId' => $callId];
    }

    public function end(string $callId): array
    {
        $callData = $this->firebaseService->getCall($callId);
        $this->firebaseService->updateCallStatus($callId, 'ended');
        if (!empty($callData['callerId'])) {
            $callerTokens = $this->firebaseService->getUserTokens($callData['callerId']);
            $this->notificationService->sendCallStatusNotification($callerTokens, $callData, 'ended');
        }
        if (!empty($callData['receiverId'])) {
            $receiverTokens = $this->firebaseService->getUserTokens($callData['receiverId']);
            $this->notificationService->sendCallStatusNotification($receiverTokens, $callData, 'ended');
        }

        return ['status' => 'success', 'callId' => $callId];
    }
}
