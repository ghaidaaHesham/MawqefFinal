<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => 'required|string|regex:/^09[0-9]{8}$/|exists:users,phone',
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'رقم الهاتف مطلوب',
            'phone.string'   => 'رقم الهاتف يجب أن يكون نصاً',
            'phone.regex'    => 'رقم الهاتف غير صحيح، يجب أن يبدأ بـ 09 ويتكون من 10 أرقام',
            'phone.exists'   => 'رقم الهاتف غير مسجل في النظام',
        ];
    }
}