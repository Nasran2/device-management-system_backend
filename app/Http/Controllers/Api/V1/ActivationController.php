<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DeviceActivationException;
use App\Http\Controllers\Controller;
use App\Services\ActivationService;
use Illuminate\Http\Request;

class ActivationController extends Controller
{
    public function __invoke(Request $request, ActivationService $service)
    {
        $submittedCode = $request->input('activation_code');
        if (! is_string($submittedCode)) {
            return $this->invalidCodeResponse();
        }

        $normalizedCode = $service->normalizeCode($submittedCode);
        if (! $service->isCompatibleCode($normalizedCode)) {
            return $this->invalidCodeResponse();
        }
        $request->merge(['activation_code' => $normalizedCode]);

        $data = $request->validate([
            'activation_code' => ['required', 'string', 'regex:/\ADG-[A-Z0-9]{7}\z/D'],
            'device_reference' => ['nullable', 'uuid'],
            'device_uuid' => ['required', 'uuid'],
            'android_id' => ['nullable', 'string', 'max:255'],
            'fcm_token' => ['nullable', 'string', 'max:4096'],
            'android_version' => ['nullable', 'string', 'max:50'],
            'app_version' => ['nullable', 'string', 'max:50'],
        ]);
        try {
            $result = $service->activate($data['activation_code'], $data);
        } catch (DeviceActivationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'error_code' => $exception->errorCode,
                'errors' => ['activation_code' => [$exception->getMessage()]],
            ], $exception->httpStatus);
        }

        return response()->json(['message' => 'Device activated.', 'data' => ['device_uuid' => $result['device']->uuid, 'device_token' => $result['token'], 'command_verification_key' => $result['verification_key'], 'status' => $result['device']->status]], 201);
    }

    private function invalidCodeResponse()
    {
        $message = 'The activation code is invalid, expired, or already used.';

        return response()->json([
            'message' => $message,
            'error_code' => 'invalid_activation_code',
            'errors' => ['activation_code' => [$message]],
        ], 422);
    }
}
