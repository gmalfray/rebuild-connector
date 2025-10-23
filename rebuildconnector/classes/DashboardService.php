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

        $revenueTaxIncl = (float) $db->getValue(
            'SELECT SUM(total_paid_tax_incl) FROM ' . _DB_PREFIX_ . 'orders WHERE date_add BETWEEN "'
            . $fromSql . '" AND "' . $toSql . '"'
        );

        $revenueTaxExcl = (float) $db->getValue(
            'SELECT SUM(total_paid_tax_excl) FROM ' . _DB_PREFIX_ . 'orders WHERE date_add BETWEEN "'
            . $fromSql . '" AND "' . $toSql . '"'
        );

        $taxCollected = max(0.0, $revenueTaxIncl - $revenueTaxExcl);

        $customersCount = (int) $db->getValue(
            'SELECT COUNT(DISTINCT id_customer) FROM ' . _DB_PREFIX_ . 'orders WHERE date_add BETWEEN "'
            . $fromSql . '" AND "' . $toSql . '"'
        );

        $returns = (int) $db->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'order_return WHERE date_add BETWEEN "' . $fromSql . '" AND "' . $toSql . '"'
        );

        $currency = $this->resolveCurrencyIso();
        $averageBasket = $ordersCount > 0 ? $revenueTaxIncl / $ordersCount : 0.0;

        return [
            'period' => [
                'label' => $period,
                'from' => $from->format(DATE_ATOM),
                'to' => $to->format(DATE_ATOM),
            ],
            'turnover' => $revenueTaxIncl,
            'orders_count' => $ordersCount,
            'customers_count' => $customersCount,
            'products_count' => $this->countActiveProducts(),
            'revenue' => $revenueTaxIncl,
            'revenue_tax_incl' => $revenueTaxIncl,
            'revenue_tax_excl' => $revenueTaxExcl,
            'tax_collected' => $taxCollected,
            'average_basket' => $averageBasket,
            'average_order_value' => $averageBasket,
            'returns' => $returns,
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
        $query->select('DATE(o.date_add) AS day');
        $query->select('SUM(o.total_paid_tax_incl) AS revenue');
        $query->select('COUNT(*) AS orders');
        $query->select('COUNT(DISTINCT o.id_customer) AS customers');
        $query->from('orders', 'o');
        $query->where('o.date_add BETWEEN "' . $fromSql . '" AND "' . $toSql . '"');
        $query->groupBy('day');
        $query->orderBy('day ASC');

        $rows = (array) Db::getInstance()->executeS($query);
        $indexed = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            if (!isset($row['day'])) {
                continue;
            }

            $day = (string) $row['day'];
            $indexed[$day] = [
                'revenue' => isset($row['revenue']) ? (float) $row['revenue'] : 0.0,
                'orders' => isset($row['orders']) ? (int) $row['orders'] : 0,
                'customers' => isset($row['customers']) ? (int) $row['customers'] : 0,
            ];
        }

        $chart = [];
        $period = new \DatePeriod(
            $from->setTime(0, 0, 0),
            new \DateInterval('P1D'),
            $to->setTime(23, 59, 59)->modify('+1 day')
        );

        foreach ($period as $date) {
            $key = $date->format('Y-m-d');
            $data = $indexed[$key] ?? ['revenue' => 0.0, 'orders' => 0, 'customers' => 0];
            $chart[] = [
                'label' => $key,
                'turnover' => (float) $data['revenue'],
                'orders' => (int) $data['orders'],
                'customers' => (int) $data['customers'],
            ];
        }

        return $chart;
    }

    private function countActiveProducts(): int
    {
        $result = Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product WHERE active = 1'
        );

        return $result !== false ? (int) $result : 0;
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
