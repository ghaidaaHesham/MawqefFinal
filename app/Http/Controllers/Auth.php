<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRenterRequest;
use App\Http\Requests\RegisterOwnerRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class Auth extends Controller
{
    use ApiResponse;

    public function __construct(
        private AuthService $authService
    ) {}

    public function registerUserRenter(RegisterRenterRequest $request)
    {
        try {
            $result = $this->authService->registerUser(
                $request->validated(),
                roleId: 3,
                image: $request->file('image')
            );

            return $this->successResponse(
                [
                    'user' => new UserResource($result['user']),
                    'role' => $result['role'],
                    'image' => $result['image'],
                    'token' => $result['token'],
                    'token_type' => 'Bearer',
                ],
                'تم إنشاء الحساب بنجاح',
                201
            );

        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors());
        } catch (QueryException $e) {
            Log::error('DB Error in registerUserRenter', [
                'error' => $e->getMessage(),
                'phone' => $request->phone ?? null,
            ]);
            return $this->errorResponse('حدث خطأ في قاعدة البيانات', 'database_error', 500);
        } catch (\Exception $e) {
            Log::error('Error in registerUserRenter', [
                'error' => $e->getMessage(),
                'phone' => $request->phone ?? null,
            ]);
            return $this->errorResponse('حدث خطأ أثناء إنشاء الحساب', 'general_error', 500);
        }
    }

    public function registerUserOwner(RegisterOwnerRequest $request)
    {
        try {
            $result = $this->authService->registerUser(
                $request->validated(),
                roleId: 2,
                image: $request->file('image')
            );

            return $this->successResponse(
                [
                    'user' => new UserResource($result['user']),
                    'role' => $result['role'],
                    'image' => $result['image'],
                    'token' => $result['token'],
                    'token_type' => 'Bearer',
                ],
                'تم إنشاء حساب صاحب الموقف بنجاح',
                201
            );

        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors());
        } catch (QueryException $e) {
            Log::error('DB Error in registerUserOwner', [
                'error' => $e->getMessage(),
                'phone' => $request->phone ?? null,
            ]);
            return $this->errorResponse('حدث خطأ في قاعدة البيانات', 'database_error', 500);
        } catch (\Exception $e) {
            Log::error('Error in registerUserOwner', [
                'error' => $e->getMessage(),
                'phone' => $request->phone ?? null,
            ]);
            return $this->errorResponse('حدث خطأ أثناء إنشاء حساب صاحب الموقف', 'general_error', 500);
        }
    }

    public function loginUser(LoginRequest $request)
    {
        try {
            $result = $this->authService->login(
                $request->phone,
                $request->password
            );

            return $this->successResponse(
                [
                    'user' => new UserResource($result['user']),
                    'role' => $result['role'],
                    'token' => $result['token'],
                    'token_type' => 'Bearer',
                ],
                'تم تسجيل الدخول بنجاح'
            );

        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors());
        } catch (QueryException $e) {
            Log::error('DB Error in loginUser', [
                'error' => $e->getMessage(),
                'phone' => $request->phone ?? null,
            ]);
            return $this->errorResponse('حدث خطأ في قاعدة البيانات', 'database_error', 500);
        } catch (\Exception $e) {
            Log::warning('Login failed', [
                'phone' => $request->phone,
                'reason' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);
            return $this->unauthorizedResponse($e->getMessage());
        }
    }

    public function logoutUser(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return $this->errorResponse('غير مصرح لك', 'unauthenticated', 401);
            }

            $this->authService->logout($user);

            return $this->successResponse(
                [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'phone' => $user->phone,
                    ],
                    'logged_out_at' => now()->toDateTimeString(),
                ],
                'تم تسجيل الخروج بنجاح'
            );

        } catch (\Exception $e) {
            Log::error('Error in logoutUser', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return $this->errorResponse('حدث خطأ أثناء تسجيل الخروج', 'general_error', 500);
        }
    }
}