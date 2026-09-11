<?php

namespace App\Http\Requests\User;

use App\Http\Requests\Concerns\ValidatesPlanOptions;
use Illuminate\Foundation\Http\FormRequest;

class PlanQuote extends FormRequest
{
    use ValidatesPlanOptions;

    public function rules(): array
    {
        return [
            'plan_id' => 'required|integer',
            'period' => 'required|string',
        ] + $this->planOptionsRules();
    }
}
