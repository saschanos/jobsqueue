<?php

/*
 * This file is part of the Active Collab Jobs Queue.
 *
 * (c) A51 doo <info@activecollab.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace ActiveCollab\JobsQueue\Test;

use ActiveCollab\JobsQueue\Jobs\Job;
use ActiveCollab\JobsQueue\Queue\MySqlQueue;
use ActiveCollab\JobsQueue\Queue\QueueInterface;
use ActiveCollab\JobsQueue\Test\Jobs\Failing;
use ActiveCollab\JobsQueue\Test\Jobs\Inc;
use DateTime;

/**
 * @package ActiveCollab\JobsQueue\Test
 */
class MySqlQueueTest extends AbstractMySqlQueueTest
{
    /**
     * Test if job queue table is prepared for testing.
     */
    public function testJobsQueueTableIsCreated()
    {
        $this->assertContains(MySqlQueue::JOBS_TABLE_NAME, $this->connection->getTableNames());
    }

    /**
     * Test jobs are added to the queue.
     */
    public function testJobsAreAddedToTheQueue()
    {
        $this->assertRecordsCount(0);

        $this->assertEquals(1, $this->dispatcher->dispatch(new Inc(['number' => 123])));
        $this->assertEquals(2, $this->dispatcher->dispatch(new Inc(['number' => 456])));
        $this->assertEquals(3, $this->dispatcher->dispatch(new Inc(['number' => 789])));

        $this->assertRecordsCount(3);
    }

    /**
     * Test jobs can be removed from queue.
     */
    public function testJobsAreRemovedFromTheQueue()
    {
        $job_id = $this->dispatcher->dispatch(new Inc(['number' => 1245]));
        $this->assertEquals(1, $job_id);

        $this->dispatcher->dispatch(new Inc(['number' => 1245]));
        $this->dispatcher->dispatch(new Inc(['number' => 1245]));

        $this->assertEquals(3, $this->dispatcher->getQueue()->count());
        $this->dispatcher->getQueue()->dequeue(1);
        $this->assertEquals(2, $this->dispatcher->getQueue()->count());
    }

    /**
     * Check if there will be no exception if we try to dequeue a job that doesn't exist.
     */
    public function testDequeueNoExceptionOnMissingJob()
    {
        $this->assertEquals(0, $this->dispatcher->getQueue()->count());
        $this->dispatcher->getQueue()->dequeue(12345);
    }

    /**
     * Test if jobs by be removed from the queue by type.
     */
    public function testDequeueByTypeRemovesJobsOfSpecificType()
    {
        $this->dispatcher->dispatch(new Inc(['number' => 1245]));
        $this->dispatcher->dispatch(new Failing());

        $this->assertEquals(2, $this->dispatcher->getQueue()->count());
        $this->dispatcher->getQueue()->dequeueByType(Inc::class);
        $this->assertEquals(1, $this->dispatcher->getQueue()->count());
    }

    /**
     * Test count jobs.
     */
    public function testCountJobs()
    {
        $this->assertRecordsCount(0);

        $this->assertEquals(1, $this->dispatcher->dispatch(new Inc(['number' => 123])));
        $this->assertEquals(2, $this->dispatcher->dispatch(new Inc(['number' => 456])));
        $this->assertEquals(3, $this->dispatcher->dispatch(new Inc(['number' => 789])));

        $this->assertEquals(3, $this->dispatcher->getQueue()->count());
    }

    /**
     * Test count jobs by type.
     */
    public function testCountJobsByType()
    {
        $this->assertRecordsCount(0);

        $this->assertEquals(1, $this->dispatcher->dispatch(new Inc(['number' => 123])));
        $this->assertEquals(2, $this->dispatcher->dispatch(new Inc(['number' => 456])));
        $this->assertEquals(3, $this->dispatcher->dispatch(new Inc(['number' => 789])));

        $this->assertEquals(3, $this->dispatcher->getQueue()->countByType('ActiveCollab\JobsQueue\Test\Jobs\Inc'));
        $this->assertEquals(3, $this->dispatcher->getQueue()->countByType('ActiveCollab\JobsQueue\Test\Jobs\Failing', 'ActiveCollab\JobsQueue\Test\Jobs\Inc'));
        $this->assertEquals(0, $this->dispatcher->getQueue()->countByType('ActiveCollab\JobsQueue\Test\Jobs\Failing'));
    }

