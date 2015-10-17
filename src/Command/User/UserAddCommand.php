<?php
namespace Platformsh\Cli\Command\User;

use Platformsh\Cli\Command\PlatformCommand;
use Platformsh\Cli\Util\ActivityUtil;
use Platformsh\Client\Model\ProjectAccess;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class UserAddCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('user:add')
          ->setDescription('Add a user to the project')
          ->addArgument('email', InputArgument::OPTIONAL, "The new user's email address")
          ->addOption('role', null, InputOption::VALUE_REQUIRED, "The new user's role: 'admin' or 'viewer'")
          ->addOption('no-wait', null, InputOption::VALUE_NONE, 'Do not wait for environment(s) to be redeployed');
        $this->addProjectOption();
        $this->addExample('Add Alice as a new administrator', 'alice@example.com --role admin');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        /** @var \Platformsh\Cli\Helper\PlatformQuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');

        $email = $input->getArgument('email');
        if ($email && !$this->validateEmail($email)) {
            return 1;
        }
        elseif (!$email) {
            $question = new Question('Email address: ');
            $question->setValidator(array($this, 'validateEmail'));
            $question->setMaxAttempts(5);
            $email = $questionHelper->ask($input, $this->stdErr, $question);
        }

        $project = $this->getSelectedProject();

        $users = $project->getUsers();
        foreach ($users as $projectAccess) {
            if ($projectAccess->getAccount()['email'] === $email) {
                $this->stdErr->writeln("The user already exists: <comment>$email</comment>");
                return 1;
            }
        }

        $projectRole = $input->getOption('role');
        if ($projectRole && !in_array($projectRole, ProjectAccess::$roles)) {
            $this->stdErr->writeln("Valid project-level roles are 'admin' or 'viewer'");
            return 1;
        }
        elseif (!$projectRole) {
            if (!$input->isInteractive()) {
                $this->stdErr->writeln('You must specify a project role for the user.');
                return 1;
            }
            $this->stdErr->writeln("The user's project role can be 'viewer' ('v') or 'admin' ('a').");
            $question = new Question('Project role <question>[V/a]</question>: ', 'viewer');
            $question->setValidator(array($this, 'validateRole'));
            $question->setMaxAttempts(5);
            $projectRole = $this->standardizeRole($questionHelper->ask($input, $this->stdErr, $question));
        }

        $environmentRoles = [];
        $environments = [];
        if ($projectRole !== 'admin') {
            $environments = $this->getEnvironments($project);
            if ($input->isInteractive()) {
                $this->stdErr->writeln("The user's environment-level roles can be 'viewer', 'contributor', 'admin', or 'none'.");
            }
            foreach ($environments as $environment) {
                $question = new Question('<info>' . $environment->id . '</info> environment role <question>[v/c/a/N]</question>: ', 'none');
                $question->setValidator(array($this, 'validateRole'));
                $question->setMaxAttempts(5);
                $environmentRoles[$environment->id] = $this->standardizeRole($questionHelper->ask($input, $this->stdErr, $question));
            }
        }

        $summaryFields = [
            'Email address' => $email,
            'Project role' => $projectRole,
        ];
        if (!empty($environmentRoles)) {
            foreach ($environments as $environment) {
                if (isset($environmentRoles[$environment->id])) {
                    $summaryFields[$environment['title']] = $environmentRoles[$environment->id];
                }
            }
        }

        $this->stdErr->writeln('Summary:');
        foreach ($summaryFields as $field => $value) {
            $this->stdErr->writeln("    $field: <info>$value</info>");
        }

        $this->stdErr->writeln("<comment>Adding users can result in additional charges.</comment>");

        if ($input->isInteractive()) {
            if (!$questionHelper->confirm("Are you sure you want to add this user?", $input, $this->stdErr)) {
                return 1;
            }
        }

        $this->stdErr->writeln("Adding the user to the project");
        $projectAccess = $project->addUser($email, $projectRole);

        if (!empty($environmentRoles)) {
            $this->stdErr->writeln("Setting environment role(s)");
            $activities = [];
            foreach ($environmentRoles as $environmentId => $role) {
                if (!isset($environments[$environmentId])) {
                    $this->stdErr->writeln("<error>Environment not found: $environmentId</error>");
                    continue;
                }
                if ($role == 'none') {
                    continue;
                }
                $access = $environments[$environmentId]->getUser($projectAccess->id);
                if ($access) {
                    $this->stdErr->writeln("Modifying the user's role on the environment: <info>$environmentId</info>");
                    $activity = $access->update(['role' => $role]);
                }
                else {
                    $this->stdErr->writeln("Adding the user to the environment: <info>$environmentId</info>");
                    $activity = $environments[$environmentId]->addUser($projectAccess->id, $role);
                }
                if (!$input->getOption('no-wait')) {
                    ActivityUtil::waitAndLog($activity, $this->stdErr);
                }
            }
        }

        $this->stdErr->writeln("User <info>$email</info> created");
        return 0;
    }

    /**
     * @param string $value
     *
     * @return string
     */
    public function validateRole($value)
    {
        if (empty($value) || !in_array($value, array('admin', 'contributor', 'viewer', 'none', 'a', 'c', 'v', 'n'))) {
            throw new \RuntimeException("Invalid role: $value");
        }

        return $value;
    }

    /**
     * @param string $value
     *
     * @return string
     */
    public function validateEmail($value)
    {
        if (empty($value) || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException("Invalid email address: $value");
        }

        return $value;
    }

    /**
     * @param string $givenRole
     *
     * @return string
     * @throws \Exception
     */
    protected function standardizeRole($givenRole)
    {
        $possibleRoles = array('viewer', 'admin', 'contributor', 'none');
        if (in_array($givenRole, $possibleRoles)) {
            return $givenRole;
        }
        $role = strtolower($givenRole);
        foreach ($possibleRoles as $possibleRole) {
            if (strpos($possibleRole, $role) === 0) {
                return $possibleRole;
            }
        }
        throw new \Exception("Role not found: $givenRole");
    }
}
