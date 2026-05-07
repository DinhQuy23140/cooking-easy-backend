<?php

namespace App\Services;

use Carbon\Carbon;

class ChatService
{
    private FirebaseService $firebaseService;

    private NotificationService $notificationService;

    public function __construct(FirebaseService $firebaseService, NotificationService $notificationService)
    {
        $this->firebaseService = $firebaseService;
        $this->notificationService = $notificationService;
    }

    public function sendMessage(string $senderId, string $receiverId, string $content): array
    {
        $conversationId = $this->createConversationId($senderId, $receiverId);
        $createdAtMillis = Carbon::now()->valueOf();

        // Firestore write happens first. If it fails, the controller returns error and no push is sent.
        $savedMessage = $this->firebaseService->saveConversationMessage($conversationId, [
            'senderId' => $senderId,
            'receiverId' => $receiverId,
            'text' => $content,
            'type' => 'text',
            'imageUrl' => '',
            'attachmentUrl' => '',
            'attachmentName' => '',
            'attachmentSize' => '',
            'createdAt' => $createdAtMillis,
        ]);

        $messageData = [
            'sender_id' => $senderId,
            'receiver_id' => $receiverId,
            'conversation_id' => $conversationId,
            'content' => $content,
            'created_at' => $createdAtMillis,
        ];
        $currentUnread = $this->firebaseService->getConversationUnreadCount($conversationId);
        $receiverUnread = (int) ($currentUnread[$receiverId] ?? 0) + 1;

        // Keep conversation summary for existing conversation list screens.
        $this->firebaseService->upsertConversation($conversationId, [
            'participants' => [$senderId, $receiverId],
            'lastMessage' => $content,
            'lastSenderId' => $senderId,
            'updatedAt' => $createdAtMillis,
            'seenBy' => [$senderId],
            'unreadCount' => [
                $senderId => 0,
                $receiverId => $receiverUnread,
            ],
        ]);

        $tokens = $this->firebaseService->getUserTokens($receiverId);
        $senderProfile = $this->firebaseService->getUserProfile($senderId);
        $notificationResult = $this->notificationService->sendChatNotification($tokens, $messageData, $senderProfile);

        return [
            'status' => 'success',
            'conversation_id' => $conversationId,
            'message' => $savedMessage,
            'notifications' => $notificationResult,
        ];
    }

    public function createConversationId(string $firstUserId, string $secondUserId): string
    {
        $participants = [$firstUserId, $secondUserId];
        sort($participants);

        return implode('_', $participants);
    }
}
