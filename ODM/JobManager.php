<?php

namespace Dtc\QueueBundle\ODM;

use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Dtc\QueueBundle\Model\JobManagerInterface;
use Doctrine\ODM\MongoDB\DocumentRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Dtc\QueueBundle\Document\Job;
use Dtc\QueueBundle\Util\Util;

class JobManager implements JobManagerInterface
{
    protected $documentManager;
    protected $documentName;
    protected $archiveDocumentName;

    public function __construct(DocumentManager $documentManager, $documentName, $archiveDocumentName)
    {
        $this->documentManager = $documentManager;
        $this->documentName = $documentName;
        $this->archiveDocumentName = $archiveDocumentName;
    }

    /**
     * @return DocumentManager
     */
    public function getDocumentManager()
    {
        return $this->documentManager;
    }

    /**
     * @return string
     */
    public function getDocumentName()
    {
        return $this->documentName;
    }

    /**
     * @return string
     */
    public function getArchiveDocumentName()
    {
        return $this->archiveDocumentName;
    }

    /**
     * @return DocumentRepository
     */
    public function getRepository()
    {
        return $this->getDocumentManager()->getRepository($this->getDocumentName());
    }

    public function resetErroneousJobs($workerName = null, $method = null)
    {
        $archiveDocumentName = $this->getArchiveDocumentName();
        $documentManager = $this->getDocumentManager();
        $qb = $documentManager->createQueryBuilder($archiveDocumentName);
        $qb
            ->find()
            ->field('status')->equals(Job::STATUS_ERROR);

        if ($workerName) {
            $qb->field('workerName')->equals($workerName);
        }

        if ($method) {
            $qb->field('method')->equals($method);
        }

        $query = $qb->getQuery();
        $count = $query->count();

        $countProcessed = 0;
        for ($i = 0; $i < $count; $i += 100) {
            $criterion = ['status' => Job::STATUS_ERROR];
            if ($workerName) {
                $criterion['workerName'] = $workerName;
            }
            if ($method) {
                $criterion['method'] = $method;
            }
            $countProcessed += $this->resetJobsByCriterion($criterion, 100, $i);
        }

        return $countProcessed;
    }

    private function resetJobsByCriterion($criterion, $limit, $offset)
    {
        $documentManager = $this->getDocumentManager();
        $className = $this->getRepository()->getClassName();
        $archiveDocumentName = $this->getArchiveDocumentName();
        $archiveRepository = $documentManager->getRepository($archiveDocumentName);

        $metadata = $documentManager->getClassMetadata($className);
        $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_NONE);
        $identifierData = $metadata->getIdentifier();
        $idColumn = isset($identifierData[0]) ? $identifierData : 'id';
        $results = $archiveRepository->findBy($criterion, [$idColumn => 'ASC'], $limit, $offset);
        $countProcessed = 0;

        foreach ($results as $jobArchive) {
            /** @var Job $job */
            $job = new $className();
            Util::copy($jobArchive, $job);
            $job->setStatus(Job::STATUS_NEW);
            $job->setLocked(null);
            $job->setLockedAt(null);
            $job->setUpdatedAt(new \DateTime());

            // Mongo has no transactions, so there is a chance for duplicates if persisting happens
            //  but things crash on or before remove.
            try {
                $documentManager->persist($job);
            } catch (\Exception $e) {
                // @Todo - output or return a warning?
                continue;
            }
            $documentManager->remove($jobArchive);
            ++$countProcessed;
        }
        $documentManager->flush();

