<?php
/**
 * @category Neo
 * @package ...
 * @copyright Vendo Services, GmbH
 */

namespace App;

use App\Entity\Doctor;
use App\Entity\Slot;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

class DoctorSlotsSynchronizerTest extends TestCase
{
    /**
     * @dataProvider doctorSlotsProvider
     * @return void
     * @throws \JsonException
     */
    public function testSynchronizeDoctorSlots(string $doctorFile, string $slotsFile,
                                               int    $expectedDoctorId, array $expectedSlots)
    {
        // Mocks
        $repository = $this->createMock(EntityRepository::class);

        $em = $this->createMock(EntityManager::class);

        $em->method('getRepository')->willReturn($repository);

        $doctorSlotsSyncronizer = $this->getMockBuilder(DoctorSlotsSynchronizer::class)
            ->setConstructorArgs(['em' => $em])
            ->onlyMethods(['getDoctors', 'getSlots', 'save', 'shouldReportErrors', 'normalizeName'])
            ->getMock();

        $doctorSlotsSyncronizer->method('getDoctors')
            ->willReturn(file_get_contents(__DIR__ . '/' . $doctorFile . '.json'));

        $doctorSlotsSyncronizer->method('getSlots')
            ->willReturn(file_get_contents(__DIR__ . '/' . $slotsFile . '.json'));

        $doctorSlotsSyncronizer->method('normalizeName')->willReturn('My Name Is Normalized');

        // Expectations
        $saveArgs = [];
        $saveArgs[] = [
            self::callback(fn(Doctor $doctor) => $doctor->getId() === (string)$expectedDoctorId &&
                $doctor->getName() === 'My Name Is Normalized' && !$doctor->hasError()
            ),
        ];

        foreach ($expectedSlots as $expectedSlotStartTime) {
            $saveArgs[] = [
                self::callback(fn(Slot $slot) => $slot->getStart()
                        ->format('Y-m-d H:i:s') === $expectedSlotStartTime && !$slot->isStale()
                ),
            ];
        }

        $doctorSlotsSyncronizer->expects(self::exactly(1 + count($expectedSlots)))
            ->method('save')
            ->withConsecutive(...$saveArgs);

        // Execution
        $doctorSlotsSyncronizer->synchronizeDoctorSlots();
    }

    public function doctorSlotsProvider()
    {
        return [
            // doctors file, slots file, expected doctor ID, expected list of slots start times
            ['doctors0', 'slots0', 0, ['2020-02-01 15:00:00']],
            ['doctors1', 'slots1', 123, ['2020-01-01 12:00:01', '2020-01-01 13:00:02', '2020-01-01 14:00:03']],
        ];
    }

    /**
     * @dataProvider normalizedNamesProvider
     * @param string $fullname
     * @param string $expectedResult
     * @return void
     * @throws \ReflectionException
     */
    public function testNormalizeName(string $fullname, string $expectedResult)
    {
        $doctorSlotsSyncronizer = $this->getMockBuilder(DoctorSlotsSynchronizer::class)
            ->disableOriginalConstructor()
            ->getMock();

        $actualResult = self::callMethod($doctorSlotsSyncronizer, 'normalizeName', [$fullname]);

        self::assertEquals($expectedResult, $actualResult);
    }

    public function normalizedNamesProvider()
    {
        return [
            // fullname, expected result
            ['Doctor Joe', 'Doctor Joe'], // Name is already perfectly normal
            ['doctor joe', 'Doctor Joe'], // Names must be capitalized
            ['doctor peter joe', 'Doctor Peter Joe'], // Middle names are not an issue
            ["doctor o'toole", "Doctor O'Toole"], // Irish surnames are capitalized correctly
            //            ["doctor peter o'toole", "Doctor Peter O'Toole"], // Irish doctors with middle names should not break our algo @todo We should fix this!
            //            ["óscar gonzález lópez", "Óscar González López"], // Spanish doctors are normalized correctly @todo We should support other common character sets
            //            ["dOcToR jOe", "Doctor Joe"], // Only first letters should be capitalized @todo We should normalize harder
        ];
    }

    /**
     * Invokes a method on an object
     *
     * This can be used to invoke protected and private methods as well
     *
     * @param object $obj
     * @param string $name
     * @param array $args
     * @return mixed
     * @throws \ReflectionException
     */
    public static function callMethod($obj, $name, array $args = [])
    {
        $class = new \ReflectionClass($obj);
        $method = $class->getMethod($name);
        $method->setAccessible(true);

        return $method->invokeArgs($obj, $args);
    }
}
