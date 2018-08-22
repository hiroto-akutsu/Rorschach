<?php

namespace Rorschach\Command;

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidPathException;
use Rorschach\Parser;
use Rorschach\Request;
use Rorschach\Assert;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Filesystem\Filesystem;

class RorschachCommand extends Command
{
    /**
     * Command configure
     */
    protected function configure()
    {
        $this
            ->setName('inspect')
            ->setDescription('inspect api')
            ->addOption(
                'file',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'test file path.'
            )
            ->addOption(
                'bind',
                'b',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'binding parameter.'
            )
            ->addOption(
                'env-file',
                null,
                InputOption::VALUE_REQUIRED,
                'file of environment variables.'
            )
            ->addOption(
                'dir',
                'd',
                InputOption::VALUE_REQUIRED,
                'test files dir.'
            )
            ->addOption(
                'saikou',
                's',
                InputOption::VALUE_NONE,
                'display saikou messages.'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'display output level.',
                array('simple', 'normal', 'debug')
            );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        switch ($input->getOption('output')) {
            case 'simple':
                $output->setVerbosity(OutputInterface::VERBOSITY_NORMAL);
                break;

            case 'debug':
                $output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
                break;

            case 'normal':
            default:
                $output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);
        }

        $this->loadDotEnv($input->getOption('env-file'));
        $this->loadPlugins();
        if ($input->getOption('dir')) {
            $targets = $this->fetchDirTargets($input->getOption('dir'));
        } else {
            $targets = $this->fetchTargets($input->getOption('file'));
        }

        $inputBinds = $this->fetchBinds($input->getOption('bind'));

        $fs = new Filesystem();

        $hasError = false;
        foreach ($targets as $target) {
            if (!$fs->exists($target)) {
                $output->writeln("<error>File not found:: {$target} has been skipped.</error>");
            }

            $yaml = file_get_contents($target);

            // {{ }} to (( ))
            $precompiled = Parser::precompile($yaml);

            $binds = $this->createEnvBinds($precompiled);
            $binds = array_merge($binds, $inputBinds);

            // bind option vars
            $compiled = Parser::compile($precompiled, $binds);
            $setting = Parser::parse($compiled);

            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                $output->writeln('<info>pre-request</info>');
            }

            foreach ((array)$setting['pre-request'] as $request) {
                $response = (new Request($setting, $request))->request();
                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                    $line = "<comment>{$request['method']}\t{$request['url']}</comment>";
                    $output->writeln($line);
                    $output->writeln($response->getStatusCode());
                    $output->writeln((string)$response->getBody());
                }

                if ($response->getStatusCode() >= 400) {
                    throw new \Exception('Pre-request failed.');
                }

