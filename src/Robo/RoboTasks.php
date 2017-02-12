<?php
/**
 * @copyright Copyright (c) 2017 Matthias Walter
 *
 * @see LICENSE
 */

namespace Mwltr\MageDeploy2\Robo;

use Consolidation\Log\ConsoleLogLevel;
use Mwltr\MageDeploy2\Config\Config;
use Mwltr\MageDeploy2\Config\ConfigAwareTrait;
use Mwltr\MageDeploy2\Robo\Task\GenerateConfigFileTask;
use Mwltr\MageDeploy2\Robo\Task\ValidateEnvironmentTask;
use Mwltr\Robo\Deployer\loadDeployerTasks;
use Mwltr\Robo\Magento2\loadMagentoTasks;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Robo\Common\Timer as RoboTimer;

/**
 * RoboTasks
 */
class RoboTasks extends \Robo\Tasks implements LoggerAwareInterface
{
    const MAGENTO_VENDOR_DIR = 'vendor';
    const MAGENTO_PATH_ENV_PHP = 'app/etc/env.php';

    use ConfigAwareTrait;
    use loadDeployerTasks;
    use loadMagentoTasks;

    use RoboTimer;
    use LoggerAwareTrait;

    public function __construct()
    {
        $this->init();
    }

    /**
     * Initialize Config
     */
    protected function init()
    {
        $this->initDeployConfig();

        $this->stopOnFail(true);
    }

    /**
     * @return GenerateConfigFileTask
     */
    protected function taskGenerateConfigFile()
    {
        return $this->createTask(GenerateConfigFileTask::class);
    }

    /**
     * @return ValidateEnvironmentTask
     */
    protected function taskDeployValidate()
    {
        return $this->createTask(ValidateEnvironmentTask::class);
    }

    /**
     * Build Task to update source code to desired branch/tag
     *
     * @param $branch
     *
     * @return \Mwltr\MageDeploy2\Robo\Task\UpdateSourceCodeTask
     */
    protected function taskUpdateSourceCode($branch)
    {
        /** @var \Mwltr\MageDeploy2\Robo\Task\UpdateSourceCodeTask $task */
        $task = $this->createTask(\Mwltr\MageDeploy2\Robo\Task\UpdateSourceCodeTask::class);
        $task->branch($branch);

        return $task;
    }

    /**
     * Build Task to clean all var dirs
     *
     * @return \Robo\Task\Filesystem\CleanDir
     */
    protected function taskMagentoCleanVarDirs()
    {
        $magentoDir = $this->config(Config::KEY_DEPLOY . '/' . Config::KEY_APP_DIR);
        $varDirs = $this->config(Config::KEY_DEPLOY . '/' . Config::KEY_CLEAN_DIRS);

        $dirs = [];
        foreach ($varDirs as $dir) {
            $dirPath = $dir;
            if (!empty($magentoDir)) {
                $dirPath = "{$magentoDir}/$dir";
            }
            if (!is_dir($dirPath)) {
                $this->logger->notice("File or directory <info>$dirPath</info> does not exist!");
                continue;
            }
            $dirs[] = $dirPath;
        }

        $task = $this->taskCleanDir($dirs);

        return $task;
    }

    /**
     * Build task to update dependencies using composer
     *
     * @param bool $dropVendor
     *
     * @return \Robo\Collection\CollectionBuilder
     */
    protected function taskMagentoUpdateDependencies($dropVendor = false)
    {
        $magentoDir = $this->config(Config::KEY_DEPLOY . '/' . Config::KEY_APP_DIR);
        $pathToComposer = $this->config(Config::KEY_ENV . '/' . Config::KEY_COMPOSER_BIN);

        $collection = $this->collectionBuilder();
        if ($dropVendor === true) {
            $vendorDir = self::MAGENTO_VENDOR_DIR;
            $dir = "$magentoDir/$vendorDir";
            $task = $this->taskDeleteDir($dir);

            $collection->addTask($task);
        }

        $task = $this->taskComposerInstall($pathToComposer);
        $task->dir($magentoDir);
        $task->noDev();
        $task->optimizeAutoloader();

        $collection->addTask($task);

        return $collection;
    }

    /**
     * Build Task to setup / upgrade Magento database
     *
     * @param bool $reinstallProject
     *
     * @return \Robo\Collection\CollectionBuilder
     */
    protected function taskMagentoSetup($reinstallProject = false)
    {
        $magentoDir = $this->config(Config::KEY_DEPLOY . '/' . Config::KEY_APP_DIR);
        $pathEnvPhp = self::MAGENTO_PATH_ENV_PHP;
        $envPhpFile = "$magentoDir/$pathEnvPhp";

        $collection = $this->collectionBuilder();

        $hasEnvPhp = is_file($envPhpFile);

        // Reinstall Project
        if ($reinstallProject === true && $hasEnvPhp) {
            $taskDeleteEnvPhp = $this->taskFilesystemStack();
            $taskDeleteEnvPhp->remove($envPhpFile);

            $collection->progressMessage("delete $envPhpFile");
            $collection->addTask($taskDeleteEnvPhp);
            $hasEnvPhp = false;
        }

        if (!$hasEnvPhp) {
            $options = $this->config(CONFIG::KEY_BUILD . '/' . Config::KEY_DB);

            $task = $this->taskMagentoSetupInstallTask();
            $task->options($options);
            $task->dir($magentoDir);
            $collection->addTask($task);
        }

        $task = $this->taskMagentoSetupUpgradeTask();
        $task->dir($magentoDir);
        $collection->addTask($task);

        return $collection;
    }

