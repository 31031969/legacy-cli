<?php
declare(strict_types=1);

namespace Platformsh\Cli;

use Doctrine\Common\Cache\CacheProvider;
use Platformsh\Cli\Command\WelcomeCommand;
use Platformsh\Cli\Console\EventSubscriber;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Command\MultiAwareInterface;
use Platformsh\Cli\Console\HiddenInputOption;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\LegacyMigration;
use Platformsh\Cli\Service\SelfUpdateChecker;
use Platformsh\Cli\Util\TimezoneUtil;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Application as ParentApplication;
use Symfony\Component\Console\Command\Command as ConsoleCommand;
use Symfony\Component\Console\DependencyInjection\AddConsoleCommandPass;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\EventDispatcher\EventDispatcher;

class Application extends ParentApplication
{
    /**
     * @var ConsoleCommand|null
     */
    private $currentCommand;

    /** @var Config */
    private $config;

    /** @var \Symfony\Component\DependencyInjection\Container */
    private $container;

    /** @var string */
    private $envPrefix;

    /** @var bool */
    private $runningViaMulti = false;

    public function __construct(Config $config = null)
    {
        // Initialize configuration (from config.yaml).
        $this->config = $config ?: new Config();
        $this->envPrefix = $this->config->get('application.env_prefix');
        parent::__construct($this->config->get('application.name'), $this->config->getVersion());

        // Use the configured timezone, or fall back to the system timezone.
        date_default_timezone_set(
            $this->config->getWithDefault('application.timezone', null)
                ?: TimezoneUtil::getTimezone()
        );

        // Set this application as the synthetic service named
        // "Platformsh\Cli\Application".
        $this->container()->set(__CLASS__, $this);

        // Set up the command loader, which will load commands that are tagged
        // appropriately in the services.yaml container configuration (any
        // services tagged with "console.command").
        /** @var \Symfony\Component\Console\CommandLoader\CommandLoaderInterface $loader */
        $loader = $this->container()->get('console.command_loader');
        $this->setCommandLoader($loader);

        // Set "welcome" as the default command.
        $this->setDefaultCommand(WelcomeCommand::getDefaultName());

        // Set up an event subscriber, which will listen for Console events.
        $dispatcher = new EventDispatcher();
        /** @var CacheProvider $cache */
        $cache = $this->container()->get(CacheProvider::class);
        $dispatcher->addSubscriber(new EventSubscriber($cache, $this->config));
        $this->setDispatcher($dispatcher);
    }

    /**
     * {@inheritDoc}
     */
    public function getVersion(): string {
        return $this->config->getVersion();
    }

    /**
     * Re-compile the container and alias caches.
     */
    public function warmCaches(): void {
        $this->container(true);
    }

    /**
     * {@inheritdoc}
     *
     * Prevent commands being enabled, according to config.yaml configuration.
     */
    public function add(ConsoleCommand $command)
    {
        if (!$this->config->isCommandEnabled($command->getName())) {
            $command->setApplication(null);
            return null;
        }

        return parent::add($command);
    }

