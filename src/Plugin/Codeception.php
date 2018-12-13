<?php

namespace PHPCensor\Plugin;

use PHPCensor\Builder;
use PHPCensor\Model\Build;
use PHPCensor\Plugin;
use PHPCensor\Plugin\Util\TestResultParsers\Codeception as Parser;
use PHPCensor\ZeroConfigPluginInterface;
use Symfony\Component\Yaml\Parser as YamlParser;

/**
 * Codeception Plugin - Enables full acceptance, unit, and functional testing.
 *
 * @author Don Gilbert <don@dongilbert.net>
 * @author Igor Timoshenko <contact@igortimoshenko.com>
 * @author Adam Cooper <adam@networkpie.co.uk>
 */
class Codeception extends Plugin implements ZeroConfigPluginInterface
{
    /**
     * Allows you to provide a path to the codeception binary (defaults to PHP Censor root)
     *
     * @var string
     */
    protected $executable;

    /** @var string */
    protected $args = '';

    /**
     * @var string $ymlConfigFile The path of a yml config for Codeception
     */
    protected $ymlConfigFile;

    /**
     * default sub-path for report.xml file
     * 
     * @var array $path The path to the report.xml file
     */
    protected $output_path = [
        'tests/_output',
        'tests/_log',
    ];


    /**
     * @return string
     */
    public static function pluginName()
    {
        return 'codeception';
    }

    /**
     * {@inheritdoc}
     */
    public function __construct(Builder $builder, Build $build, array $options = [])
    {
        parent::__construct($builder, $build, $options);

        if (empty($options['config'])) {
            $this->ymlConfigFile = self::findConfigFile($this->builder->buildPath);
        } else {
            $this->ymlConfigFile = $options['config'];
        }

        if (isset($options['args'])) {
            $this->args = (string) $options['args'];
        }

        // deprecated compatibility option
        if (isset($options['path']) && !isset($options['output_path'])) {
            $options['output_path'] = $options['grunpatht'];
        }

        if (isset($options['output_path'])) {
            array_unshift($this->output_path, $options['output_path']);
        }

        if (isset($options['executable'])) {
            $this->executable = $options['executable'];
        } else {
            $this->executable = $this->findBinary('codecept');
        }

    }

    /**
     * {@inheritdoc}
     */
    public static function canExecuteOnStage($stage, Build $build)
    {
        return Build::STAGE_TEST == $stage && !is_null(self::findConfigFile($build->getBuildPath()));
    }

    /**
     * Try and find the codeception YML config file.
     * @param $buildPath
     * @return null|string
     */
    public static function findConfigFile($buildPath)
    {
        if (file_exists($buildPath . 'codeception.yml')) {
            return 'codeception.yml';
        }

        if (file_exists($buildPath . 'codeception.dist.yml')) {
            return 'codeception.dist.yml';
        }

        return null;
    }

    /**
     * Runs Codeception tests
     */
    public function execute()
    {
        if (empty($this->ymlConfigFile)) {
            throw new \Exception("No configuration file found");
        }

        // Run any config files first. This can be either a single value or an array.
        return $this->runConfigFile($this->ymlConfigFile);
    }

    /**
     * Run tests from a Codeception config file.
     * @param $configPath
     * @return bool|mixed
     * @throws \Exception
     */
    protected function runConfigFile($configPath)
    {
        $codeception = $this->executable;

        if (!$codeception) {
            $this->builder->logFailure(sprintf('Could not find "%s" binary', 'codecept'));

            return false;
        }

        $cmd = 'cd "%s" && ' . $codeception . ' run -c "%s" ' . $this->args . ' --xml';

        $configPath = $this->builder->buildPath . $configPath;
        $success    = $this->builder->executeCommand($cmd, $this->builder->buildPath, $configPath);

        $parser = new YamlParser();
        $yaml   = file_get_contents($configPath);
        $config = (array) $parser->parse($yaml);

        $trueReportXmlPath = null;
        if ($config && isset($config['paths']['log'])) {
            $trueReportXmlPath = $this->builder->buildPath . $config['paths']['log'] . '/';
        }

        if (!file_exists($trueReportXmlPath . 'report.xml')) {
            foreach ($this->output_path as $output_path) {
                $trueReportXmlPath = $this->builder->buildPath . rtrim($output_path, '/\\') . '/';
                if (file_exists($trueReportXmlPath . 'report.xml')) {
                    break;
                }
            }
        }

        $parser = new Parser($this->builder, ($trueReportXmlPath . 'report.xml'));
        $output = $parser->parse();

        $meta = [
            'tests'     => $parser->getTotalTests(),
            'timetaken' => $parser->getTotalTimeTaken(),
            'failures'  => $parser->getTotalFailures(),
        ];

        $this->build->storeMeta((self::pluginName() . '-meta'), $meta);
        $this->build->storeMeta((self::pluginName() . '-data'), $output);
        $this->build->storeMeta((self::pluginName() . '-errors'), $parser->getTotalFailures());

        return $success;
    }
}
