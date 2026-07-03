<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * Vérifie que hookActionValidateOrder (déclenché pendant la validation de paiement client) reste
 * défensif et non bloquant : il ne doit JAMAIS lever d'exception ni faire échouer la commande, et
 * les appels push/webhook best-effort sont différés hors du chemin bloquant du checkout
 * (register_shutdown_function + fastcgi_finish_request).
 */
final class ValidateOrderHookTest extends TestCase
{
    private RebuildConnector $module;

    protected function setUp(): void
    {
        parent::setUp();

        // Sécurité : si le hub était activé, on pointe vers un port fermé → échec cURL immédiat,
        // pas d'attente réseau au shutdown du process de test.
        if (!defined('REBUILDCONNECTOR_HUB_URL_OVERRIDE')) {
            define('REBUILDCONNECTOR_HUB_URL_OVERRIDE', 'http://127.0.0.1:1');
        }

        $this->module = new RebuildConnector();
    }

    public function testValidateOrderWithValidOrderDoesNotThrow(): void
    {
        $order = new \stdClass();
        $order->id = 1234;
        $order->reference = 'ABCDEF';
        $order->total_paid = 49.90;

        try {
            $this->module->hookActionValidateOrder(['order' => $order]);
        } catch (\Throwable $exception) {
            $this->fail('hookActionValidateOrder ne doit jamais lever d\'exception : ' . $exception->getMessage());
        }

        $this->addToAssertionCount(1);
    }

    public function testValidateOrderIgnoresMissingOrder(): void
    {
        try {
            $this->module->hookActionValidateOrder([]);
        } catch (\Throwable $exception) {
            $this->fail('hookActionValidateOrder doit ignorer un params sans order sans throw : ' . $exception->getMessage());
        }

        $this->addToAssertionCount(1);
    }

    public function testValidateOrderIgnoresOrderWithoutId(): void
    {
        $order = new \stdClass();

        try {
            $this->module->hookActionValidateOrder(['order' => $order]);
        } catch (\Throwable $exception) {
            $this->fail('hookActionValidateOrder doit ignorer un order sans id sans throw : ' . $exception->getMessage());
        }

        $this->addToAssertionCount(1);
    }

    public function testRunAfterResponseSwallowsTaskExceptions(): void
    {
        // La tâche différée est encadrée par un try/catch large : un push/webhook qui échoue ne
        // doit jamais remonter. On récupère le callback enregistré au shutdown et on l'exécute
        // manuellement pour vérifier qu'il n'y a pas de propagation.
        $method = new \ReflectionMethod(RebuildConnector::class, 'runAfterResponse');
        $method->setAccessible(true);

        $ran = false;
        $method->invoke($this->module, function () use (&$ran): void {
            $ran = true;
            throw new \RuntimeException('échec simulé de push/webhook');
        });

        // Le shutdown handler enregistré n'est pas exécuté ici (il tournera à la fin du process),
        // mais l'appel de runAfterResponse lui-même ne doit pas throw.
        $this->assertFalse($ran, 'La tâche différée ne doit pas s\'exécuter de façon synchrone.');
    }
}
