<?php

namespace Dtc\QueueBundle\Manager;

use Dtc\QueueBundle\Model\Job;

interface JobManagerInterface
{
    public function resetExceptionJobs($workerName = null, $methodName = null);

    public function pruneExceptionJobs($workerName = null, $methodName = null);

    /**
     * Prunes (or archived) jobs that are expired.
     *
     * @return mixed
     */
    public function pruneExpiredJobs($workerName = null, $methodName = null);

    public function getJobCount($workerName = null, $methodName = null);

    public function getStatus();

    public function getJob($workerName = null, $methodName = null, $prioritize = true, $runId = null);

    public function deleteJob(Job $job);

    public function save(Job $job);

    /**
     * Called after a job has finished - may delete the job / reset the job and/or do other related cleanup.
     *
     * @param Job $job
     */
    public function saveHistory(Job $job);

    public function resetStalledJobs($workerName = null, $method = null);

    public function pruneStalledJobs($workerName = null, $method = null);

    /**
     * @return JobTimingManager
     */
    public function getJobTimingManager();

    public function getJobClass();

    /**
     * Removes archived jobs older than $olderThan.
     *
     * @param \DateTime $olderThan
     */
    public function pruneArchivedJobs(\DateTime $olderThan);
}
