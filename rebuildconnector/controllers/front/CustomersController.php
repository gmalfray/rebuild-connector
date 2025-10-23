<?php

defined('_PS_VERSION_') || exit;

require_once __DIR__ . '/BaseApiController.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/CustomersService.php';

class RebuildconnectorCustomersModuleFrontController extends RebuildconnectorBaseApiModuleFrontController
{
    private ?CustomersService $customersService = null;

    public function initContent(): void
    {
        parent::initContent();

        $method = Tools::strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        try {
            if ($method !== 'GET') {
                header('Allow: GET');
                $this->jsonError(
                    'method_not_allowed',
                    $this->t('api.error.method_not_allowed', [], 'HTTP method not allowed.'),
                    405
                );
                return;
            }

            $this->requireAuth(['customers.read']);
            $this->handleGet();
        } catch (AuthenticationException $exception) {
            $this->jsonError(
                'unauthenticated',
                $this->t('api.error.unauthenticated', [], 'Authentication required.'),
                401
            );
        } catch (AuthorizationException $exception) {
            $this->jsonError(
                'forbidden',
                $this->t('api.error.forbidden', [], 'You do not have the required permissions.'),
                403
            );
        } catch (\InvalidArgumentException $exception) {
            $this->jsonError(
                'invalid_payload',
                $exception->getMessage(),
                400
            );
        } catch (\Throwable $exception) {
            $message = $this->isDevMode() ? $exception->getMessage() : $this->t('api.error.unexpected', [], 'Unexpected error occurred.');
            $this->jsonError('server_error', $message, 500);
        }
    }

