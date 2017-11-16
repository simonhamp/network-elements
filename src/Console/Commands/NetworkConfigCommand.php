<?php

namespace SimonHamp\NetworkElements\Console\Commands;

use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Console\ConfirmableTrait;
use SimonHamp\NetworkElements\Models\User;
use SimonHamp\NetworkElements\Console\Command;

class NetworkConfigCommand extends Command
{
    use ConfirmableTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'network:configure {--force : Force the operation to run when in production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Configure your network.';

    protected $stage = 0;
    protected $finalStage = 2;
    protected $hasError = false;

    protected $validConnections = [
        'sqlite' => 'SQLite',
        'mysql' => 'MySQL',
        'pgsql' => 'PostgreSQL',
        'sqlsrv' => 'Microsoft SQL Server'
    ];

    protected $defaultDbPorts = [
        'mysql' => 3306,
        'pgsql' => 5432,
        'sqlsrv' => 1433,
    ];

    protected $user_name;
    protected $name;
    protected $url;
    protected $connection;
    protected $host;
    protected $port;
    protected $database;
    protected $username;
    protected $password;

    /**
     * Map .env key names to local instance variable names
     *
     * @var array
     */
    protected $keys = [
        'APP_NAME'      => 'name',
        'APP_URL'       => 'url',
        'DB_CONNECTION' => 'connection',
        'DB_HOST'       => 'host',
        'DB_PORT'       => 'port',
        'DB_DATABASE'   => 'database',
        'DB_USERNAME'   => 'username',
        'DB_PASSWORD'   => 'password',
    ];

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        // Get the last state of the installer
        if (Storage::exists('installer') && file_exists($this->laravel->environmentFilePath())) {
            $this->stage = (int) Storage::get('installer');

            try {
                $this->user_name = User::first()->name;
            } catch (\PDOException $e) {
                $this->user_name = str_replace("'s Network$", '', env('APP_NAME').'$');
                $this->warn("There was a problem connecting to your Network database. Please update your connection details.");
                $this->stage = 1;
            }
        }

        // If it's been completed already, let's confirm
        $confirmed = $this->confirmToProceed(
            'It looks like you have already completed config for this network!',
            function () {
                return $this->isComplete();
            }
        );

        if (! $confirmed) {
            return;
        }

        // If this was previously complete, reset the stage so we can rerun everything
        if ($this->isComplete()) {
            $this->stage = 0;
        }

        // See if there's a file from a previous attempt
        if (Storage::exists('temp.env')) {
            $this->info('Loading settings from initial setup...');
            $env = Storage::get('temp.env');
        } else {
            $this->runInteractiveSurvey();
        }

        // Attempt to update the environment file
        if (! $this->updateEnvironmentFile($env ?? null)) {
            $this->error(".env file couldn't be updated. Please check folder permissions and try again.");

            logger('NETWORK.INSTALL: Failed to update .env file');

            return;
        }

        // Delete the temporary settings file
        Storage::delete('temp.env');