    /**
     * Make sure that full job class is recorded.
     */
    public function testFullJobClassIsRecorded()
    {
        $this->assertEquals(1, $this->dispatcher->dispatch(new Inc(['number' => 123])));

        $job = $this->connection->executeFirstRow('SELECT * FROM `' . MySqlQueue::JOBS_TABLE_NAME . '` WHERE id = ?', 1);

        $this->assertInternalType('array', $job);
        $this->assertEquals('ActiveCollab\JobsQueue\Test\Jobs\Inc', $job['type']);
    }

    /**
     * Test if channel is properly set.
     */
    public function testChannelIsSet()
    {
        $this->assertEquals(1, $this->dispatcher->dispatch(new Inc(['number' => 123])));

        $job = $this->connection->executeFirstRow('SELECT * FROM `' . MySqlQueue::JOBS_TABLE_NAME . '` WHERE id = ?', 1);

        $this->assertInternalType('array', $job);
        $this->assertEquals(QueueInterface::MAIN_CHANNEL, $job['channel']);
    }

    /**
     * Test if priority is properly set.
     */
    public function testPriorityIsProperlySetFromData()
    {
        $this->assertEquals(1, $this->dispatcher->dispatch(new Inc(['number' => 123, 'priority' => Job::HAS_HIGHEST_PRIORITY])));

        $result = $this->link->query('SELECT * FROM `' . MySqlQueue::JOBS_TABLE_NAME . '` WHERE id = 1');
        $this->assertInstanceOf('mysqli_result', $result);
        $this->assertEquals(1, $result->num_rows);

        $row = $result->fetch_assoc();

        $this->assertArrayHasKey('priority', $row);
        $this->assertEquals((string) Job::HAS_HIGHEST_PRIORITY, $row['priority']);
    }

    /**
     * Test job data is properly serialized to JSON.
     */
    public function testJobDataIsSerializedToJson()
    {
        $this->assertEquals(1, $this->dispatcher->dispatch(new Inc(['number' => 123])));

        $result = $this->link->query('SELECT * FROM `' . MySqlQueue::JOBS_TABLE_NAME . '` WHERE id = 1');
        $this->assertInstanceOf('mysqli_result', $result);
        $this->assertEquals(1, $result->num_rows);

        $row = $result->fetch_assoc();

        $this->assertArrayHasKey('data', $row);
        $this->assertStringStartsWith('{', $row['data']);
        $this->assertStringEndsWith('}', $row['data']);

        $decoded_data = json_decode($row['data'], true);
        $this->assertInternalType('array', $decoded_data);

        $this->assertArrayHasKey('number', $decoded_data);
        $this->assertEquals(123, $decoded_data['number']);
        $this->assertArrayHasKey('priority', $decoded_data);
        $this->assertEquals(Job::NOT_A_PRIORITY, $decoded_data['priority']);
    }

    /**
     * Test if invalid JSON data is treated as a reason for job to fail.
     */
    public function testJobDataCanBeBrokenJson()
    {
        $this->assertEquals(1, $this->dispatcher->dispatch(new Inc(['number' => 123])));

        $this->connection->execute('UPDATE `jobs_queue` SET `data` = ? WHERE `id` = ?', 'broken JSON', 1);

        $job = $this->dispatcher->getQueue()->getJobById(1);

        $this->assertNull($job);
        $this->assertEquals(0, $this->connection->executeFirstCell('SELECT COUNT(`id`) AS "row_count" FROM `jobs_queue`'));
        $this->assertEquals(1, $this->connection->executeFirstCell('SELECT COUNT(`id`) AS "row_count" FROM `jobs_queue_failed`'));
        $this->assertContains('Failed to parse JSON', $this->connection->executeFirstCell('SELECT `reason` FROM `jobs_queue_failed` WHERE `id` = ?', 1));
    }

