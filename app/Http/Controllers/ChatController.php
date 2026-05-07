<?php

namespace App\Http\Controllers;

use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Kreait\Firebase\Exception\FirebaseException;
use Throwable;

class ChatController extends Controller
{
    public function sendMessage(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'sender_id' => ['required', 'string', 'max:100', 'different:receiver_id'],
            'receiver_id' => ['required', 'string', 'max:100'],
            'content' => ['required', 'string', 'max:4000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $chatService = app(ChatService::class);

            $result = $chatService->sendMessage(
                $request->input('sender_id'),
                $request->input('receiver_id'),
                trim($request->input('content'))
            );

            return response()->json($result);
        } catch (FirebaseException $exception) {
            Log::error('Firebase operation failed.', [
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process Firebase request.',
            ], 503);
        } catch (\RuntimeException $exception) {
            Log::error('Firebase configuration error.', [
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 503);
        } catch (Throwable $exception) {
            Log::error('Unexpected error in sendMessage API.', [
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Unexpected server error.',
            ], 500);
        }
    }
}
