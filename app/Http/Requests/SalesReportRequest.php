<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TransactionPayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalesReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->routeIs('reports.export') ? 'reports.export' : 'reports.view') === true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'method' => ['nullable', Rule::in(array_keys(TransactionPayment::METHODS))],
        ];
    }
}