    /**
     * Build Task to set magento to production mode
     *
     * @return \Mwltr\Robo\Magento2\Task\DeploySetModeTask
     */
    protected function taskMagentoSetProductionMode()
    {
        $magentoDir = $this->config(Config::KEY_DEPLOY . '/' . Config::KEY_APP_DIR);

        $task = $this->taskMagentoDeploySetModeTask();
        $task->modeProduction();
        $task->skipCompilation();
        $task->dir($magentoDir);

        return $task;
    }

    /**
     * Build Task to run magento setup di compile
     *
     * @return \Mwltr\Robo\Magento2\Task\SetupDiCompileTask
     */
    protected function taskMagentoSetupDiCompile()
    {
        $magentoDir = $this->config(Config::KEY_DEPLOY . '/' . Config::KEY_APP_DIR);

        $task = $this->taskMagentoSetupDiCompileTask();
        $task->dir($magentoDir);

        return $task;
    }

    /**
     * Build Task to run magento setup static content deploy
     *
     * @return \Robo\Collection\CollectionBuilder
     */
    protected function taskMagentoSetupStaticContentDeploy()
    {
        /** @var array $themes */
        $themes = $this->config(Config::KEY_DEPLOY . '/' . Config::KEY_THEMES);
        $magentoDir = $this->config(Config::KEY_DEPLOY . '/' . Config::KEY_APP_DIR);

        $collection = $this->collectionBuilder();
        foreach ($themes as $theme) {
            if (!array_key_exists('code', $theme)) {
                throw new \RuntimeException('invalid theme config: code is missing');
            }

            $task = $this->taskMagentoSetupStaticContentDeployTask();
            $task->addTheme($theme['code']);
            $task->addLanguages($theme['languages']);
            $task->dir($magentoDir);

            $collection->addTask($task);
        }

        return $collection;
    }

    /**
     * Build Task for artifact creation
     *
     * @return \Robo\Collection\CollectionBuilder
     */
    protected function taskArtifactCreatePackages()
    {
        $tarBin = $this->config(Config::KEY_ENV . '/' . Config::KEY_TAR_BIN);
        $gitDir = $this->config(Config::KEY_DEPLOY . '/' . Config::KEY_GIT_DIR);
        $magentoDir = $this->config(Config::KEY_DEPLOY . '/' . Config::KEY_APP_DIR);

        /** @var array $assets */
        $assets = $this->config(Config::KEY_DEPLOY . '/' . Config::KEY_ASSETS);

        $collection = $this->collectionBuilder();
        $collection->progressMessage('cleanup old packages');

        // Cleanup old tars
        foreach ($assets as $assetName => $assetConfig) {
            $file = "$magentoDir/$assetName";

            $task = $this->taskFilesystemStack();
            $task->remove($file);

            $collection->addTask($task);
        }

        // Create Tars
        $collection->progressMessage('creating packages');
        foreach ($assets as $assetName => $assetConfig) {
            $dir = $assetConfig['dir'];

            $tarOptions = '';
            if (array_key_exists('options', $assetConfig)) {
                $options = $assetConfig['options'];

                $tarOptions = implode(' ', $options);
            }

            $tarCmd = "$tarBin $tarOptions -czf $assetName $dir";
            $task = $this->taskExec($tarCmd);
            $task->dir($gitDir);

            $collection->addTask($task);
        }

        return $collection;
    }

    /**
     * Build Task for deployer deploy
     *
     * @param string $stage
     * @param string $branch
     *
     * @return \Mwltr\Robo\Deployer\Task\DeployTask
     */
    protected function taskDeployerDeploy($stage, $branch)
    {
        $deployerBin = $this->config(Config::KEY_ENV . '/' . Config::KEY_DEPLOYER_BIN);

        $task = $this->taskDeployerDeployTask($deployerBin);
        $task->branch($branch);
        $task->stage($stage);

        // Map Verbosity
        $output = $this->output();
        if ($output->isDebug()) {
            $task->debug();
        } elseif ($output->isVeryVerbose()) {
            $task->veryVerbose();
        } elseif ($output->isVerbose()) {
            $task->verbose();
        } elseif ($output->isQuiet()) {
            $task->quiet();
        }

        return $task;
    }

    /**
     * Print Stage Starting Information
     *
     * @param string $msg
     */
    protected function printStageInfo($msg)
    {
        $this->yell($msg, 80, 'red');
    }

    /**
     * Print Task Starting Information
     *
     * @param string $msg
     */
    protected function printTaskInfo($msg)
    {
        $this->yell($msg, 80, 'blue');
    }

    /**
     * Print Runtime
     *
     * @param string $method
     */
    protected function printRuntime($method)
    {
        $context = [
            'time' => $this->getExecutionTime(),
            'timer-label' => 'in',
        ];
        $this->logger->log(ConsoleLogLevel::SUCCESS, "<info>$method</info> finished", $context);
    }

    protected function createTask()
    {
        $task = call_user_func_array(['parent', 'task'], func_get_args());
        $task->setInput($this->input());
        $task->setOutput($this->output());

        return $task;
    }

}