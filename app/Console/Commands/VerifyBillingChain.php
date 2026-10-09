<?php

namespace App\Console\Commands;

use App\Domain\Billing\Issuing\InvoicingAccess;
use App\Domain\Billing\Issuing\RecordChain;
use App\Models\User;
use App\Notifications\Billing\BillingChainBroken;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Comprueba el registro de facturación de la emisión propia (PLAN-EMISION §4.3; D-429): la cadena de
 * cada instalación (orden sin huecos, enlace con el anterior y cada huella recalculada desde sus
 * campos), que cada factura emitida tenga su registro con sus datos y la correlatividad de cada serie
 * y año. Sale con error si algo no cuadra. Con --nightly (cada noche, después de la copia) avisa a
 * los admins que usan la emisión (obligatorio, en la app y por email).
 */
#[Signature('app:billing-verify-chain {--nightly : Avisa a los admins si algo no cuadra}')]
#[Description('Comprueba que el registro de facturación (cadena y huellas) y la numeración no se han alterado')]
class VerifyBillingChain extends Command
{
    public function handle(RecordChain $chain): int
    {
        $result = $chain->verify();

        activity('invoicing')
            ->event('chain_verified')
            ->withProperties(['ok' => $result['ok'], 'records' => $result['records'], 'documents' => $result['documents'], 'problems' => array_slice($result['problems'], 0, 20)])
            ->log('invoicing.chain_verified');

        if ($result['ok']) {
            $this->info((string) __('invoicing.chain.ok', ['records' => $result['records'], 'documents' => $result['documents']]));

            return self::SUCCESS;
        }

        foreach ($result['problems'] as $problem) {
            $this->error($problem);
        }

        if ($this->option('nightly')) {
            $admins = User::query()->active()->role('admin')->get()->filter(fn (User $admin): bool => InvoicingAccess::uses($admin));
            foreach ($admins as $admin) {
                $admin->notify(new BillingChainBroken($result['problems']));
            }
        }

        return self::FAILURE;
    }
}
