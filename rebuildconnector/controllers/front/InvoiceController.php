<?php

defined('_PS_VERSION_') || exit;

require_once __DIR__ . '/BaseApiController.php';

/**
 * Endpoint : GET /module/rebuildconnector/api/orders/{id}/invoice
 *
 * Renvoie le PDF de la première facture d'une commande PrestaShop.
 * Nécessite le scope orders.read.
 *
 * Réponses possibles :
 *   200  Content-Type: application/pdf — flux binaire du PDF
 *   404  {"error":"not_found","message":"..."} — commande ou facture introuvable
 *   401/403  erreurs d'authentification standard
 */
class RebuildconnectorInvoiceModuleFrontController extends RebuildconnectorBaseApiModuleFrontController
{
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

            $this->requireAuth(['orders.read']);
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
        } catch (\Throwable $exception) {
            $message = $this->isDevMode() ? $exception->getMessage() : $this->t('api.error.unexpected', [], 'Unexpected error occurred.');
            $this->jsonError('server_error', $message, 500);
        }
    }

    private function handleGet(): void
    {
        $orderId = (int) Tools::getValue('id', 0);
        if ($orderId <= 0) {
            $this->jsonError(
                'not_found',
                $this->t('orders.error.not_found', [], 'Order not found.'),
                404
            );
            return;
        }

        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            $this->jsonError(
                'not_found',
                $this->t('orders.error.not_found', [], 'Order not found.'),
                404
            );
            return;
        }

        // Récupère la liste des factures associées à la commande.
        $invoices = OrderInvoice::getByOrderId($orderId);
        if (!is_array($invoices) || count($invoices) === 0) {
            $this->jsonError(
                'not_found',
                $this->t('orders.error.invoice_not_found', [], 'No invoice available for this order.'),
                404
            );
            return;
        }

        // On renvoie la première facture disponible.
        /** @var OrderInvoice $invoice */
        $invoice = $invoices[0];
        $filename = $this->buildFilename($order, $invoice);

        $this->streamInvoicePdf($invoice, $filename);
    }

    /**
     * Génère le PDF via la classe PDF du core PrestaShop et l'envoie en streaming.
     */
    private function streamInvoicePdf(OrderInvoice $invoice, string $filename): void
    {
        // La classe PDF (rebuildconnector/classes/PDF.php ou core PS) reçoit un tableau d'objets.
        $pdf = new PDF([$invoice], PDF::TEMPLATE_INVOICE, $this->context->smarty);
        $pdfContent = $pdf->render(false);

        if ($pdfContent === false || $pdfContent === '') {
            $this->jsonError(
                'server_error',
                $this->t('api.error.unexpected', [], 'Unexpected error occurred.'),
                500
            );
            return;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdfContent));
        header('Cache-Control: private, no-cache, no-store, must-revalidate');
        http_response_code(200);

        $this->ajaxRender($pdfContent);
        exit;
    }

    private function buildFilename(Order $order, OrderInvoice $invoice): string
    {
        $ref = $order->reference !== '' ? $order->reference : (string) $order->id;
        $invoiceNumber = isset($invoice->number) ? (string) $invoice->number : '0';

        return 'facture_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $ref) . '_' . $invoiceNumber . '.pdf';
    }
}
