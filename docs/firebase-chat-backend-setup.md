# Firebase Firestore + FCM Chat Backend Setup

This backend is designed so chat writes happen only through Laravel APIs and all chat data is stored in Firebase Firestore.

## Migration note: removing Cloud Functions

Legacy trigger flow:

`Firestore write -> Cloud Function trigger -> FCM send`

New flow:

`Android client -> Laravel POST /api/send-message -> Firestore write -> FCM send`

There are no Firestore `onCreate` triggers in this architecture. Laravel is the single place where message write and notification dispatch are orchestrated.

## 1) Install dependencies

The current repository is Laravel 8 with PHP 7.4. To run modern `kreait/firebase-php` versions, upgrade runtime first:

- PHP `>= 8.1` (recommended `8.3`)
- Laravel `>= 10` (recommended latest)

Then install:

```bash
composer require kreait/firebase-php
```

If you must stay on PHP 7.4 temporarily, use an older compatible Kreait version at your own risk and review dependency security advisories carefully.

## 2) Firebase service account

1. Open Firebase Console -> Project Settings -> Service Accounts
2. Generate a new private key JSON file
3. Store it in a secure location outside git, for example:

```text
storage/app/firebase/firebase-service-account.json
```

4. Set environment variables in `.env`:

```env
FIREBASE_PROJECT_ID=your-firebase-project-id
FIREBASE_CREDENTIALS=storage/app/firebase/firebase-service-account.json
```

## 3) API endpoint

`POST /api/send-message`

Request body:

```json
{
  "sender_id": "user1",
  "receiver_id": "user2",
  "content": "Hello"
}
```

Response:

```json
{
  "status": "success",
  "conversation_id": "user1_user2",
  "message": {},
  "notifications": {
    "success": 1,
    "failed": 0
  }
}
```

## 4) Firestore data model

- `messages` collection:
  - `sender_id`, `receiver_id`, `conversation_id`, `content`, `created_at`
- `users` collection:
  - `fcm_tokens` array (used for push notifications)

## 5) Firestore indexing

Create composite indexes for:

- `messages.conversation_id` + `messages.created_at` (ascending/descending per query requirements)
- Any additional unread/last seen query combinations you add

## 6) Security recommendations

- Keep Firestore security rules restrictive: deny direct client writes for chat message paths.
- Allow clients to call Laravel API only.
- Validate payloads server-side (already implemented in `ChatController`).
- Keep service account JSON out of version control.

## 7) Optional feature extensions

- Unread count increment/decrement by user
- Last seen timestamps by user
- Typing indicator document updates with TTL
