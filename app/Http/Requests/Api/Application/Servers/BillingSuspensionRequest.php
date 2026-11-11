<?php

namespace Pterodactyl\Http\Requests\Api\Application\Servers;

class BillingSuspensionRequest extends ServerWriteRequest
{
    public function rules(): array
    {
        return [
            'action' => ['required', 'in:suspend,unsuspend'],
            'expected_owner_id' => ['required', 'integer', 'min:1'],
            'expected_tracking_id' => ['required', 'integer', 'min:1'],
            'expected_subscription_id' => ['required', 'string', 'max:255'],
            'expected_customer_id' => ['required', 'string', 'max:255'],
            'expected_tracking_status' => ['required', 'in:active,suspended,pending_deletion'],
            'expected_subscription_status' => ['required', 'string', 'max:32'],
            'expected_cancel_at_period_end' => ['required', 'boolean'],
        ];
    }
}