    /**
     * Test check for existing job.
     */
    public function testCheckForExistingJob()
    {
        $this->assertEquals(1, $this->dispatcher->dispatch(new Inc(['number' => 123, 'extra' => true])));

        $this->assertFalse($this->dispatcher->exists('ActiveCollab\\JobsQueue\\Test\\Jobs\\Something'));
        $this->assertTrue($this->dispatcher->exists('ActiveCollab\\JobsQueue\\Test\\Jobs\\Inc'));
    }

    /**
     * Test check for existing job.
     */
    public function testCheckForExistingJobWithMatchigProperties()
    {
        $this->assertEquals(1, $this->dispatcher->dispatch(new Inc(['number' => 123, 'extra' => true])));

        $this->assertTrue($this->dispatcher->exists('ActiveCollab\\JobsQueue\\Test\\Jobs\\Inc', ['number' => 123]));
        $this->assertTrue($this->dispatcher->exists('ActiveCollab\\JobsQueue\\Test\\Jobs\\Inc', ['number' => 123, 'extra' => true]));
        $this->assertFalse($this->dispatcher->exists('ActiveCollab\\JobsQueue\\Test\\Jobs\\Inc', ['number' => '123']));
        $this->assertFalse($this->dispatcher->exists('ActiveCollab\\JobsQueue\\Test\\Jobs\\Inc', ['number' => 123, 'extra' => false]));
        $this->assertFalse($this->dispatcher->exists('ActiveCollab\\JobsQueue\\Test\\Jobs\\Inc', ['number' => 234]));
    }

    /**
     * Test if new jobs are instantly available.
     */
    public function testNewJobsAreAvailableInstantly()
    {
        $this->assertEquals(1, $this->dispatcher->dispatch(new Inc(['number' => 123])));

        $result = $this->link->query('SELECT * FROM `' . MySqlQueue::JOBS_TABLE_NAME . '` WHERE id = 1');
        $this->assertInstanceOf('mysqli_result', $result);
        $this->assertEquals(1, $result->num_rows);

        $row = $result->fetch_assoc();

        $this->assertArrayHasKey('available_at', $row);
        $this->assertEquals(time(), strtotime($row['available_at']));
    }

    /**
     * Test new jobs can be delayed by a specified number of seconds.
     */
    public function testNewJobsCanBeDelayed()
    {
        $this->assertEquals(1, $this->dispatcher->dispatch(new Inc(['number' => 123, 'delay' => 5])));

        /** @var DateTime $available_at */
        $available_at = $this->connection->executeFirstCell('SELECT `available_at` FROM `' . MySqlQueue::JOBS_TABLE_NAME . '` WHERE `id` = ?', 1);

        $this->assertInstanceOf('DateTime', $available_at);
        $this->assertGreaterThan(time(), $available_at->getTimestamp());
    }

    /**
     * Test if we can use first_attempt_delay to set a delay of the first attempt.
     */
    public function testNewJobsCanBeDelayedWithFirstAttemptExecutedNow()
    {
        $inc_job_with_no_first_attempt_delay = new Inc([
            'number' => 123,
            'delay' => 5,
            'first_attempt_delay' => 0,
        ]);

        $this->assertEquals(5, $inc_job_with_no_first_attempt_delay->getDelay());
        $this->assertEquals(0, $inc_job_with_no_first_attempt_delay->getFirstJobDelay());

        $this->assertEquals(1, $this->dispatcher->dispatch($inc_job_with_no_first_attempt_delay));

        /** @var DateTime $available_at */
        $available_at = $this->connection->executeFirstCell('SELECT `available_at` FROM `' . MySqlQueue::JOBS_TABLE_NAME . '` WHERE `id` = ?', 1);

        $this->assertInstanceOf('DateTime', $available_at);
        $this->assertLessThanOrEqual(time(), $available_at->getTimestamp());
    }

