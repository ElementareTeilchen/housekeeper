<?php

declare(strict_types=1);

namespace Elementareteilchen\Housekeeper\Command;

use Elementareteilchen\Housekeeper\Helper\CommandHelper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Abstract base class for all housekeeper commands
 *
 * Provides common functionality for command initialization, error handling,
 * storage management, and file operations that are shared across all commands.
 */
abstract class AbstractCommand extends Command
{

    /**
     * Dry run flag
     */
    protected bool $dryRun = false;

    /**
     * Symfony style interface for command output
     */
    protected SymfonyStyle $io;

    /**
     * Constructor
     *
     * @param ResourceFactory $resourceFactory TYPO3 resource factory service
     * @param StorageRepository $storageRepository TYPO3 storage repository service
     */
    public function __construct(
        protected readonly ResourceFactory $resourceFactory,
        protected readonly StorageRepository $storageRepository
    ) {
        parent::__construct();
    }

    /**
     * Add common options used by all commands
     */
    protected function addCommonOptions(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only pretend operations');
    }

    /**
     * Initialize common command properties
     *
     * Sets up the SymfonyStyle IO interface, title, and common options like dry-run
     *
     * @param InputInterface $input Command input interface
     * @param OutputInterface $output Command output interface
     */
    protected function initializeCommand(InputInterface $input, OutputInterface $output): void
    {
        $this->initializeUserSession();

        $this->io = new SymfonyStyle($input, $output);
        $this->io->title($this->getDescription());

        $this->dryRun = (bool)$input->getOption('dry-run');
    }

    /**
     * Initialize backend user session correctly for the commands to function properly.
     *
     * We need a 'proper' session in CLI context, since ExtendedFileUtility attempts to write flash messages into the
     * session storage.
     * We also must not overwrite an already existing session, if the command is executed from the BE scheduler module,
     * otherwise the current BE user will be logged out.
     */
    protected function initializeUserSession(): void
    {

        if (!isset($GLOBALS['BE_USER'])) {
            throw new \RuntimeException('Existing backend user authentication expected', 1768989261);
        }

        $userSession = null;
        try {
            $userSession = $GLOBALS['BE_USER']->getSession();
        } catch (\TypeError $e) {
            // Workaround for core bug:
            // AbstractUserAuthentication::userSession might be null, but AbstractUserAuthentication::getSession() is
            // expected to return a UserSession type, instead of a ?UserSession. So the function call fails, if no user
            // session exists.
        }

        if ($userSession === null) {
            $GLOBALS['BE_USER']->initializeUserSessionManager();
        }
    }

    /**
     * Handle exceptions that occur during command execution
     *
     * Formats and displays the exception information to the user
     *
     * @param \Throwable $exception Exception that occurred
     * @return int Command error status code
     */
    protected function handleException(\Throwable $exception): int
    {
        $this->io->error($exception->getMessage());
        if ($this->io->isVerbose()) {
            $this->io->writeln('<comment>' . $exception->getTraceAsString() . '</comment>');
        }
        return Command::FAILURE;
    }

    /**
     * Get a file or folder object from an identifier
     *
     * @param string $identifier File or folder identifier
     * @return object File or folder object
     */
    protected function getFileObject($identifier)
    {
        return CommandHelper::getFileObject($this->resourceFactory, $identifier);
    }
}