        // .env created successfully, let's finish setup
        $this->finalizeSetup();
    }

    protected function isComplete()
    {
        return $this->stage >= $this->finalStage;
    }

    protected function runInteractiveSurvey()
    {
        if ($this->stage < 1) {
            $this->alert("Welcome to Network! To get your system set up, I just need to ask you a few questions...");

            $this->user_name = $this->ask('What is your name?');
            $this->name = $this->user_name . "'s Network";

            $this->line("Hi {$this->user_name}! Great to have you on board :)");

            $this->url = $this->ask('What URL is your network accessible from?', 'http://localhost/network');

            $this->line("Site URL set to {$this->url}.");
        }

        if ($this->stage < 2) {
            $this->database = database_path('database.sqlite');

            $this->connection = $this->choice(
                'Which database connection are you using?',
                $this->validConnections,
                'sqlite'
            );

            $dbServer = $this->validConnections[$this->connection];

            $this->line("Database connection set to {$dbServer}.");

            if ($this->connection !== 'sqlite') {
                $this->host = $this->anticipate('What host is your database on? (IP address or hostname)', ['localhost', '127.0.0.1'], 'localhost');
                $this->line("Database host set to {$this->host}.");

                $defaultDbPort = $this->defaultDbPorts[$this->connection];

                $this->port = $this->anticipate('What port is your database on?', [$defaultDbPort], $defaultDbPort);
                $this->line("Database port set to {$this->port}.");

                $this->database = $this->ask('What is the name of the database you wish to use?', 'network');
                $this->line("Database name is {$this->database}.");

                $this->username = $this->ask("Please enter a {$dbServer} username that has access to this database", 'root');
                $this->line("Database user set to {$this->username}.");

                $this->password = $this->secret("What is that user's password? (Leave blank for no password)", true, true) ?? '';
                $this->line('Database user password set.');
            }

            // Adjust current config so we can use these settings in the current request
            config([
                'database' => [
                    'default' => $this->connection,
                    'connections' => [
                        $this->connection => [
                            'driver' => $this->connection,
                            'host' => $this->host,
                            'port' => $this->port,
                            'database' => $this->database,
                            'username' => $this->username,
                            'password' => $this->password,
                        ],
                    ],
                ],
                'app' => [
                    'url' => $this->url,
                ],
            ]);
        }
    }

    /**
     * Run main setup after we know we have the .env in place.
     */
    protected function finalizeSetup()
    {
        // Run migrations if possible
        $attemptMigrate = false;

        if ($this->connection === 'sqlite') {
            $attemptMigrate = $this->createSqliteDatabase();
        } else {
            $attemptMigrate = $this->canConnectToDb();
        }

        sleep(1);

        if ($attemptMigrate) {
            $this->call('migrate', ['--force' => true]);

            // Mark as installer complete
            Storage::put('installer', '2');

            sleep(1);

            // Create the first user's account if we need to
            if (User::count() < 1) {
                $this->info("Now let's create your user account...");
                $this->call('network:user', ['--name' => $this->user_name]);
            }
        }

        // Make the symlinks we need
        $this->createSymlinks();

        sleep(1);

        if (! $this->hasError) {
            $this->alert("Great news, {$this->user_name}: your Network is set up! I hope you enjoy using Network.");
        } else {
            $this->warn("Sorry, {$this->user_name}, I couldn't complete your Network setup this time.");
            $this->warn("Please check the warning messages to see what you need to do and rerun `php artisan network:config`.");
        }
    }

    /**
     * Create the SQLite database file if this is the driver the user wants to use.
     *
     * @return bool
     */
    protected function createSqliteDatabase()
    {
        $dbPath = config('database.connections.sqlite.database');
        $createDb = new Process("touch $dbPath");
        $createDb->run();

        if (! $createDb->isSuccessful()) {
            $this->warn("I couldn't create your SQLite database file.");
            $this->warn("Please create it after setup is complete and re-run setup.");
            $this->warn("Run `touch database/database.sqlite`");
            Storage::put('installer', '1');
            logger('NETWORK.INSTALL: Failed to create SQLite database');
            $this->hasError = true;

            return false;
        }

        $this->info("Database created at $dbPath.");

        return true;
    }

    /**
     * Check the database connection
     *
     * @return bool
     */
    protected function canConnectToDb()
    {
        $dbServer = $this->validConnections[$this->connection];

        try {
            // Run a simple test that will fail if the database doesn't exist
            Schema::connection($this->connection)->hasTable('migrations');
            return true;
        } catch (\PDOException $e) {
            $this->warn("I couldn't connect to a database called '{$this->database}' using the credentials you gave.");
            $this->warn("Please make sure the database exists and check your settings then re-run `network:config`.");
            Storage::put('installer', '1');
            logger('NETWORK.INSTALL: Failed to connect to database');
            $this->hasError = true;
        }

        return false;
    }

    /**
     * Write a new environment file with the given key.
     *
     * @param  string  $env
     * @return bool
     */
    protected function updateEnvironmentFile($env = null)
    {
        if ($env === null) {
            $env = $this->generateEnv();
        }

        if ($env !== null) {
            // Store the new .env file values
            if (file_put_contents($this->laravel->environmentFilePath(), $env)) {
                return true;
            }

            // Attempt to store settings in a separate file so the user doesn't have to repeat
            Storage::put('temp.env', $env);
        }

        return false;
    }

    /**
     * Generate the full .env file by replacing values from original
     *
     * @return string
     */
    protected function generateEnv()
    {
        $env = null;

        // Get the existing .env file contents to replace
        $envPath = $this->laravel->environmentFilePath();

        if (! file_exists($envPath)) {
            // Create the .env file from the example file
            copy($envPath.'.example', $envPath);
            $this->callSilent('key:generate', ['--force' => true]);
        }

        $env = file_get_contents($envPath);

        // Loop over all of the keys we can set and replace the values that are set
        foreach ($this->keys as $key => $var) {
            // Skip if it's not set
            if (! isset($this->$var)) {
                continue;
            }

            $env = preg_replace(
                $this->keyReplacementPattern($key),
                $key.'='.(is_string($this->$var) ? '"'.$this->$var.'"' : $this->$var),
                $env,
                1
            );
        }

        return $env;
    }

    /**
     * Create folder symlinks
     */
    protected function createSymlinks()
    {
        // Symlink the public storage folder
        if (! file_exists(public_path('storage'))) {
            $this->call('storage:link');
        }

        // Symlink the public assets folder
        if (! is_link($sitePath = public_path('network'))) {
            $this->info('Symlinking public assets...');
            $vendorPath = base_path('vendor/simonhamp/network-elements/public/');
            $createSymlink = new Process('ln -s "'.$vendorPath.'" "'.$sitePath.'"');
            $createSymlink->run();

            if (! $createSymlink->isSuccessful()) {
                $this->warn("I couldn't create a symlink to the Network public assets (CSS, JavaScript, fonts and images.");
                $this->warn("Please create a symlink to {$vendorPath} at {$sitePath}");
                logger('NETWORK.INSTALL: Failed to symlink public assets.', ['paths' => [$vendorPath, $sitePath]]);
                $this->hasError = true;
                return;
            }

            $this->info('Symlinking created successfully!');
        }
    }

    /**
     * Get a regex pattern that will match the appropriate env key.
     *
     * @return string
     */
    protected function keyReplacementPattern($key)
    {
        return "/^$key\=.*$/m";
    }
}
