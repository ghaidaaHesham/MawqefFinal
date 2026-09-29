<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:191',
            'phone' => 'required|string|max:191|unique:users,phone|regex:/^09[0-9]{8}$/',
            'password' => 'required|string|min:8',
            'language' => 'sometimes|string|in:en,ar',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'الاسم مطلوب',
            'name.string' => 'الاسم يجب أن يكون نصاً',
            'name.max' => 'الاسم لا يتجاوز 191 حرف',
            'phone.required' => 'رقم الهاتف مطلوب',
            'phone.string' => 'رقم الهاتف يجب أن يكون نصاً',
            'phone.max' => 'رقم الهاتف لا يتجاوز 191 حرف',
            'phone.unique' => 'رقم الهاتف مستخدم بالفعل',
            'phone.regex' => 'رقم الهاتف يجب أن يتألف من 10 أرقام ويبدأ بـ 09',
            'password.required' => 'كلمة المرور مطلوبة',
            'password.string' => 'كلمة المرور يجب أن تكون نصاً',
            'password.min' => 'كلمة المرور يجب أن تتجاوز 8 أحرف',
            'language.string' => 'اللغة يجب أن تكون نصاً',
            'language.in' => 'اللغة يجب أن تكون إما en أو ar',
            'image.image' => 'يجب أن تكون الصورة بصيغة صورة',
            'image.mimes' => 'الصورة يجب أن تكون بصيغة: jpeg, png, jpg, gif, webp',
            'image.max' => 'حجم الصورة يجب أن لا يتجاوز 2MB',
        ];
    }
}