    private function handleGet(): void
    {
        $customerId = (int) Tools::getValue('id_customer', (int) Tools::getValue('id', 0));
        if ($customerId > 0) {
            $customer = $this->getCustomersService()->getCustomerById($customerId);
            if ($customer === []) {
                $this->jsonError(
                    'not_found',
                    $this->t('customers.error.not_found', [], 'Customer not found.'),
                    404
                );
                return;
            }

            $this->renderJson([
                'customer' => $customer,
            ]);

            return;
        }

        $limit = $this->parseLimit(Tools::getValue('limit'));
        $offset = $this->parseOffset(Tools::getValue('offset'));

        $filters = [
            'limit' => $limit,
            'offset' => $offset,
        ];

        $search = Tools::getValue('search');
        if (is_string($search) && $search !== '') {
            $filters['search'] = $search;
        }

        $email = Tools::getValue('email');
        if (is_string($email) && $email !== '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException(
                    $this->t('customers.error.invalid_email', [], 'Email filter must be a valid email address.')
                );
            }
            $filters['email'] = $email;
        }

        $rawFilter = Tools::getValue('filter');
        $filterData = is_array($rawFilter) ? $rawFilter : [];

        $searchFilter = isset($filterData['search']) ? (string) $filterData['search'] : '';
        if ($searchFilter !== '') {
            $filters['search'] = $searchFilter;
        }

        $segment = isset($filterData['segment']) ? trim((string) $filterData['segment']) : '';
        if ($segment !== '') {
            $segment = Tools::strtolower($segment);
            if (!in_array($segment, ['new', 'repeat', 'vip', 'inactive'], true)) {
                throw new \InvalidArgumentException(
                    $this->t('customers.error.invalid_segment', [], 'Unknown customer segment filter.')
                );
            }
            $filters['segment'] = $segment;
        }

        $ordersMin = $this->parseNullableInt($filterData['min_orders'] ?? null, 'customers.error.invalid_min_orders');
        $ordersMax = $this->parseNullableInt($filterData['max_orders'] ?? null, 'customers.error.invalid_max_orders');
        if ($ordersMin !== null) {
            $filters['orders_min'] = $ordersMin;
        }
        if ($ordersMax !== null) {
            $filters['orders_max'] = $ordersMax;
        }
        if ($ordersMin !== null && $ordersMax !== null && $ordersMin > $ordersMax) {
            throw new \InvalidArgumentException(
                $this->t('customers.error.invalid_orders_range', [], 'min_orders cannot be greater than max_orders.')
            );
        }

        $spentMin = $this->parseNullableFloat($filterData['min_spent'] ?? null, 'customers.error.invalid_min_spent');
        $spentMax = $this->parseNullableFloat($filterData['max_spent'] ?? null, 'customers.error.invalid_max_spent');
        if ($spentMin !== null) {
            $filters['spent_min'] = $spentMin;
        }
        if ($spentMax !== null) {
            $filters['spent_max'] = $spentMax;
        }
        if ($spentMin !== null && $spentMax !== null && $spentMin > $spentMax) {
            throw new \InvalidArgumentException(
                $this->t('customers.error.invalid_spent_range', [], 'min_spent cannot be greater than max_spent.')
            );
        }

        $createdFrom = $this->parseNullableDate($filterData['created_from'] ?? null, 'customers.error.invalid_created_from');
        $createdTo = $this->parseNullableDate($filterData['created_to'] ?? null, 'customers.error.invalid_created_to');
        if ($createdFrom !== null) {
            $filters['created_from'] = $createdFrom;
        }
        if ($createdTo !== null) {
            $filters['created_to'] = $createdTo;
        }
        if ($createdFrom !== null && $createdTo !== null && $createdFrom > $createdTo) {
            throw new \InvalidArgumentException(
                $this->t('customers.error.invalid_created_range', [], 'created_from cannot be greater than created_to.')
            );
        }

        $sort = isset($filterData['sort']) ? (string) $filterData['sort'] : (string) Tools::getValue('sort', '');
        if ($sort !== '') {
            $sort = Tools::strtolower($sort);
            if (!in_array($sort, ['date_asc', 'date_desc', 'orders_desc', 'orders_asc', 'spent_desc', 'spent_asc'], true)) {
                throw new \InvalidArgumentException(
                    $this->t('customers.error.invalid_sort', [], 'Unsupported sort value.')
                );
            }
            $filters['sort'] = $sort;
        }

        $idsParam = $filterData['ids'] ?? Tools::getValue('ids');
        $ids = $this->parseIds($idsParam);
        if ($ids !== []) {
            $filters['ids'] = $ids;
        }

        $result = $this->getCustomersService()->getCustomers($filters);

        $this->renderJson([
            'customers' => $result['items'],
            'pagination' => $result['pagination'],
        ]);
    }

    private function getCustomersService(): CustomersService
    {
        if ($this->customersService === null) {
            $this->customersService = new CustomersService();
        }

        return $this->customersService;
    }

    /**
     * @param mixed $value
     */
    private function parseLimit($value): int
    {
        if ($value === null || $value === '') {
            return CustomersService::DEFAULT_LIMIT;
        }

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(
                $this->t('customers.error.invalid_limit', [], 'Limit must be a positive integer.')
            );
        }

        $limit = (int) $value;
        if ($limit <= 0) {
            throw new \InvalidArgumentException(
                $this->t('customers.error.invalid_limit', [], 'Limit must be a positive integer.')
            );
        }

        if ($limit > CustomersService::MAX_LIMIT) {
            return CustomersService::MAX_LIMIT;
        }

        return $limit;
    }

    /**
     * @param mixed $value
     */
    private function parseOffset($value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(
                $this->t('customers.error.invalid_offset', [], 'Offset must be a non-negative integer.')
            );
        }

        $offset = (int) $value;
        if ($offset < 0) {
            throw new \InvalidArgumentException(
                $this->t('customers.error.invalid_offset', [], 'Offset must be a non-negative integer.')
            );
        }

        return $offset;
    }

    /**
     * @param mixed $value
     */
    private function parseNullableInt($value, string $translationKey): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(
                $this->t($translationKey, [], 'This filter expects an integer value.')
            );
        }

        $int = (int) $value;
        if ($int < 0) {
            throw new \InvalidArgumentException(
                $this->t($translationKey, [], 'This filter expects a positive integer value.')
            );
        }

        return $int;
    }

    /**
     * @param mixed $value
     */
    private function parseNullableFloat($value, string $translationKey): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(
                $this->t($translationKey, [], 'This filter expects a numeric value.')
            );
        }

        $float = (float) $value;
        if ($float < 0) {
            throw new \InvalidArgumentException(
                $this->t($translationKey, [], 'This filter expects a positive numeric value.')
            );
        }

        return $float;
    }

    /**
     * @param mixed $value
     */
    private function parseNullableDate($value, string $translationKey): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                $this->t($translationKey, [], 'This filter expects a date string.')
            );
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new \InvalidArgumentException(
                $this->t($translationKey, [], 'This filter expects a valid date.')
            );
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function parseIds($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $parts = explode(',', $value);
        } elseif (is_array($value)) {
            $parts = $value;
        } else {
            throw new \InvalidArgumentException(
                $this->t('customers.error.invalid_ids', [], 'IDs filter must be a list of integers.')
            );
        }

        $ids = [];
        foreach ($parts as $part) {
            if (!is_numeric($part)) {
                throw new \InvalidArgumentException(
                    $this->t('customers.error.invalid_ids', [], 'IDs filter must be a list of integers.')
                );
            }
            $ids[] = (int) $part;
        }

        return array_values(array_unique(array_filter($ids, static function (int $value): bool {
            return $value > 0;
        })));
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function buildFiltersMeta(array $filters): array
    {
        $meta = [];
        foreach ($filters as $key => $value) {
            if (in_array($key, ['limit', 'offset'], true)) {
                continue;
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if (is_array($value)) {
                $meta[$key] = array_values($value);
                continue;
            }

            $meta[$key] = $value;
        }

        return $meta;
    }
}