    /**
     * Returns the Dependency Injection Container for the whole application.
     *
     * @param bool $recompile
     *
     * @return ContainerInterface
     */
    private function container(bool $recompile = false)
    {
        $cacheFile = __DIR__ . '/../config/cache/container.php';
        $servicesFile = __DIR__ . '/../config/services.yaml';

        if (!isset($this->container)) {
            if (file_exists($cacheFile) && !getenv('PLATFORMSH_CLI_DEBUG') && !$recompile) {
                // Load the cached container.
                require_once $cacheFile;
                $this->container = new \ProjectServiceContainer();
            } else {
                // Compile a new container.
                $this->container = new ContainerBuilder();
                try {
                    (new YamlFileLoader($this->container, new FileLocator()))
                        ->load($servicesFile);
                } catch (\Exception $e) {
                    throw new \RuntimeException(sprintf(
                        'Failed to load services.yaml file %s: %s',
                        $servicesFile,
                        $e->getMessage()
                    ));
                }
                $this->container->addCompilerPass(new AddConsoleCommandPass());
                $this->container->compile();
                $dumper = new PhpDumper($this->container);
                if (!is_dir(dirname($cacheFile))) {
                    mkdir(dirname($cacheFile), 0755, true);
                }
                file_put_contents($cacheFile, $dumper->dump());
            }
        }

        return $this->container;
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultInputDefinition()
    {
        return new InputDefinition([
            new InputArgument('command', InputArgument::REQUIRED, 'The command to execute'),
            new InputOption('--help', '-h', InputOption::VALUE_NONE, 'Display this help message'),
            new InputOption('--verbose', '-v|vv|vvv', InputOption::VALUE_NONE, 'Increase the verbosity of messages'),
            new InputOption('--version', '-V', InputOption::VALUE_NONE, 'Display this application version'),
            new InputOption('--yes', '-y', InputOption::VALUE_NONE, 'Answer "yes" to confirmation questions; accept the default value for other questions; disable interaction'),
            new InputOption(
                '--no-interaction',
                null,
                InputOption::VALUE_NONE,
                'Do not ask any interactive questions; accept default values. '
                . sprintf('Equivalent to using the environment variable: <comment>%sNO_INTERACTION=1</comment>', $this->envPrefix)
            ),
            new HiddenInputOption('--ansi', '', InputOption::VALUE_NONE, 'Force ANSI output'),
            new HiddenInputOption('--no-ansi', '', InputOption::VALUE_NONE, 'Disable ANSI output'),
            // TODO deprecate the following options?
            new HiddenInputOption('--no', '-n', InputOption::VALUE_NONE, 'Answer "no" to confirmation questions; accept the default value for other questions; disable interaction'),
            new HiddenInputOption('--quiet', '-q', InputOption::VALUE_NONE, 'Do not output any message'),
        ]);
    }

    /**
     * @inheritdoc
     */
    protected function getDefaultCommands()
    {
        // All commands are lazy-loaded.
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getHelp()
    {
        $messages = [
            $this->getLongVersion(),
            '',
            '<comment>Global options:</comment>',
        ];

        foreach ($this->getDefinition()
                      ->getOptions() as $option) {
            $messages[] = sprintf(
                '  %-29s %s %s',
                '<info>--' . $option->getName() . '</info>',
                $option->getShortcut() ? '<info>-' . $option->getShortcut() . '</info>' : '  ',
                $option->getDescription()
            );
        }

        return implode(PHP_EOL, $messages);
    }

    /**
     * {@inheritdoc}
     */
    protected function configureIO(InputInterface $input, OutputInterface $output)
    {
        // Set the input and output in the service container.
        $this->container()->set(InputInterface::class, $input);
        $this->container()->set(OutputInterface::class, $output);

        parent::configureIO($input, $output);

        // Set the input to non-interactive if the yes or no options are used,
        // or if the PLATFORMSH_CLI_NO_INTERACTION variable is not empty.
        // The --no-interaction option is handled in the parent method.
        if ($input->hasParameterOption(['--yes', '-y', '--no', '-n'])
          || getenv($this->envPrefix . 'NO_INTERACTION')) {
            $input->setInteractive(false);
        }

        // Allow the NO_COLOR, CLICOLOR_FORCE, and TERM environment variables to
        // override whether colors are used in the output.
        // See: https://no-color.org
        // See: https://en.wikipedia.org/wiki/Computer_terminal#Dumb_terminals
        /* @see StreamOutput::hasColorSupport() */
        if (getenv('CLICOLOR_FORCE') === '1') {
            $output->setDecorated(true);
        } elseif (getenv('NO_COLOR')
            || getenv('CLICOLOR_FORCE') === '0'
            || getenv('TERM') === 'dumb'
            || getenv($this->envPrefix . 'NO_COLOR')) {
            $output->setDecorated(false);
        }

        // The api.debug config option triggers debug-level output.
        if ($this->config->get('api.debug') || getenv($this->envPrefix . 'DEBUG')) {
            $output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
        }

        // Tune error reporting based on the output verbosity.
        ini_set('log_errors', '0');
        ini_set('display_errors', '0');
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        } elseif ($output->getVerbosity() === OutputInterface::VERBOSITY_QUIET) {
            error_reporting(0);
        } else {
            error_reporting(E_PARSE | E_ERROR);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function doRunCommand(ConsoleCommand $command, InputInterface $input, OutputInterface $output)
    {
        $this->setCurrentCommand($command);
        if ($command instanceof MultiAwareInterface) {
            $command->setRunningViaMulti($this->runningViaMulti);
        }

        // Build the command synopsis early, so it doesn't include default
        // options and arguments (such as --help and <command>).
        // @todo find a better solution for this?
        $this->currentCommand->getSynopsis();

        // Work around a bug in Console which means the default command's input
        // is always considered to be interactive.
        if ($command->getName() === 'welcome'
            && isset($GLOBALS['argv'])
            && array_intersect($GLOBALS['argv'], ['-n', '--no', '-y', '---yes'])) {
            $input->setInteractive(false);
        }

        // Check for automatic updates.
        $noChecks = in_array($command->getName(), ['welcome', '_completion']);
        if ($input->isInteractive() && !$noChecks) {
            /** @var SelfUpdateChecker $checker */
            $checker = $this->container()->get(SelfUpdateChecker::class);
            $checker->checkUpdates();
        }

        if (!$noChecks && $command->getName() !== 'legacy-migrate') {
            /** @var LocalProject $localProject */
            $localProject = $this->container()->get(LocalProject::class);
            if ($localProject->getLegacyProjectRoot()) {
                /** @var LegacyMigration $legacyMigration */
                $legacyMigration = $this->container()->get(LegacyMigration::class);
                $legacyMigration->check();
            }
        }

        return parent::doRunCommand($command, $input, $output);
    }

    /**
     * Set the current command. This is used for error handling.
     *
     * @param ConsoleCommand|null $command
     */
    public function setCurrentCommand(ConsoleCommand $command = null)
    {
        // The parent class has a similar (private) property named
        // $runningCommand.
        $this->currentCommand = $command;
    }

    /**
     * Get the current command.
     *
     * @return ConsoleCommand|null
     */
    public function getCurrentCommand()
    {
        return $this->currentCommand;
    }

    public function renderThrowable(\Throwable $e, OutputInterface $output): void
    {
        $output->writeln('', OutputInterface::VERBOSITY_QUIET);

        $this->doRenderThrowable($e, $output);

        if (isset($this->currentCommand)
            && $this->currentCommand->getName() !== 'welcome'
            && $e instanceof ExceptionInterface) {
            $output->writeln(
                sprintf('Usage: <info>%s</info>', $this->currentCommand->getSynopsis()),
                OutputInterface::VERBOSITY_QUIET
            );
            $output->writeln('', OutputInterface::VERBOSITY_QUIET);
            $output->writeln(sprintf(
                'For more information, type: <info>%s help %s</info>',
                $this->config->get('application.executable'),
                $this->currentCommand->getName()
            ), OutputInterface::VERBOSITY_QUIET);
            $output->writeln('', OutputInterface::VERBOSITY_QUIET);
        }
    }

    public function setRunningViaMulti()
    {
        $this->runningViaMulti = true;
    }
}
