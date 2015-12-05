<?php
namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Model\Variable;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class VariableGetCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('variable:get')
            ->setAliases(['variables', 'vget'])
            ->addArgument('name', InputArgument::OPTIONAL, 'The name of the variable')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the full variable value only')
            ->addOption('ssh', null, InputOption::VALUE_NONE, 'Use SSH to get the currently active variables')
            ->setDescription('View variable(s) for an environment');
        $this->addProjectOption()
             ->addEnvironmentOption();
        $this->addExample('View the variable "example"', 'example');
        $this->setHiddenAliases(['variable:list']);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        // @todo This --ssh option is only here as a temporary workaround.
        if ($input->getOption('ssh')) {
            $shellHelper = $this->getHelper('shell');
            $platformVariables = $shellHelper->execute([
                'ssh',
                $this->getSelectedEnvironment()->getSshUrl(),
                'echo $PLATFORM_VARIABLES',
            ], true);
            $results = json_decode(base64_decode($platformVariables), true);
            foreach ($results as $id => $value) {
                if (!is_scalar($value)) {
                    $value = json_encode($value);
                }
                $output->writeln("$id\t$value");
            }

            return 0;
        }

        $name = $input->getArgument('name');

        if ($name) {
            $variable = $this->getSelectedEnvironment()
                             ->getVariable($name);
            if (!$variable) {
                $this->stdErr->writeln("Variable not found: <error>$name</error>");

                return 1;
            }
            $results = [$variable];
        } else {
            $results = $this->getSelectedEnvironment()
                            ->getVariables();
            if (!$results) {
                $this->stdErr->writeln('No variables found');

                return 1;
            }
        }

        if ($input->getOption('pipe')) {
            foreach ($results as $variable) {
                $output->writeln($variable->id . "\t" . $variable->value);
            }
        } else {
            $table = $this->buildVariablesTable($results, $output);
            $table->render();
        }

        return 0;
    }

    /**
     * @param Variable[]      $variables
     * @param OutputInterface $output
     *
     * @return Table
     */
    protected function buildVariablesTable(array $variables, OutputInterface $output)
    {
        $table = new Table($output);
        $table->setHeaders(["ID", "Value", "Inherited", "JSON"]);
        foreach ($variables as $variable) {
            $value = $variable->value;
            // Truncate long values.
            if (strlen($value) > 60) {
                $value = substr($value, 0, 57) . '...';
            }
            // Wrap long values.
            $value = wordwrap($value, 30, "\n", true);
            $table->addRow([
                $variable->id,
                $value,
                $variable->inherited ? 'Yes' : 'No',
                $variable->is_json ? 'Yes' : 'No',
            ]);
        }

        return $table;
    }

}
