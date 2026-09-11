<?php

namespace App\Http\Requests\Concerns;

trait ValidatesPlanOptions
{
    protected function planOptionsRules(): array
    {
        $rules = ['options' => 'sometimes|nullable|array:transfer_enable,device_limit,speed_limit,addon_groups,granted_groups'];
        // null is the same as an omitted selection; nullable limits still need to be
        // present when an actual selection object is supplied.
        if ($this->input('options') === null) {
            return $rules;
        }

        return $rules + [
            'options.transfer_enable' => 'required_with:options|integer|min:1|max:1000000',
            'options.device_limit' => 'present_with:options|nullable|integer|min:0',
            'options.speed_limit' => 'present_with:options|nullable|integer|min:0',
            'options.addon_groups' => 'sometimes|array|list|max:20',
            'options.addon_groups.*' => 'integer|min:1',
            // Older clients echo plan_options verbatim. The calculator ignores this
            // derived field and always computes granted groups from the sold rules.
            'options.granted_groups' => 'sometimes|array|list|max:20',
            'options.granted_groups.*' => 'integer|min:1',
        ];
    }

    public function planOptions(): ?array
    {
        $options = $this->validated('options');
        if ($options === null) {
            return null;
        }
        // Laravel's integer validation also accepts form-encoded integer strings.
        // Convert only after validation, retaining null as the unlimited sentinel.
        foreach (['transfer_enable', 'device_limit', 'speed_limit'] as $field) {
            if (isset($options[$field])) {
                $options[$field] = (int) $options[$field];
            }
        }
        foreach (['addon_groups', 'granted_groups'] as $field) {
            if (isset($options[$field])) {
                $options[$field] = array_map('intval', $options[$field]);
            }
        }
        return $options;
    }
}