                $binds = array_merge($binds, Request::getBindParams($response, (array)$request['bind'], $request['after-function']));
            }

            // bind vars after pre-requests
            $compiled = Parser::compile($precompiled, $binds);
            $setting = Parser::parse($compiled);

            if (isset($setting['pre-request']) && $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                $vars = Parser::searchVars($compiled);
                foreach ($vars as $var) {
                    $output->writeln('<error>unbound variable: '.$var.'</error>');
                }
            }

            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                $output->writeln('<info>request</info>');
            }

            $requestError = false;
            foreach ($setting['request'] as $request) {
                // bind vars
                $yaml = Parser::dump($request);
                $compiled = Parser::compile($yaml, $binds);
                $request = Parser::parse($compiled);

                $response = (new Request($setting, $request))->request();

                $binds = array_merge($binds, Request::getBindParams($response, (array)$request['bind'], $request['after-function']));

                $outputs = [];
                foreach ($request['expect'] as $type => $expect) {
                    switch ($type) {
                        case 'code':
                            $result = (new Assert\StatusCode($response, $expect))->assert();
                            $outputs[] = [$type, $expect, $result];
                            break;

                        case 'has':
                            foreach ($expect as $col) {
                                $result = (new Assert\HasProperty($response, $col))->assert();
                                $outputs[] = [$type, $col, $result];

                                if (! $result) {
                                    $requestError = true;
                                }
                            }
                            break;

                        case 'type':
                            foreach ($expect as $col => $val) {
                                $errors = (new Assert\Type($response, $col, $val))->assert();
                                $result = (count($errors) === 0);
                                $outputs[] = [$type, "$col:$val", $result];

                                if (! $result) {
                                    $requestError = true;
                                }
                            }
                            break;

                        case 'value':
                            foreach ($expect as $col => $val) {
                                $result = (new Assert\Value($response, $col, $val))->assert();
                                $outputs[] = [$type, "$col:$val", $result];

                                if (! $result) {
                                    $requestError = true;
                                }
                            }
                            break;

                        case 'redirect':
                            $result = (new Assert\Redirect($response, $expect))->assert();
                            $outputs[] = [$type, $expect, $result];
                            break;

                        default:
                            throw new \Exception('Unknown expect type given.');
                    }

                    if (! $result) {
                        $requestError = true;
                    }
                }

                if ($requestError) {
                    $hasError = true;
                }

                $line = "<comment>{$request['method']}\t{$request['url']}</comment>";

                if ($output->getVerbosity() == OutputInterface::VERBOSITY_NORMAL) {
                    if ($requestError) {
                        $line .= "\t<error>FAILED.</error>";
                    } else {
                        $line .= "\t<question>PASSED.</question>";
                    }
                }

                $output->writeln($line);

                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                    $vars = Parser::searchVars($compiled);
                    foreach ($vars as $var) {
                        $output->writeln('<error>unbound variable: '.$var.'</error>');
                    }

                    $description = $request['description'];
                    if (!empty($description)) {
                        $output->writeln("<comment>{$description}</comment>");
                    }

                    $output->writeln($response->getStatusCode());
                    $output->writeln((string)$response->getBody());
                }

                if ($requestError || $output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                    foreach ($outputs as $vars) {
                        $output->writeln($this->buildMessage($vars[0], $vars[1], $vars[2]));
                    }
                }
            }
        }

        if ($input->getOption('saikou')) {
            if ($hasError) {
                $output->write("Don't care!! Try again!!😊 \n");
            } else {
                $output->write("Congrats!!🍻 \n");
            }
        } else {
            $output->writeln("finished");
        }
    }

    /**
     * load plugin
     *
     * @param $filename
     */
    private function loadPlugins()
    {
        $pluginDir = __DIR__ . '/../../../../../plugins';
        if (file_exists($pluginDir)) {
            foreach (glob(rtrim($pluginDir, '/') . '/*.php') as $classFile) {
                require_once $classFile;
            }
        }
    }

    /**
     * load file of environment variables
     *
     * @param $filename
     */
    private function loadDotEnv($filename)
    {
        if ($filename) {
            $dotenv = new Dotenv(getcwd(), $filename);
            $dotenv->load();
        } else {
            try {
                $dotenv = new Dotenv(getcwd());
                $dotenv->load();
            } catch (InvalidPathException $e) {
                // .envが存在しないケース
            }
        }
    }

    /**
     * fetch target files.
     *
     * @param string $files
     * @return array
     */
    private function fetchTargets($files)
    {
        if ($files) {
            return $files;
        }

        $targets = [];
        $finder = new Finder();
        $finder->files()
            ->in(__DIR__ . '/../../../..')
            ->name('test*.yml');
        foreach ($finder as $file) {
            $targets[] = $file->getRealPath();
        }

        return $targets;
    }

    /**
     * fetch target dir in files.
     *
     * @param  string $dir
     * @return array
     */
    private function fetchDirTargets($dir)
    {
        // 相対パス
        if (substr($dir, 0, 1) == '.') {
            $targetDir = __DIR__ . '/../../../../../' . $dir;
        }
        // 絶対パス
        else {
            $targetDir = $dir;
        }

        $targets = [];
        $finder = new Finder();
        $finder->files()
            ->in($targetDir)
            ->name('*.yml')
            ->name('*.yaml');
        foreach ($finder as $file) {
            $targets[] = $file->getRealPath();
        }

        return $targets;
    }

    /**
     * fetch option --bind params.
     * @param $binds
     * @return array
     */
    private function fetchBinds($binds)
    {
        $params = [];
        if (count($binds) > 0) {
            foreach ($binds as $bind) {
                $bind = json_decode($bind, true);
                $params = array_merge($params, $bind);
            }
        }

        return $params;
    }

    /**
     * build test result message.
     *
     * @param $type
     * @param $value
     * @param $result
     * @return string
     */
    private function buildMessage($type, $value, $result)
    {
        if ($result) {
            $tag = 'question';
            $info = 'PASSED.';
        } else {
            $tag = 'error';
            $info = 'FAILED.';
        }
        return "\t<{$tag}>[{$type}]\t{$info}\t{$value}</{$tag}>";
    }

    /**
     * create binds from environment variables
     *
     * @param $raw
     * @return array
     */
    private function createEnvBinds($raw)
    {
        $result = [];
        $vars = Parser::searchVars($raw);
        foreach ($vars as $var) {
            $bind = getenv($var);
            if ($bind) {
                $result[$var] = $bind;
            }
        }
        return $result;
    }
}
