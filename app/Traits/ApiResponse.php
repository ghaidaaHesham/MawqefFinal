<?php

namespace App\Traits;

trait ApiResponse
{
    protected function successResponse(
        mixed $data = null,
        string $message = 'Success',
        int $code = 200
    ) {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    protected function errorResponse(
        string $message,
        string $errorType = 'general_error',
        int $code = 500,
        ?array $errors = null
    ) {
        $response = [
            'success' => false,
            'message' => $message,
            'error_type' => $errorType,
            'status_code' => $code,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $code);
    }

    protected function validationErrorResponse(array $errors)
    {
        return $this->errorResponse(
            'فشل التحقق من البيانات',
            'validation_error',
            422,
            $errors
        );
    }

    protected function unauthorizedResponse(string $message = 'بيانات الدخول غير صحيحة')
    {
        return $this->errorResponse($message, 'invalid_credentials', 401);
    }
}