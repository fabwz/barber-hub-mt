<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\UseCases;

use DateTimeImmutable;
use Src\Catalog\Barbershops\Application\DTOs\UpdateBarbershopFeaturesCommand;
use Src\Catalog\Barbershops\Domain\Contracts\BarbershopRepository;
use Src\Catalog\Barbershops\Domain\Exceptions\BarbershopNotFoundException;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopFeatures;
use Src\Shared\Domain\Contracts\AuditLog;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\Contracts\TransactionRunner;
use Src\Shared\Domain\ValueObjects\AuditAction;
use Src\Shared\Domain\ValueObjects\AuditEntry;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class UpdateBarbershopFeatures
{
    /** Receive the barbershop repository, the context that tells which barbershop is current, the technical record and the transaction port. */
    public function __construct(
        private BarbershopRepository $barbershops,
        private BarbershopContext $context,
        private AuditLog $audit,
        private TransactionRunner $transaction,
    ) {}

    /**
     * Switch the vouchers, the waitlist and the place in the public directory of the current barbershop on or off (RF-09, RF-54). A
     * field that does not come keeps its value.
     * The change applies to the operations that start from now on: it never cancels a voucher already bought or an offer in course.
     * The change and its entries in the technical record (SE-04) are written in one transaction: if one fails, none stays. The
     * directory has an action of its own, so a change of it is never mixed with one of the vouchers or the waitlist.
     */
    public function execute(UpdateBarbershopFeaturesCommand $command, string $actorUserId, DateTimeImmutable $now): BarbershopFeatures
    {
        $barbershop = $this->barbershops->find($this->context->current()) ?? throw BarbershopNotFoundException::create();
        $current = $barbershop->features;

        $features = BarbershopFeatures::of(
            $command->vouchersEnabled ?? $current->vouchersEnabled,
            $command->waitlistEnabled ?? $current->waitlistEnabled,
            $command->listedInDirectory ?? $current->listedInDirectory,
        );

        $this->transaction->run(function () use ($barbershop, $current, $features, $actorUserId, $now): void {
            $this->barbershops->save($barbershop->withFeatures($features));

            $actor = new UserId($actorUserId);
            $entries = [
                AuditEntry::of($actor, AuditAction::FeaturesUpdated, 'barbershop', $barbershop->id->value, self::snapshot($current), self::snapshot($features), $now),
                AuditEntry::of($actor, AuditAction::DirectoryListingUpdated, 'barbershop', $barbershop->id->value, self::snapshot($current), self::snapshot($features), $now),
            ];

            foreach ($entries as $entry) {
                if ($entry !== null) {
                    $this->audit->record($entry);
                }
            }
        });

        return $features;
    }

    /**
     * The switches as the technical record names them; each action keeps only the ones it allows.
     *
     * @return array<string, bool>
     */
    private static function snapshot(BarbershopFeatures $features): array
    {
        return [
            'vouchers_enabled' => $features->vouchersEnabled,
            'waitlist_enabled' => $features->waitlistEnabled,
            'listed_in_directory' => $features->listedInDirectory,
        ];
    }
}
