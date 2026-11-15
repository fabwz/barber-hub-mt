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
     * Switch the vouchers and the waitlist of the current barbershop on or off (RF-09). A field that does not come keeps its value.
     * The change applies to the operations that start from now on: it never cancels a voucher already bought or an offer in course.
     * The change and its entry in the technical record (SE-04) are written in one transaction: if one fails, none stays.
     */
    public function execute(UpdateBarbershopFeaturesCommand $command, string $actorUserId, DateTimeImmutable $now): BarbershopFeatures
    {
        $barbershop = $this->barbershops->find($this->context->current()) ?? throw BarbershopNotFoundException::create();
        $current = $barbershop->features;

        $features = BarbershopFeatures::of(
            $command->vouchersEnabled ?? $current->vouchersEnabled,
            $command->waitlistEnabled ?? $current->waitlistEnabled,
        );

        $this->transaction->run(function () use ($barbershop, $current, $features, $actorUserId, $now): void {
            $this->barbershops->save($barbershop->withFeatures($features));

            $entry = AuditEntry::of(
                new UserId($actorUserId),
                AuditAction::FeaturesUpdated,
                'barbershop',
                $barbershop->id->value,
                self::snapshot($current),
                self::snapshot($features),
                $now,
            );

            if ($entry !== null) {
                $this->audit->record($entry);
            }
        });

        return $features;
    }

    /**
     * The switches as the technical record names them.
     *
     * @return array<string, bool>
     */
    private static function snapshot(BarbershopFeatures $features): array
    {
        return ['vouchers_enabled' => $features->vouchersEnabled, 'waitlist_enabled' => $features->waitlistEnabled];
    }
}
