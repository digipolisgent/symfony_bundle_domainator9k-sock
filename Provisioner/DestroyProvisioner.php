<?php

namespace DigipolisGent\Domainator9k\SockBundle\Provisioner;

use DigipolisGent\Domainator9k\CoreBundle\Entity\ApplicationEnvironment;
use DigipolisGent\Domainator9k\CoreBundle\Entity\Task;
use DigipolisGent\Domainator9k\CoreBundle\Entity\VirtualServer;
use DigipolisGent\Domainator9k\CoreBundle\Exception\LoggedException;
use DigipolisGent\Domainator9k\CoreBundle\Provisioner\AbstractProvisioner;
use DigipolisGent\Domainator9k\CoreBundle\Service\TaskLoggerService;
use DigipolisGent\Domainator9k\SockBundle\Service\ApiService;
use DigipolisGent\SettingBundle\Service\DataValueService;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Exception\ClientException;

/**
 * Class BuildProvisioner
 *
 * @package DigipolisGent\Domainator9k\SockBundle\Provisioner
 */
class DestroyProvisioner extends AbstractProvisioner
{

    private $dataValueService;
    private $taskLoggerService;
    private $apiService;
    private $entityManager;

    /**
     * DestroyProvisioner constructor.
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(
        DataValueService $dataValueService,
        TaskLoggerService $taskLoggerService,
        ApiService $apiService,
        EntityManagerInterface $entityManager
    ) {
        $this->dataValueService = $dataValueService;
        $this->taskLoggerService = $taskLoggerService;
        $this->apiService = $apiService;
        $this->entityManager = $entityManager;
    }

    public function doRun()
    {
        $applicationEnvironment = $this->task->getApplicationEnvironment();
        $environment = $applicationEnvironment->getEnvironment();

        /** @var VirtualServer[] $servers */
        $servers = $this->entityManager->getRepository(VirtualServer::class)->findAll();

        foreach ($servers as $server) {
            if ($server->getEnvironment() != $environment) {
                continue;
            }

            if (!$this->dataValueService->getValue($server, 'manage_sock')) {
                continue;
            }

            $this->taskLoggerService->addLogHeader(
                $this->task,
                sprintf('Sock server "%s"', $server->getName())
            );

            try {
                $this->destroySockDatabase($applicationEnvironment);
                $this->destroySockApplication($applicationEnvironment);
                $this->destroySockAccount($applicationEnvironment);

                try {
                    $this->entityManager->persist($applicationEnvironment);
                    $this->entityManager->flush();
                } catch (\Exception $ex) {
                    $this->taskLoggerService->addWarningLogMessage($this->task, 'Could not remove local Sock data.');
                }

                $this->taskLoggerService->addSuccessLogMessage($this->task, 'Cleanup succeeded.');
            } catch (\Exception $ex) {
                $this->taskLoggerService->addFailedLogMessage($this->task, 'Cleanup failed.');
                throw new LoggedException('', 0, $ex);
            }
        }
    }

    /**
     * Destroy a sock database for a specific application environment.
     *
     * @param ApplicationEnvironment $applicationEnvironment
     *   The application environment to destroy the database for.
     *
     * @throws ClientException
     *   When something goes wrong while destroying the database.
     */
    protected function destroySockDatabase(ApplicationEnvironment $applicationEnvironment)
    {
        $this->taskLoggerService->addLogHeader($this->task, 'Removing database', 1);

        if (!$databaseId = $this->dataValueService->getValue($applicationEnvironment, 'sock_database_id')) {
            $this->taskLoggerService->addInfoLogMessage($this->task, 'No database to remove.', 2);
            return;
        }

        try {
            $this->apiService->removeDatabase($databaseId);

            $this->dataValueService->storeValue($applicationEnvironment, 'sock_database_id', null);

            $applicationEnvironment->setDatabaseUser(null);
            $applicationEnvironment->setDatabaseName(null);
            $applicationEnvironment->setDatabasePassword(null);

            $this->taskLoggerService->addSuccessLogMessage(
                $this->task,
                sprintf('Removed database %s.', $databaseId),
                2
            );
        } catch (\Exception $ex) {
            $this->taskLoggerService
                ->addErrorLogMessage($this->task, $ex->getMessage(), 2)
                ->addFailedLogMessage($this->task, 'Removing database failed.', 2);

            throw $ex;
        }
    }

    /**
     * Destroy a sock application for a specific application environment.
     *
     * @param ApplicationEnvironment $applicationEnvironment
     *   The application environment to destroy the application for.
     *
     * @throws ClientException
     *   When something goes wrong while destroying the application.
     */
    protected function destroySockApplication(ApplicationEnvironment $applicationEnvironment)
    {
        $this->taskLoggerService->addLogHeader($this->task, 'Removing application', 1);

        if (!$applicationId = $this->dataValueService->getValue($applicationEnvironment, 'sock_application_id')) {
            $this->taskLoggerService->addInfoLogMessage($this->task, 'No application to remove.', 2);
            return;
        }

        try {
            $this->apiService->removeApplication($applicationId);

            $this->dataValueService->storeValue($applicationEnvironment, 'sock_application_id', null);

            $this->taskLoggerService->addSuccessLogMessage(
                $this->task,
                sprintf('Removed application %s.', $applicationId),
                2
            );
        } catch (\Exception $ex) {
            $this->taskLoggerService
                ->addErrorLogMessage($this->task, $ex->getMessage(), 2)
                ->addFailedLogMessage($this->task, 'Removing database failed.', 2);

            throw $ex;
        }
    }

    /**
     * Destroy a sock account for a specific application environment.
     *
     * @param ApplicationEnvironment $applicationEnvironment
     *   The application environment to destroy the account for.
     *
     * @throws ClientException
     *   When something goes wrong while destroying the account.
     */
    protected function destroySockAccount(ApplicationEnvironment $applicationEnvironment)
    {
        $this->taskLoggerService->addLogHeader($this->task, 'Removing account', 1);

        $application = $applicationEnvironment->getApplication();

        if (!$accountId = $this->dataValueService->getValue($applicationEnvironment, 'sock_account_id')) {
            $this->taskLoggerService->addInfoLogMessage($this->task, 'No accoutn to remove.', 2);
            return;
        }

        if ($this->dataValueService->getValue($application, 'parent_application')) {
            $this->taskLoggerService->addInfoLogMessage($this->task, 'Using parent application account.', 2);
            return;
        }

        try {
            $this->apiService->removeAccount($accountId);

            $this->dataValueService->storeValue($applicationEnvironment, 'sock_account_id', null);
            $this->dataValueService->storeValue($applicationEnvironment, 'sock_ssh_user', null);

            $this->taskLoggerService->addSuccessLogMessage(
                $this->task,
                sprintf('Removed account %s.', $accountId),
                2
            );
        } catch (\Exception $ex) {
            $this->taskLoggerService
                ->addErrorLogMessage($this->task, $ex->getMessage(), 2)
                ->addFailedLogMessage($this->task, 'Removing database failed.', 2);

            throw $ex;
        }
    }

    public function getName()
    {
        return 'Sock accounts, applications and databases';
    }
}