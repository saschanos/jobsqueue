<?php

  namespace ActiveCollab\JobsQueue\Test;

  use ActiveCollab\JobsQueue\Test\Jobs\Failing;
  use ActiveCollab\JobsQueue\Jobs\Job;
  use Exception;

  /**
   * @package ActiveCollab\JobsQueue\Test
   */
  class OnJobFailedCallbacksTest extends AbstractMySqlQueueTest
  {
    /**
     * Test to check if we can set multiple failure callbacks
     */
    public function testExtraCallback()
    {
      $failure_count = 0;
      $failure_message = '';

      $this->dispatcher->getQueue()->onJobFailure(function(Job $job, Exception $e) use (&$failure_count, &$failure_message) {
        $failure_count++;
        $failure_message = $e->getMessage();
      });

      $this->assertRecordsCount(0);

      $this->assertEquals(1, $this->dispatcher->dispatch(new Failing()));

      $next_in_line = $this->dispatcher->getQueue()->nextInLine();

      $this->assertInstanceOf('ActiveCollab\JobsQueue\Test\Jobs\Failing', $next_in_line);
      $this->assertEquals(1, $next_in_line->getQueueId());

      $this->dispatcher->getQueue()->execute($next_in_line);

      $this->assertEquals('Built to fail!', $this->last_failure_message);
      $this->assertEquals('ActiveCollab\JobsQueue\Test\Jobs\Failing', $this->last_failed_job);

      $this->assertEquals('Built to fail!', $failure_message);
      $this->assertEquals(1, $failure_count);
    }
  }