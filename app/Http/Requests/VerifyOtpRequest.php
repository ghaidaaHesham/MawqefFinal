<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => 'required|string|regex:/^09[0-9]{8}$/',
            'otp'   => 'required|string|size:6',
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'رقم الهاتف مطلوب',
            'phone.regex'    => 'رقم الهاتف غير صحيح',
            'otp.required'   => 'رمز التحقق مطلوب',
            'otp.size'       => 'رمز التحقق يجب أن يكون 6 أرقام',
        ];
    }
}