    /**
     * Test that jobs are not reserved by default.
     */
    public function testJobsAreNotReservedByDefault()
    {
        $this->assertEquals(1, $this->dispatcher->dispatch(new Inc(['number' => 123])));

        $result = $this->link->query('SELECT * FROM `' . MySqlQueue::JOBS_TABLE_NAME . '` WHERE id = 1');
        $this->assertInstanceOf('mysqli_result', $result);
        $this->assertEquals(1, $result->num_rows);

        $row = $result->fetch_assoc();

        $this->assertArrayHasKey('reservation_key', $row);
        $this->assertNull($row['reservation_key']);

        $this->assertArrayHasKey('reserved_at', $row);
        $this->assertNull($row['reserved_at']);
    }

    /**
     * Test that jobs start with zero attempts.
     */
    public function testAttemptsAreZeroByDefault()
    {
        $this->assertEquals(1, $this->dispatcher->dispatch(new Inc(['number' => 123])));

        $result = $this->link->query('SELECT * FROM `' . MySqlQueue::JOBS_TABLE_NAME . '` WHERE id = 1');
        $this->assertInstanceOf('mysqli_result', $result);
        $this->assertEquals(1, $result->num_rows);

        $row = $result->fetch_assoc();

        $this->assertArrayHasKey('attempts', $row);
        $this->assertEquals('0', $row['attempts']);
    }

    /**
     * Test next in line when no priority is set (FIFO).
     */
    public function testNextInLineReturnsNullOnNoJobs()
    {
        $this->assertRecordsCount(0);
        $this->assertNull($this->dispatcher->getQueue()->nextInLine());
    }

    /**
     * Test next in line when no priority is set (FIFO).
     */
    public function testNextInLine()
    {
        $this->assertRecordsCount(0);

        $this->assertEquals(1, $this->dispatcher->dispatch(new Inc(['number' => 123])));
        $this->assertEquals(2, $this->dispatcher->dispatch(new Inc(['number' => 456])));

        $this->assertRecordsCount(2);

        $next_in_line = $this->dispatcher->getQueue()->nextInLine();

        $this->assertInstanceOf(Inc::class, $next_in_line);
        $this->assertEquals(1, $next_in_line->getQueueId());
    }

    /**
     * @dataProvider provideInvalidJobsToRunValues
     * @param mixed $jobs_to_run
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Jobs to run needs to be a number larger than zero
     */
    public function testExceptionOnInvalidJobsToRun($jobs_to_run)
    {
        $this->dispatcher->getQueue()->nextBatchInLine($jobs_to_run);
    }

    public function provideInvalidJobsToRunValues()
    {
        return [
            [-1],
            [0],
            [null],
            ['string']
        ];
    }

    /**
     * Test next batch in line when no priority is set (FIFO).
     */
    public function testNextBatchInLine()
    {
        $this->assertRecordsCount(0);

        $this->assertEquals(1, $this->dispatcher->dispatch(new Inc(['number' => 123])));
        $this->assertEquals(2, $this->dispatcher->dispatch(new Inc(['number' => 456])));
        $this->assertEquals(3, $this->dispatcher->dispatch(new Inc(['number' => 789])));
        $this->assertEquals(4, $this->dispatcher->dispatch(new Inc(['number' => 135])));

        $this->assertRecordsCount(4);

        $batch_of_tasks = $this->dispatcher->getQueue()->nextBatchInLine(3);

        $this->assertInternalType('array', $batch_of_tasks);
        $this->assertCount(3, $batch_of_tasks);

        $this->assertEquals(1, $batch_of_tasks[0]->getQueueId());
        $this->assertEquals(2, $batch_of_tasks[1]->getQueueId());
        $this->assertEquals(3, $batch_of_tasks[2]->getQueueId());
    }

    /**
     * Test next in line when no priority is set (FIFO).
     */
    public function testNextBatchInLineReturnsAnEmptyArrayOnNoJobs()
    {
        $this->assertRecordsCount(0);

        $batch_of_jobs = $this->dispatcher->getQueue()->nextBatchInLine(10);

        $this->assertInternalType('array', $batch_of_jobs);
        $this->assertCount(0, $batch_of_jobs);
    }

