<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * Tests unitaires pour l'endpoint GET /orders/{id}/invoice.
 *
 * On ne peut pas instancier le controller directement (il hérite de
 * ModuleFrontController qui suppose un contexte PS complet), donc on teste
 * le service sous-jacent via un helper isolé qui reproduit la logique
 * de résolution de factures.
 */
final class InvoiceControllerTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Logique de résolution de facture (OrderInvoice::getByOrderId)
    // -----------------------------------------------------------------------

    public function testNoInvoiceReturnsEmptyArray(): void
    {
        $resolver = new InvoiceResolverHelper();
        $invoices = $resolver->resolveInvoices(999);

        $this->assertIsArray($invoices);
        $this->assertCount(0, $invoices);
    }

    public function testFilenameBuiltFromOrderReference(): void
    {
        $builder = new InvoiceFilenameHelper();
        $filename = $builder->build('XPREF0042', 12);

        $this->assertSame('facture_XPREF0042_12.pdf', $filename);
    }

    public function testFilenameWithSpecialCharsIsSanitized(): void
    {
        $builder = new InvoiceFilenameHelper();
        $filename = $builder->build('REF/2024 #01', 1);

        // Les slashes, espaces et # doivent être remplacés par _
        $this->assertMatchesRegularExpression('/^facture_[a-zA-Z0-9_\-]+_\d+\.pdf$/', $filename);
    }

    public function testFilenameWithEmptyReferenceUsesId(): void
    {
        $builder = new InvoiceFilenameHelper();
        // PHP 7.4 minimum (pas d'arguments nommés) : positionnel = reference, invoiceNumber, orderId
        $filename = $builder->build('', 5, 99);

        $this->assertSame('facture_99_5.pdf', $filename);
    }
}

// ---------------------------------------------------------------------------
// Helpers de test isolés (pas de dépendance BDD/PS)
// ---------------------------------------------------------------------------

/**
 * Reproduit la logique de résolution des factures sans BDD réelle.
 */
final class InvoiceResolverHelper
{
    /**
     * @return array<int, OrderInvoice>
     */
    public function resolveInvoices(int $orderId): array
    {
        // Le stub OrderInvoice::getByOrderId retourne [] par défaut.
        return OrderInvoice::getByOrderId($orderId);
    }
}

/**
 * Reproduit la logique de construction du nom de fichier du controller.
 */
final class InvoiceFilenameHelper
{
    public function build(string $reference, int $invoiceNumber, int $orderId = 0): string
    {
        $ref = $reference !== '' ? $reference : (string) $orderId;
        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $ref);

        return 'facture_' . $sanitized . '_' . $invoiceNumber . '.pdf';
    }
}
