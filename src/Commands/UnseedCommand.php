<?php

namespace Devel\Modules\Commands;

use ErrorException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Str;
use Devel\Modules\Contracts\RepositoryInterface;
use Devel\Modules\Module;
use Devel\Modules\Support\Config\GenerateConfigReader;
use Devel\Modules\Traits\ModuleCommandTrait;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class UnseedCommand extends Command
{
    use ModuleCommandTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'module:unseed';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Revert database seeder from the specified module or from all modules.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            if ($name = $this->argument('module')) {
                $name = Str::studly($name);
                $this->moduleUnseed($this->getModuleByName($name));
            } else {
                $modules = $this->getModuleRepository()->getOrdered();
                array_walk($modules, [$this, 'moduleUnseed']);
                $this->info('All modules unseeded.');
            }
        } catch (\Error $e) {
            $e = new ErrorException($e->getMessage(), $e->getCode(), 1, $e->getFile(), $e->getLine(), $e);
            $this->reportException($e);
            $this->renderException($this->getOutput(), $e);

            return 1;
        } catch (\Exception $e) {
            $this->reportException($e);
            $this->renderException($this->getOutput(), $e);

            return 1;
        }
    }

    /**
     * @throws RuntimeException
     * @return RepositoryInterface
     */
    public function getModuleRepository(): RepositoryInterface
    {
        $modules = $this->laravel['devel-modules'];
        if (!$modules instanceof RepositoryInterface) {
            throw new RuntimeException('Module repository not found!');
        }

        return $modules;
    }

    /**
     * @param $name
     *
     * @throws RuntimeException
     *
     * @return Module
     */
    public function getModuleByName($name)
    {
        $modules = $this->getModuleRepository();
        if ($modules->has($name) === false) {
            throw new RuntimeException("Module [$name] does not exists.");
        }

        return $modules->find($name);
    }

    /**
     * @param Module $module
     *
     * @return void
     */
    public function moduleUnseed(Module $module)
    {
        $seeders = [];
        $name = $module->getName();
        $config = $module->get('migration');
        if (is_array($config) && array_key_exists('seeds', $config)) {
            foreach ((array)$config['seeds'] as $class) {
                if (class_exists($class)) {
                    $seeders[] = $class;
                }
            }
        } else {
            $class = $this->getSeederName($name); //legacy support
            if (class_exists($class)) {
                $seeders[] = $class;
            } else {
                //look at other namespaces
                $classes = $this->getSeederNames($name);
                foreach ($classes as $class) {
                    if (class_exists($class)) {
                        $seeders[] = $class;
                    }
                }
            }
        }

        if (count($seeders) > 0) {
            array_walk($seeders, [$this, 'dbUnseed']);
            $this->info("Module [$name] unseeded.");
        }
    }

    /**
     * Unseed the specified module.
     *
     * @param string $className
     */
    protected function dbUnseed($className)
    {
        if ($option = $this->option('class')) {
            $params['--class'] = Str::finish(substr($className, 0, strrpos($className, '\\')), '\\') . $option;
        } else {
            $params = ['--class' => $className];
        }

        if ($option = $this->option('database')) {
            $params['--database'] = $option;
        }

        if ($option = $this->option('force')) {
            $params['--force'] = $option;
        }

        (new $className)->revert();
    }

    /**
     * Get master database seeder name for the specified module.
     *
     * @param string $name
     *
     * @return string
     */
    public function getSeederName($name)
    {
        $name = Str::studly($name);

        $namespace = $this->laravel['devel-modules']->config('namespace');
        $config = GenerateConfigReader::read('seeder');
        $seederPath = str_replace('/', '\\', $config->getPath());

        return $namespace . '\\' . $name . '\\' . $seederPath . '\\' . $name . 'DatabaseSeeder';
    }

    /**
     * Get master database seeder name for the specified module under a different namespace than Modules.
     *
     * @param string $name
     *
     * @return array $foundModules array containing namespace paths
     */
    public function getSeederNames($name)
    {
        $name = Str::studly($name);

        $seederPath = GenerateConfigReader::read('seeder');
        $seederPath = str_replace('/', '\\', $seederPath->getPath());

        $foundModules = [];
        $paths = $this->laravel['devel-modules']->config('scan.paths');

        if (is_array($paths)) {
            foreach ($this->laravel['devel-modules']->config('scan.paths') as $path) {
                $namespace = array_slice(explode('/', $path), -1)[0];
                $foundModules[] = $namespace . '\\' . $name . '\\' . $seederPath . '\\' . $name . 'DatabaseSeeder';
            }
        }

        return $foundModules;
    }

    /**
     * Report the exception to the exception handler.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Throwable  $e
     * @return void
     */
    protected function renderException($output, \Exception $e)
    {
        $this->laravel[ExceptionHandler::class]->renderForConsole($output, $e);
    }

    /**
     * Report the exception to the exception handler.
     *
     * @param  \Throwable  $e
     * @return void
     */
    protected function reportException(\Exception $e)
    {
        $this->laravel[ExceptionHandler::class]->report($e);
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['module', InputArgument::OPTIONAL, 'Module to unseed.'],
        ];
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['class', null, InputOption::VALUE_OPTIONAL, 'The class name of the root seeder.'],
            ['database', null, InputOption::VALUE_OPTIONAL, 'The database connection to seed.'],
            ['force', null, InputOption::VALUE_NONE, 'Force the operation to run when in production.'],
        ];
    }
}
