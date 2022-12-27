<?php

declare(strict_types=1);

namespace App;

use App\Entity\Doctor;
use App\Entity\Slot;

class StaticDoctorSlotsSynchronizer extends DoctorSlotsSynchronizer
{
    public array $savedEntities = [];

    protected function fetchDoctorsData(): array
    {
        return $this->getJsonDecode(<<<JSON
[
  {
    "id": 0,
    "name": "doctor sven"
  }
]
JSON
        );
    }

    protected function fetchSlotsForDoctorData(int $id): array
    {
        return $this->getJsonDecode(<<<JSON
[
  {
    "start": "2020-02-01T15:00:00+00:00",
    "end": "2020-02-01T15:30:00+00:00"
  }
]
JSON
        );
    }

    protected function save(Doctor|Slot $entity): void
    {
        $this->savedEntities[] = $entity;
    }
}
