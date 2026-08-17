<?php

declare(strict_types=1);

namespace ADT\DoctrineAnonymization;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates or regenerates the database views with masked personal data.
 *
 * Run it on every deploy right after migrations - the column list of a view is fixed
 * at generation time, so new columns only appear after regeneration.
 *
 * If your project needs extra steps around the generation, skip this command and call
 * {@see AnonymizedViewsGenerator} from your own command instead.
 */
#[AsCommand(
	name: 'anonymization:generate-views',
	description: 'Create or regenerate DB views with anonymized personal data columns.',
)]
class GenerateAnonymizedViewsCommand extends Command
{
	public function __construct(private readonly AnonymizedViewsGenerator $generator)
	{
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->addOption('schema', null, InputOption::VALUE_REQUIRED, 'Target schema for the views (default "<dbname>_anon")')
			->addOption('salt', null, InputOption::VALUE_REQUIRED, 'Salt for deterministic hashing')
			->addOption('mask', null, InputOption::VALUE_NONE, 'Replace personal values with a fixed "' . AnonymizedViewsGenerator::MASK_VALUE . '" instead of realistic ones')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the SQL instead of executing it')
			->addOption('federated', null, InputOption::VALUE_NONE, 'Mirror the source database on --host through FEDERATED tables and build the views over them')
			->addOption('host', null, InputOption::VALUE_REQUIRED, 'Target DB host (default: the application connection)')
			->addOption('port', null, InputOption::VALUE_REQUIRED, 'Target DB port (only with --host)', '3306')
			->addOption('db-user', null, InputOption::VALUE_REQUIRED, 'Target DB user (only with --host)')
			->addOption('db-password', null, InputOption::VALUE_REQUIRED, 'Target DB password (only with --host)')
			->addOption('dbname', null, InputOption::VALUE_REQUIRED, 'Source database name (only with --host)')
			->addOption('grant-user', null, InputOption::VALUE_REQUIRED, 'Read-only account to create and grant SELECT on the views schema')
			->addOption('grant-password', null, InputOption::VALUE_REQUIRED, 'Password for --grant-user (omit to keep the existing one)')
			->addOption('grant-host', null, InputOption::VALUE_REQUIRED, 'Host part of the granted account', '%');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$salt = $input->getOption('salt');

		$options = new GeneratorOptions(
			schema: $input->getOption('schema') ?: null,
			salt: $salt !== null ? (string) $salt : (new GeneratorOptions())->salt,
			mask: (bool) $input->getOption('mask'),
			dryRun: (bool) $input->getOption('dry-run'),
			federated: (bool) $input->getOption('federated'),
			host: $input->getOption('host') ?: null,
			port: (int) $input->getOption('port'),
			dbUser: $input->getOption('db-user') ?: null,
			dbPassword: $input->getOption('db-password') ?: null,
			dbName: $input->getOption('dbname') ?: null,
			grantUser: $input->getOption('grant-user') ?: null,
			grantPassword: $input->getOption('grant-password') ?: null,
			grantHost: (string) $input->getOption('grant-host'),
		);

		try {
			$result = $this->generator->generate($options);
		} catch (\RuntimeException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return Command::FAILURE;
		}

		foreach ($result->sqlStatements as $sql) {
			$output->writeln($sql . ';');
		}

		if ($output->isVerbose()) {
			foreach ($result->anonymizedColumnsByTable as $table => $columns) {
				$output->writeln(sprintf(
					'<comment>%s: anonymized %d column(s): %s</comment>',
					$table,
					count($columns),
					implode(', ', $columns),
				));
			}
		}

		if ($result->skippedTables) {
			$output->writeln(sprintf(
				'<comment>Skipped %d table(s) missing on the source server: %s</comment>',
				count($result->skippedTables),
				implode(', ', $result->skippedTables),
			));
		}

		foreach ($result->failedTables as $table => $message) {
			$output->writeln(sprintf('<error>Failed "%s": %s</error>', $table, $message));
		}

		if ($options->grantUser !== null) {
			$output->writeln(sprintf('<info>Granted SELECT on "%s".* to %s@%s.</info>', $result->schema, $options->grantUser, $options->grantHost));
		}

		$output->writeln(sprintf(
			'<info>%s %d view(s) in schema "%s"%s (%d anonymized column(s)%s, %d stale view(s) dropped, %d failed).</info>',
			$options->dryRun ? '[dry-run] Would generate' : 'Generated',
			$result->viewCount,
			$result->schema,
			$result->mirrorSchema !== null ? sprintf(' over FEDERATED mirror "%s"', $result->mirrorSchema) : '',
			$result->anonymizedColumnCount,
			$options->mask ? sprintf(' masked as "%s"', AnonymizedViewsGenerator::MASK_VALUE) : '',
			count($result->staleViews),
			count($result->failedTables),
		));

		return $result->hasFailures() ? Command::FAILURE : Command::SUCCESS;
	}
}
