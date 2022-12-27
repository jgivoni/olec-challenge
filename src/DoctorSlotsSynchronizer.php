<?php
declare(strict_types=1);

namespace App;

use App\Entity\Doctor;
use App\Entity\Slot;
use Carbon\Carbon;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use JsonException;
use Monolog\Logger;

class DoctorSlotsSynchronizer
{
    protected const ENDPOINT = 'http://localhost:2137/api/doctors';
    protected const USERNAME = 'docplanner';
    protected const PASSWORD = 'docplanner';

    protected EntityManagerInterface $em;
    protected Logger $logger;
    protected EntityRepository $doctorRepository;
    protected EntityRepository $slotRepository;

    public function __construct(EntityManagerInterface $em, Logger $logger)
    {
        $this->em = $em;
        $this->logger = $logger;
    }

    protected function getEntityManager(): EntityManagerInterface
    {
        return $this->em;
    }

    protected function getDoctorRepository(): EntityRepository
    {
        if (!isset($this->doctorRepository)) {
            $this->doctorRepository = $this->getEntityManager()->getRepository(Doctor::class);
        }
        return $this->doctorRepository;
    }

    protected function getSlotRepository(): EntityRepository
    {
        if (!isset($this->slotRepository)) {
            $this->slotRepository = $this->getEntityManager()->getRepository(Slot::class);
        }
        return $this->slotRepository;
    }

    /**
     */
    public function synchronizeDoctorSlots(): void
    {
        try {
            $doctorsData = $this->fetchDoctorsData();

            foreach ($doctorsData as $doctorData) {
                $doctor = $this->getDoctor($doctorData['id']);
                $doctor->setName($this->normalizeName($doctorData['name']));
                $doctor->clearError();

                $this->updateDoctorSlots($doctor);

                $this->save($doctor);
            }
        } catch (\Exception $e) {
            if ($this->shouldReportErrors()) {
                $this->logger->error('Error synchronizing doctor slots', [
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function updateDoctorSlots(Doctor $doctor): void
    {
        foreach ($this->fetchDoctorSlots($doctor->getId()) as $slot) {
            if (isset($slot)) {
                $this->save($slot);
            } else {
                $doctor->markError();
            }
        }
    }

    /**
     * @throws JsonException
     */
    protected function getJsonDecode(string $json): array
    {
        return json_decode(
            json: false === $json ? '' : $json,
            associative: true,
            depth: 16,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @throws \RuntimeException
     */
    protected function fetchData(string $url): string
    {
        $auth = base64_encode(
            sprintf(
                '%s:%s',
                self::USERNAME,
                self::PASSWORD,
            ),
        );

        $data = @file_get_contents(
            filename: $url,
            context: stream_context_create(
                [
                    'http' => [
                        'header' => 'Authorization: Basic ' . $auth,
                    ],
                ],
            ),
        );

        if ($data === false) {
            throw new \RuntimeException('Error loading data from external source');
        }

        return $data;
    }

    protected function fetchDoctorsData(): array
    {
        return $this->getJsonDecode($this->fetchData(self::ENDPOINT));
    }

    protected function fetchSlotsForDoctorData(int $doctorId): array
    {
        return $this->getJsonDecode($this->fetchData(self::ENDPOINT . '/' . $doctorId . '/slots'));
    }

    protected function getDoctor(int $doctorId): Doctor
    {
        return $this->getDoctorRepository()->find($doctorId) ??
            new Doctor($doctorId, 'New unnamed doctor');
    }

    protected function normalizeName(string $fullName): string
    {
        [, $surname] = explode(' ', $fullName);

        /** @see https://www.youtube.com/watch?v=PUhU3qCf0Nk */
        if (0 === stripos($surname, "o'")) {
            return ucwords($fullName, ' \'');
        }

        return ucwords($fullName);
    }

    protected function save(Doctor|Slot $entity): void
    {
        $em = $this->doctorRepository->createQueryBuilder('alias')->getEntityManager();
        $em->persist($entity);
        $em->flush();
    }

    /**
     * @return iterable<Slot>
     */
    protected function fetchDoctorSlots(int $doctorId): iterable
    {
        try {
            foreach ($this->fetchSlotsForDoctorData($doctorId) as $slotData) {
                yield $this->parseSlotData($slotData, $doctorId);
            };
        } catch (\Exception $e) {
            if ($this->shouldReportErrors()) {
                $this->logger->info('Error fetching slots for doctor', [
                    'doctorId' => $doctorId,
                    'exception' => $e->getMessage(),
                ]);
            }
            yield null;
        }
    }

    protected function parseSlotData(array $slotData, int $doctorId): Slot
    {
        $slot = $this->getSlot($doctorId, $slotData['start']);

        if (!$slot->isStale()) {
            $slot->setEnd(new DateTime($slotData['end']));
        }

        return $slot;
    }

    protected function getSlot(int $doctorId, string $start): Slot
    {
        $startDatetime = new DateTime($start);
        return $this->getSlotRepository()->findOneBy(['doctorId' => $doctorId, 'start' => $startDatetime]) ??
            new Slot($doctorId, $startDatetime, new DateTime());
    }

    protected function shouldReportErrors(): bool
    {
        return (new Carbon())->format('D') !== 'Sun';
    }
}
