<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\UseCases;

use Src\Catalog\Collaborators\Application\AttachAccounts;
use Src\Catalog\Collaborators\Application\Contracts\BarbershopSchedule;
use Src\Catalog\Collaborators\Application\DTOs\CollaboratorWithAccount;
use Src\Catalog\Collaborators\Application\DTOs\ReplaceScheduleCommand;
use Src\Catalog\Collaborators\Application\DTOs\ScheduleEntry;
use Src\Catalog\Collaborators\Domain\Contracts\CollaboratorRepository;
use Src\Catalog\Collaborators\Domain\Exceptions\CollaboratorNotFoundException;
use Src\Catalog\Collaborators\Domain\Exceptions\ScheduleEntryException;
use Src\Catalog\Collaborators\Domain\Exceptions\ShiftOutsideOpeningHoursException;
use Src\Catalog\Collaborators\Domain\Services\RangesCover;
use Src\Catalog\Collaborators\Domain\ValueObjects\BreakPeriod;
use Src\Catalog\Collaborators\Domain\ValueObjects\CollaboratorId;
use Src\Catalog\Collaborators\Domain\ValueObjects\Shift;
use Src\Catalog\Collaborators\Domain\ValueObjects\WorkSchedule;
use Src\Shared\Domain\ValueObjects\DayOfWeek;
use Src\Shared\Domain\ValueObjects\TimeOfDay;
use Src\Shared\Domain\ValueObjects\TimeRange;

final readonly class ReplaceSchedule
{
    /** Receive the collaborator repository and the barbershop schedule ports. */
    public function __construct(
        private CollaboratorRepository $collaborators,
        private BarbershopSchedule $barbershopSchedule,
        private AttachAccounts $attachAccounts,
    ) {}

    /**
     * Replace the weekly shifts and breaks of a collaborator. Every shift must fall inside the opening hours of the
     * barbershop that day, every break inside a shift, and neither shifts nor breaks may overlap (HO-02).
     *
     * @throws CollaboratorNotFoundException
     * @throws ScheduleEntryException
     */
    public function execute(ReplaceScheduleCommand $command): CollaboratorWithAccount
    {
        $collaborator = $this->collaborators->find(new CollaboratorId($command->collaboratorId))
            ?? throw CollaboratorNotFoundException::create();

        $shifts = array_map(fn (ScheduleEntry $entry): Shift => new Shift(...$this->dayAndRange($entry)), $command->shifts);
        $breaks = array_map(fn (ScheduleEntry $entry): BreakPeriod => new BreakPeriod(...$this->dayAndRange($entry)), $command->breaks);

        foreach ($shifts as $index => $shift) {
            if (! RangesCover::covers($this->barbershopSchedule->openRangesOn($shift->day), $shift->range)) {
                throw ShiftOutsideOpeningHoursException::at($index);
            }
        }

        $collaborator->work(WorkSchedule::of($shifts, $breaks));
        $this->collaborators->save($collaborator);

        return $this->attachAccounts->toOne($collaborator);
    }

    /**
     * Turn an entry into its day and range.
     *
     * @return array{day: DayOfWeek, range: TimeRange}
     */
    private function dayAndRange(ScheduleEntry $entry): array
    {
        return [
            'day' => new DayOfWeek($entry->day),
            'range' => TimeRange::between(new TimeOfDay($entry->startsAt), new TimeOfDay($entry->endsAt)),
        ];
    }
}
