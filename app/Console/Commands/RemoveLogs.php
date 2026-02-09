<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RemoveLogs extends Command
{
    protected $signature = 'remove:logs';
    protected $description = 'Clear PM2 logs and restart PM2 safely';

    public function handle()
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->info('Skipping PM2 log cleanup on Windows.');
            return Command::SUCCESS;
        }

        $logPath = '/root/.pm2/logs';
        
        if (!is_dir($logPath)) {
            $this->error("PM2 log directory not found: {$logPath}");
            return Command::FAILURE;
        }

        exec("find {$logPath} -type f -name '*.log' -delete", $output, $code);

        if ($code !== 0) {
            $this->error('Failed to delete PM2 logs.');
            return Command::FAILURE;
        }

        $this->info('PM2 logs cleaned.');

        sleep(2);

        exec('pm2 reload all', $restartOutput, $restartCode);

        if ($restartCode !== 0) {
            $this->error('PM2 reload failed.');
            return Command::FAILURE;
        }
        else
        {
            shell_exec('pm2 restart all');
        }

        $this->info('PM2 restarted successfully.');

        return Command::SUCCESS;
    }
}