        return $countProcessed;
    }

    public function pruneErroneousJobs($workerName = null, $method = null)
    {
        $qb = $this->getDocumentManager()->createQueryBuilder($this->getArchiveDocumentName());
        $qb
            ->remove()
            ->field('status')->equals(Job::STATUS_ERROR);

        if ($workerName) {
            $qb->field('workerName')->equals($workerName);
        }

        if ($method) {
            $qb->field('method')->equals($method);
        }
        $query = $qb->getQuery();
        $query->execute();
    }

    /**
     * Prunes expired jobs.
     */
    public function pruneExpiredJobs()
    {
        $qb = $this->getDocumentManager()->createQueryBuilder($this->getDocumentName());
        $qb
            ->remove()
            ->field('expiresAt')->lte(new \DateTime());

        $query = $qb->getQuery();
        $query->execute();
    }

    /**
     * Removes archived jobs older than $olderThan.
     *
     * @param \DateTime $olderThan
     */
    public function pruneArchivedJobs(\DateTime $olderThan)
    {
        $qb = $this->getDocumentManager()->createQueryBuilder($this->getArchiveDocumentName());
        $qb
            ->remove()
            ->field('updatedAt')->lt($olderThan);

        $query = $qb->getQuery();
        $query->execute();
    }

    public function getJobCount($workerName = null, $method = null)
    {
        $qb = $this->getDocumentManager()->createQueryBuilder($this->getDocumentName());
        $qb
        ->find();

        if ($workerName) {
            $qb->field('workerName')->equals($workerName);
        }

        if ($method) {
            $qb->field('method')->equals($method);
        }

        // Filter
        $qb
            ->addOr($qb->expr()->field('whenAt')->equals(null))
            ->addOr($qb->expr()->field('whenAt')->lte(new \DateTime()))
            ->field('locked')->equals(null);

        $query = $qb->getQuery();

        return $query->count(true);
    }

    /**
     * Get Status Jobs.
     */
    public function _getStatusByDocument($documentName)
    {
        // Run a map reduce function get worker and status break down
        $mapFunc = "function() {
            var result = {};
            result[this.status] = 1;
            var key = this.worker_name + '->' + this.method + '()';
            emit(key, result);
        }";
        $reduceFunc = 'function(k, vals) {
            var result = {};
            for (var index in vals) {
                var val =  vals[index];
                for (var i in val) {
                    if (result.hasOwnProperty(i)) {
                        result[i] += val[i];
                    }
                    else {
                        result[i] = val[i];
                    }
                }
            }
            return result;
        }';

        $qb = $this->getDocumentManager()->createQueryBuilder($documentName)
            ->map($mapFunc)
            ->reduce($reduceFunc);
        $query = $qb->getQuery();
        $results = $query->execute();

        $allStatus = array(
            Job::STATUS_ERROR => 0,
            Job::STATUS_NEW => 0,
            Job::STATUS_SUCCESS => 0,
        );

        $status = [];

        foreach ($results as $info) {
            $status[$info['_id']] = $info['value'] + $allStatus;
        }

        return $status;
    }

    public function getStatus()
    {
        $result = $this->_getStatusByDocument($this->getDocumentName());
        $status2 = $this->_getStatusByDocument($this->getArchiveDocumentName());
        foreach ($status2 as $key => $value) {
            foreach ($value as $k => $v) {
                $result[$key][$k] += $v;
            }
        }

        $finalResult = [];
        foreach ($result as $key => $item) {
            ksort($item);
            $finalResult[$key] = $item;
        }

        return $finalResult;
    }

    /**
     * Get the next job to run (can be filtered by workername and method name).
     *
     * @param string $workerName
     * @param string $methodName
     * @param bool   $prioritize
     *
     * @return \Dtc\QueueBundle\Model\Job
     */
    public function getJob($workerName = null, $methodName = null, $prioritize = true)
    {
        $qb = $this->getDocumentManager()->createQueryBuilder($this->getDocumentName());
        $qb
            ->findAndUpdate()
            ->returnNew();

        if ($workerName) {
            $qb->field('workerName')->equals($workerName);
        }

        if ($methodName) {
            $qb->field('method')->equals($methodName);
        }

        if ($prioritize) {
            $qb->sort('priority', 'asc');
        } else {
            $qb->sort('whenAt', 'asc');
        }

        // Filter
        $date = new \DateTime();
        $qb
            ->addAnd(
                $qb->expr()->addOr($qb->expr()->field('whenAt')->equals(null), $qb->expr()->field('whenAt')->lte($date)),
                $qb->expr()->addOr($qb->expr()->field('expiresAt')->equals(null), $qb->expr()->field('expiresAt')->gt($date))
            )
            ->field('status')->equals(Job::STATUS_NEW)
            ->field('locked')->equals(null);

        // Update
        $qb
            ->field('lockedAt')->set($date) // Set started
            ->field('locked')->set(true);

        //$arr = $qb->getQueryArray();
        $query = $qb->getQuery();

        //ve($query->debug());
        $job = $query->execute();

        return $job;
    }

    public function deleteJob(\Dtc\QueueBundle\Model\Job $job)
    {
        $documentManager = $this->getDocumentManager();
        $documentManager->remove($job);
        $documentManager->flush();
    }

    public function saveHistory(\Dtc\QueueBundle\Model\Job $job)
    {
        $this->getDocumentManager()->persist($job);
        $this->deleteJob($job); // Should cause job to be archived
    }

    public function save(\Dtc\QueueBundle\Model\Job $job)
    {
        // Todo: Serialize args

        // Generate crc hash for the job
        $hashValues = array($job->getClassName(), $job->getMethod(), $job->getWorkerName(), $job->getArgs());
        $crcHash = hash('sha256', serialize($hashValues));
        $job->setCrcHash($crcHash);

        if (true === $job->getBatch()) {
            // See if similar job that hasn't run exists
            $criteria = array('crcHash' => $crcHash, 'status' => Job::STATUS_NEW);
            $oldJob = $this->getRepository()->findOneBy($criteria);

            if ($oldJob) {
                // Old job exists - just override fields Set higher priority
                $oldJob->setPriority(max($job->getPriority(), $oldJob->getPriority()));
                $oldJob->setWhenAt(min($job->getWhenAt(), $oldJob->getWhenAt()));
                $oldJob->setBatch(true);
                $oldJob->setUpdatedAt(new \DateTime());
                $this->getDocumentManager()->flush();

                return $oldJob;
            }
        }

        // Just save a new job
        $this->getDocumentManager()->persist($job);
        $this->getDocumentManager()->flush();

        return $job;
    }
}
