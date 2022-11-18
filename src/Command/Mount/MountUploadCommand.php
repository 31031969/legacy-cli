<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Mount;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Local\ApplicationFinder;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Service\Mount;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Rsync;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class MountUploadCommand extends CommandBase
{
    protected static $defaultName = 'mount:upload';
    protected static $defaultDescription = 'Upload files to a mount, using rsync';

    private $config;
    private $filesystem;
    private $finder;
    private $mountService;
    private $questionHelper;
    private $rsync;
    private $selector;
    private $ssh;

    public function __construct(
        Config $config,
        Filesystem $filesystem,
        ApplicationFinder $finder,
        Mount $mountService,
        QuestionHelper $questionHelper,
        Rsync $rsync,
        Selector $selector,
        Ssh $ssh
    ) {
        $this->config = $config;
        $this->filesystem = $filesystem;
        $this->finder = $finder;
        $this->mountService = $mountService;
        $this->questionHelper = $questionHelper;
        $this->rsync = $rsync;
        $this->selector = $selector;
        $this->ssh = $ssh;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addOption('source', null, InputOption::VALUE_REQUIRED, 'A directory containing files to upload')
            ->addOption('mount', 'm', InputOption::VALUE_REQUIRED, 'The mount (as an app-relative path)')
            ->addOption('delete', null, InputOption::VALUE_NONE, 'Whether to delete extraneous files in the mount')
            ->addOption('exclude', null, InputOption::VALUE_IS_ARRAY|InputOption::VALUE_REQUIRED, 'File(s) to exclude from the upload (pattern)')
            ->addOption('include', null, InputOption::VALUE_IS_ARRAY|InputOption::VALUE_REQUIRED, 'File(s) to include in the upload (pattern)')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache');

        $this->selector->addAllOptions($this->getDefinition(), true);
        $this->ssh->configureInput($this->getDefinition());
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
<<<<<<< HEAD
        $container = $this->selector->getSelection($input)->getRemoteContainer();
        $mounts = $this->mountService->mountsFromConfig($container->getConfig());
=======
        $this->validateInput($input);

        $container = $this->selectRemoteContainer($input);
        /** @var \Platformsh\Cli\Service\Mount $mountService */
        $mountService = $this->getService('mount');
        $mounts = $mountService->mountsFromConfig($container->getConfig());
        $sshUrl = $container->getSshUrl($input->getOption('instance'));
>>>>>>> 3.x

        if (empty($mounts)) {
            $this->stdErr->writeln(sprintf('No mounts found on host: <info>%s</info>', $sshUrl));

            return 1;
        }

        $mounts = $this->mountService->normalizeMounts($mounts);

        if ($input->getOption('mount')) {
            $mountPath = $this->mountService->matchMountPath($input->getOption('mount'), $mounts);
        } elseif ($input->isInteractive()) {
            $mountOptions = [];
            foreach ($mounts as $path => $definition) {
                if ($definition['source'] === 'local') {
                    $mountOptions[$path] = sprintf('<question>%s</question>', $path);
                } else {
                    $mountOptions[$path] = sprintf('<question>%s</question>: %s', $path, $definition['source']);
                }
            }

            $mountPath = $this->questionHelper->choose(
                $mountOptions,
                'Enter a number to choose a mount to upload to:'
            );
        } else {
            $this->stdErr->writeln('The <error>--mount</error> option must be specified (in non-interactive mode).');

            return 1;
        }

        $source = null;
        $defaultSource = null;
        if ($input->getOption('source')) {
            $source = (string) $input->getOption('source');
        } elseif ($projectRoot = $this->selector->getProjectRoot()) {
            $sharedMounts = $this->mountService->getSharedFileMounts($mounts);
            if (isset($sharedMounts[$mountPath])) {
                if (file_exists($projectRoot . '/' . $this->config->get('local.shared_dir') . '/' . $sharedMounts[$mountPath])) {
                    $defaultSource = $projectRoot . '/' . $this->config->get('local.shared_dir') . '/' . $sharedMounts[$mountPath];
                }
            }

            $applications = $this->finder->findApplications($projectRoot);
            $appPath = $projectRoot;
            foreach ($applications as $path => $candidateApp) {
                if ($candidateApp->getName() === $container->getName()) {
                    $appPath = $path;
                    break;
                }
            }
            if (is_dir($appPath . '/' . $mountPath)) {
                $defaultSource = $appPath . '/' . $mountPath;
            }
        }

        if (empty($source)) {
            $questionText = 'Source directory';
            if ($defaultSource !== null) {
                $formattedDefaultSource = $this->filesystem->formatPathForDisplay($defaultSource);
                $questionText .= ' <question>[' . $formattedDefaultSource . ']</question>';
            }
            $questionText .= ': ';
            $source = $this->questionHelper->ask($input, $this->stdErr, new Question($questionText, $defaultSource));
        }

        if (empty($source)) {
            $this->stdErr->writeln('The source directory must be specified.');

            return 1;
        }

        $this->filesystem->validateDirectory($source);

        $confirmText = sprintf(
            "\nUploading files from <comment>%s</comment> to the remote mount <comment>%s</comment>"
            . "\n\nAre you sure you want to continue?",
            $this->filesystem->formatPathForDisplay($source),
            $mountPath
        );
        if (!$this->questionHelper->confirm($confirmText)) {
            return 1;
        }

        $rsyncOptions = [
            'delete' => $input->getOption('delete'),
            'exclude' => $input->getOption('exclude'),
            'include' => $input->getOption('include'),
            'verbose' => $output->isVeryVerbose(),
            'quiet' => $output->isQuiet(),
        ];

        if (OsUtil::isOsX()) {
            if ($this->rsync->supportsConvertingFilenames() !== false) {
                $this->debug('Converting filenames with special characters (utf-8-mac to utf-8)');
                $rsyncOptions['convert-mac-filenames'] = true;
            } else {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('Warning: the installed version of <comment>rsync</comment> does not support converting filenames with special characters (the --iconv flag). You may need to upgrade rsync.');
            }
        }

        $this->stdErr->writeln('');
<<<<<<< HEAD
        $this->rsync->syncUp($container->getSshUrl(), $source, $mountPath, $rsyncOptions);
=======
        $rsync->syncUp($sshUrl, $source, $mountPath, $rsyncOptions);
>>>>>>> 3.x

        return 0;
    }
}
