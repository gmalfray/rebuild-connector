<?php

defined('_PS_VERSION_') || exit;

class DashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function getMetrics(): array
    {
        // TODO: compute metrics and KPI for the dashboard.
        return [
            'orders_count' => 0,
            'revenue' => 0,
            'customers' => 0,
        ];
    }
}
