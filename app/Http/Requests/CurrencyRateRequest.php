<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CurrencyRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.view') === true;
    }

    public function rules(): array
    {
        return ['currency' => ['required', Rule::in(array_column(config('currencies'), 'code'))]];
    }
}