    /**
     * Test if nextInLine works fine when another worker process "snatches" the job (returns NULL).
     */
    public function testJobSnatching()
    {
        $this->assertEquals(0, $this->connection->executeFirstCell('SELECT COUNT(`id`) AS "row_count" FROM `jobs_queue`'));

        $snatch_reservation_key = sha1('something completely random');
        $captured_job_id = 0;
        $captured_reservation_key = '';

        // Simulate job snatching done by a different worker process
        $this->dispatcher->getQueue()->onReservationKeyReady(function ($job_id, $reservation_key) use ($snatch_reservation_key, &$captured_job_id, &$captured_reservation_key) {
            $captured_job_id = $job_id;
            $captured_reservation_key = $reservation_key;

            $this->connection->execute('UPDATE `jobs_queue` SET `reservation_key` = ?, `reserved_at` = ? WHERE `id` = ? AND `reservation_key` IS NULL', $snatch_reservation_key, date('Y-m-d H:i:s'), $job_id);
        });

        $this->assertEquals(1, $this->dispatcher->dispatch(new Inc(['number' => 123])));

        $next_in_line = $this->dispatcher->getQueue()->nextInLine();

        $this->assertSame(1, $captured_job_id);
        $this->assertEquals(40, strlen($captured_reservation_key));
        $this->assertNotEquals($snatch_reservation_key, $captured_reservation_key);

        $this->assertEquals(1, $this->connection->executeFirstCell('SELECT COUNT(`id`) AS "row_count" FROM `jobs_queue`'));
        $this->assertEquals($snatch_reservation_key, $this->connection->executeFirstCell('SELECT `reservation_key` FROM `jobs_queue` WHERE `id` = ?', $captured_job_id));
        $this->assertNull($next_in_line);
    }

    /**
     * Test if queue instance is properly set.
     */
    public function testJobGetsQueueProperlySet()
    {
        $this->assertEquals(1, $this->dispatcher->dispatch(new Inc(['number' => 123])));
        $this->assertRecordsCount(1);

        $next_in_line = $this->dispatcher->getQueue()->nextInLine();

        $this->assertInstanceOf('ActiveCollab\JobsQueue\Test\Jobs\Inc', $next_in_line);

        $this->assertInstanceOf('ActiveCollab\JobsQueue\Queue\MySqlQueue', $next_in_line->getQueue());
        $this->assertEquals(1, $next_in_line->getQueueId());
    }

    /**
     * Test priority tasks are front in line.
     */
    public function testPriorityJobsAreFrontInLine()
    {
        $this->assertRecordsCount(0);

        $this->assertEquals(1, $this->dispatcher->dispatch(new Inc(['number' => 123])));
        $this->assertEquals(2, $this->dispatcher->dispatch(new Inc(['number' => 456, 'priority' => Job::HAS_PRIORITY])));
        $this->assertEquals(3, $this->dispatcher->dispatch(new Inc(['number' => 789, 'priority' => Job::HAS_PRIORITY])));

        $this->assertRecordsCount(3);

        $next_in_line = $this->dispatcher->getQueue()->nextInLine();

        $this->assertInstanceOf('ActiveCollab\JobsQueue\Test\Jobs\Inc', $next_in_line);
        $this->assertEquals(2, $next_in_line->getQueueId());
    }

    /**
     * Test if job execution removes it from the queue.
     */
    public function testExecuteJobRemovesItFromQueue()
    {
        $this->assertRecordsCount(0);

        $this->assertEquals(1, $this->dispatcher->dispatch(new Inc(['number' => 123])));

        $next_in_line = $this->dispatcher->getQueue()->nextInLine();

        $this->assertInstanceOf('ActiveCollab\JobsQueue\Test\Jobs\Inc', $next_in_line);
        $this->assertEquals(1, $next_in_line->getQueueId());

        $job_execution_result = $this->dispatcher->getQueue()->execute($next_in_line);
        $this->assertEquals(124, $job_execution_result);

        $this->assertRecordsCount(0);
    }

    /**
     * Test if execute suppresses exceptions by default.
     */
    public function testExecuteIsSilencedByDefault()
    {
        $this->dispatcher->getQueue()->execute(new Failing());
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Built to fail!
     */
    public function testExecuteThrowsExceptionWhenNotSilenced()
    {
        $this->dispatcher->getQueue()->execute(new Failing(), false);
    }
}
