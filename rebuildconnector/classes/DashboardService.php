<?php

defined('_PS_VERSION_') || exit;

class DashboardService
{
    /**
     * @param string $period
     * @return array<string, mixed>
     */
    public function getMetrics(string $period = 'month'): array
    {
        $range = $this->resolvePeriodRange($period);
        $from = $range['from'];
        $to = $range['to'];
        $fromSql = pSQL($from->format('Y-m-d H:i:s'));
        $toSql = pSQL($to->format('Y-m-d H:i:s'));

        $db = Db::getInstance();

        $ordersCount = (int) $db->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'orders WHERE date_add BETWEEN "' . $fromSql . '" AND "' . $toSql . '"'
        );

        $revenue = (float) $db->getValue(
            'SELECT SUM(total_paid_tax_incl) FROM ' . _DB_PREFIX_ . 'orders WHERE date_add BETWEEN "'
            . $fromSql . '" AND "' . $toSql . '"'
        );

        $customers = (int) $db->getValue(
            'SELECT COUNT(DISTINCT id_customer) FROM ' . _DB_PREFIX_ . 'orders WHERE date_add BETWEEN "'
            . $fromSql . '" AND "' . $toSql . '"'
        );

        $currency = $this->resolveCurrencyIso();
        $averageOrderValue = $ordersCount > 0 ? $revenue / $ordersCount : 0.0;

        return [
            'period' => [
                'label' => $period,
                'from' => $from->format(DATE_ATOM),
                'to' => $to->format(DATE_ATOM),
            ],
            'orders_count' => $ordersCount,
            'revenue' => $revenue,
            'average_order_value' => $averageOrderValue,
            'customers' => $customers,
            'currency' => $currency,
            'chart' => $this->buildChart($from, $to),
        ];
    }

    /**
     * @return array<string, \DateTimeImmutable>
     */
    private function resolvePeriodRange(string $period): array
    {
        $now = new \DateTimeImmutable('now');

        switch (Tools::strtolower($period)) {
            case 'day':
                $from = $now->setTime(0, 0, 0);
                $to = $now->setTime(23, 59, 59);
                break;
            case 'week':
                $from = $now->modify('monday this week')->setTime(0, 0, 0);
                $to = $now->setTime(23, 59, 59);
                break;
            case 'year':
                $from = $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0, 0);
                $to = $now->setTime(23, 59, 59);
                break;
            case 'month':
            default:
                $from = $now->setDate((int) $now->format('Y'), (int) $now->format('m'), 1)->setTime(0, 0, 0);
                $to = $now->setTime(23, 59, 59);
                break;
        }

        return [
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildChart(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $fromSql = pSQL($from->format('Y-m-d H:i:s'));
        $toSql = pSQL($to->format('Y-m-d H:i:s'));

        $query = new DbQuery();
        $query->select('DATE(date_add) AS day, SUM(total_paid_tax_incl) AS revenue, COUNT(*) AS orders');
        $query->from('orders');
        $query->where('date_add BETWEEN "' . $fromSql . '" AND "' . $toSql . '"');
        $query->groupBy('day');
        $query->orderBy('day ASC');

        $rows = Db::getInstance()->executeS($query);
        $indexed = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $indexed[$row['day']] = [
                    'revenue' => isset($row['revenue']) ? (float) $row['revenue'] : 0.0,
                    'orders' => isset($row['orders']) ? (int) $row['orders'] : 0,
                ];
            }
        }

        $chart = [];
        $period = new \DatePeriod(
            $from->setTime(0, 0, 0),
            new \DateInterval('P1D'),
            $to->setTime(23, 59, 59)->modify('+1 day')
        );

        foreach ($period as $date) {
            $key = $date->format('Y-m-d');
            $chart[] = [
                'date' => $key,
                'revenue' => isset($indexed[$key]) ? $indexed[$key]['revenue'] : 0.0,
                'orders' => isset($indexed[$key]) ? $indexed[$key]['orders'] : 0,
            ];
        }

        return $chart;
    }

    private function resolveCurrencyIso(): ?string
    {
        $defaultCurrencyId = (int) Configuration::get('PS_CURRENCY_DEFAULT');
        if ($defaultCurrencyId <= 0) {
            return null;
        }

        $currency = new Currency($defaultCurrencyId);
        return Validate::isLoadedObject($currency) ? (string) $currency->iso_code : null;
    }
}
