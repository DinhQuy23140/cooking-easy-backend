<?php

namespace App\Http\Controllers;

use App\Services\CallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class CallController extends Controller
{
    private CallService $callService;

    public function __construct(CallService $callService)
    {
        $this->callService = $callService;
    }

    public function initiate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'callerId' => ['required', 'string', 'max:100', 'different:receiverId'],
            'receiverId' => ['required', 'string', 'max:100'],
            'type' => ['required', 'in:audio,video'],
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            return response()->json(
                $this->callService->initiate(
                    $request->input('callerId'),
                    $request->input('receiverId'),
                    $request->input('type')
                )
            );
        } catch (Throwable $exception) {
            Log::error('Call initiate failed', ['error' => $exception->getMessage()]);

            return response()->json(['status' => 'error', 'message' => 'Failed to initiate call'], 503);
        }
    }

    public function accept(Request $request): JsonResponse
    {
        return $this->transition($request, 'accept');
    }

    public function reject(Request $request): JsonResponse
    {
        return $this->transition($request, 'reject');
    }

    public function end(Request $request): JsonResponse
    {
        return $this->transition($request, 'end');
    }

    private function transition(Request $request, string $action): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'callId' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            /** @var array $result */
            $result = $this->callService->{$action}($request->input('callId'));

            return response()->json($result);
        } catch (Throwable $exception) {
            Log::error(sprintf('Call %s failed', $action), ['error' => $exception->getMessage()]);

            return response()->json(['status' => 'error', 'message' => sprintf('Failed to %s call', $action)], 503);
        }
    }
}
