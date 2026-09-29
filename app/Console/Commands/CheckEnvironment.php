<?php

namespace App\Console\Commands;

use App\Support\Environment\EnvironmentValidator;
use Illuminate\Console\Command;

class CheckEnvironment extends Command
{
    protected $signature = 'app:check-environment {--skip-database : Do not test the database connection}';

    protected $description = 'Validate environment configuration and database connectivity';

    public function handle(EnvironmentValidator $validator): int
    {
        $result = $validator->check(! $this->option('skip-database'));

        foreach ($result['warnings'] as $warning) {
            $this->components->warn($warning);
        }

        foreach ($result['errors'] as $error) {
            $this->components->error($error);
        }

        if ($result['errors'] !== []) {
            return self::FAILURE;
        }

        $this->components->info('Environment configuration is valid.');

        return self::SUCCESS;
    }
}
