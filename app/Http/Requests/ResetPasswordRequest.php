<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone'        => 'required|string|regex:/^09[0-9]{8}$/|exists:users,phone',
            'new_password' => 'required|string|min:8|confirmed',
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required'          => 'رقم الهاتف مطلوب',
            'phone.regex'             => 'رقم الهاتف غير صحيح',
            'phone.exists'            => 'رقم الهاتف غير مسجل في النظام',
            'new_password.required'   => 'كلمة المرور الجديدة مطلوبة',
            'new_password.min'        => 'كلمة المرور يجب أن تكون 8 أحرف على الأقل',
            'new_password.confirmed'  => 'تأكيد كلمة المرور غير متطابق',
        ];
    }
}