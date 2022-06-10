<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;

class Check extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'check {organization} {subrepo} {--phpversion=8.1} {--lockfile=} {--only-direct} {--force}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Check packages against PHP version';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $client = new \PrivatePackagist\ApiClient\Client();
        $client->authenticate(env('PACKAGIST_PUBLIC'), env('PACKAGIST_SECRET'));

        $organization = $this->input->getArgument('organization');
        $subrepositoryName = $this->input->getArgument('subrepo');
        $phpVersionToCheck = $this->input->getOption('phpversion');
        $composerLockFile = $this->input->getOption('lockfile');
        $onlyDirect = $this->input->getOption('only-direct');
        $force = $this->input->getOption('force');

        if ($onlyDirect && !$composerLockFile) {
            $this->output->writeln('<error>If --only-direct is passed, you also have to pass in the composer.lock file path');
            return;
        }

        $packages = $client->subrepositories()->packages()->all($subrepositoryName);

        $table = $this->output->createTable();
        $table->setHeaders(['Package', 'Status', 'Constraint', 'Final result']);

        $results = [];
        $filename = 'results/results_' . $subrepositoryName . '.json';

        if (file_exists($filename) && !$force) {
            $results = json_decode(file_get_contents($filename), true);
            $this->output->writeln('JSON file found, outputting those values. Remove ' . $filename . ' to fetch new data');
            foreach ($results as $data) {
                $table->addRow($data);
            }
            $table->render();

            $this->outputSummary($results, $subrepositoryName);

            return;
        }

        $composerJsonFile = str_replace('.lock', '.json', $composerLockFile);
        if ($onlyDirect && file_exists($composerJsonFile)) {
            $directComposerPackages = array_keys(json_decode(file_get_contents($composerJsonFile), true)['require']);
        } else {
            if ($composerLockFile && file_exists($composerLockFile)) {
                $composerLockPackages = array_column(json_decode(file_get_contents($composerLockFile), true)['packages'], 'name');
            } else {
                $this->output->writeln('No Composer lock file given or found, proceeding to check all packages in Private Packagist\'s subrepo');
            }
        }

        foreach ($packages as $package) {
            if (stripos($package['name'], 'magento/') === 0) {
                continue;
            }

            // Skip packages that aren't in the composer.lock file
            if (isset($composerLockPackages) && !in_array($package['name'], $composerLockPackages)) {
                continue;
            }

            // If --direct option is passed, skip packages that aren't directly required by the root composer.json
            if (isset($directComposerPackages) && !in_array($package['name'], $directComposerPackages)) {
                continue;
            }

            $process = new Process(['composer2', 'show', '-a', $package['name'], '--format', 'json']);
            $process->run();

            while ($process->isRunning()) {
                // waiting for process to finish
            }

            $response = $process->getOutput();

            $json = json_decode($response, true);

            $testPackageCompatibility = false;
            $finalResult = '';
            if (is_array($json) && isset($json['requires']) && isset($json['requires']['php'])) {
                $constraint = $json['requires']['php'];
                if (\Composer\Semver\Semver::satisfies($phpVersionToCheck, $constraint)) {
                    $result = '<info>Compatible</info>';
                    $finalResult = 'OK';
                    if (stripos($constraint, $phpVersionToCheck) === false) {
                        $result = '<comment>Risky</comment>';
                        $testPackageCompatibility = true;
                    }
                } else {
                    $result = '<error>Incompatible</error>';
                    $finalResult = 'Incompatible';
                }
            } else {
                $result = '<comment>Unknown</comment>';
                $testPackageCompatibility = true;
                $constraint = '';
            }

            $process = new Process(['vendor/bin/phpcs', '--config-set', 'ignore_warnings_on_exit', '1']);
            $process->run();

            if ($testPackageCompatibility) {
                $clonePath = '/tmp/' . md5($package['name']);

                if ($package['config']['url']) {
                    $gitUrl = $package['config']['url'];
                    if (str_contains($gitUrl, 'https://')) {
                        $gitUrl = str_replace('https://', 'git@', $gitUrl);
                        $gitUrl = $this->str_replace_first('/', ':', $gitUrl);
                    }
                    $clone = new Process(['git', 'clone', $gitUrl, $clonePath]);
                    $clone->run();
                } else {
                    $packageInfo = $client->subrepositories()->packages()->show($subrepositoryName, $package['name']);
                    if (isset($packageInfo['versions']) && count($packageInfo['versions'])) {
                        $version = $packageInfo['versions'][0]['versionNormalized'];
                    } else {
                        $version = 'dev-master';
                    }
                    $reference = null;
                    $type = 'zip';
                    $downloadUrl = $this->getDownloadUrl($organization, $subrepositoryName, $package['name'], $version, $reference, $type);

                    $tmpFile = tempnam('tmp', 'pcc');

                    $process = new Process(['wget', $downloadUrl, '-O', $tmpFile]);
                    $process->run();

                    // Try dev-master when latest version isn't found
                    if ($process->getExitCode() && $version !== 'dev-master') {
                        $downloadUrl = $this->getDownloadUrl($organization, $subrepositoryName, $package['name'], 'dev-master', $reference, $type);
                        $process = new Process(['wget', $downloadUrl, '-O', $tmpFile]);
                        $process->run();
                    }

                    // Try dev-main instead of dev-master when download fails
                    if ($process->getExitCode()) {
                        $downloadUrl = $this->getDownloadUrl($organization, $subrepositoryName, $package['name'], 'dev-main', $reference, $type);
                        $process = new Process(['wget', $downloadUrl, '-O', $tmpFile]);
                        $process->run();
                    }

                    $process = new Process(['unzip', $tmpFile, '-d', $clonePath]);
                    $process->run();
                    $process = new Process(['rm', $tmpFile]);
                    $process->run();
                }

                try {
                    $process = new Process(
                        command: ['vendor/bin/phpcs', '-p', $clonePath, '--standard=vendor/phpcompatibility/php-compatibility/PHPCompatibility', '--extensions=php,phtml', '--runtime-set', 'testVersion', $phpVersionToCheck],
                        timeout: 60 * 60
                    );
                    $process->run();
                    $finalResult = $process->getExitCodeText();
                } catch (\Exception $e) {
                    $finalResult = $e->getMessage();
                }

                if ($finalResult === 'General error') {
                    file_put_contents('errors/' . str_replace('/', '-', $package['name']) . '-log.txt', $process->getOutput());
                }

                $process = new Process(['rm -r', $clonePath]);
                $process->run();
            }

            if ($finalResult !== 'OK') {
                $finalResult = '<error>' . $finalResult . '</error>';
            }

            $results[] = [$package['name'], strip_tags($result), $constraint, strip_tags($finalResult)];
            $table->appendRow([$package['name'], $result, $constraint, $finalResult]);

            file_put_contents($filename, json_encode($results));

            $this->outputSummary($results, $subrepositoryName);
        }
    }

    private function str_replace_first($search, $replace, $subject)
    {
        $search = '/' . preg_quote($search, '/') . '/';
        return preg_replace($search, $replace, $subject, 1);
    }

    /**
     * @param $organization
     * @param $subrepositoryName
     * @param $name
     * @param string $version
     * @param $reference
     * @param string $type
     * @return string
     */
    private function getDownloadUrl($organization, $subrepositoryName, $name, string $version, $reference, string $type): string
    {
        return sprintf('https://%s:%s@repo.packagist.com/%s/%s/dists/%s/%s/r%s.%s', env('COMPOSER_USER'), env('COMPOSER_KEY'), $organization, $subrepositoryName, $name, $version, $reference, $type);
    }

    /**
     * @param $results
     * @param $subrepositoryName
     * @return void
     */
    private function outputSummary($results, $subrepositoryName): void
    {
        $values = array_count_values(array_column($results, 3));
        $compatible = 0;
        if (isset($values['OK'])) {
            $compatible = $values['OK'];
        }
        $this->output->write('Summary for ' . $subrepositoryName . ': ');
        $this->output->writeln('Compatible: ' . $compatible . ' / ' . count($results) . ' (' . round(($compatible / count($results)) * 100, 0) . '%)');
    }
}
