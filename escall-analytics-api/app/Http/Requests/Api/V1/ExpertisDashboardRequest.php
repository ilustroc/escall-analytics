<?php

namespace App\Http\Requests\Api\V1;

final class ExpertisDashboardRequest extends AnalyticsFiltersRequest
{
    public function rules(): array
    {
        return $this->analyticsFilterRules();
    }
}
