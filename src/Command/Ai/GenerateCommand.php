<?php

namespace Drupal\Console\Command\Ai;

use Drupal\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\PhpExecutableFinder;

class GenerateCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('generate:ai')
            ->setDescription('Generates a Drupal module/theme using AI based on user specifications via a web form.')
            ->setHelp('This command starts a local web server to gather your requirements for a module or theme, then uses AI to generate the code.');
        // Add options here if any
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        // Interaction logic if needed before execute
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $host = '127.0.0.1';
        $port = 8088;
        // Determine project root relative to this file.
        // Assumes src/Command/Ai/GenerateCommand.php
        $projectRoot = __DIR__ . '/../../../../';
        $docRoot = $projectRoot . '/resources/ai_web_form';

        if (!is_dir($docRoot)) {
            if (!mkdir($docRoot, 0777, true) && !is_dir($docRoot)) {
                $output->writeln(sprintf('<error>Directory "%s" was not created</error>', $docRoot));
                return 1;
            }
            $output->writeln(sprintf('<info>Created document root directory: %s</info>', $docRoot));
        }

        if (!file_exists($docRoot . '/index.html')) {
            file_put_contents($docRoot . '/index.html', '<h1>AI Form Page - Coming Soon</h1><p>This page will host the form to specify your Drupal module or theme requirements for AI generation.</p>');
            $output->writeln(sprintf('<info>Created placeholder index.html in %s</info>', $docRoot));
        }

        $phpExecutableFinder = new PhpExecutableFinder();
        $phpExecutable = $phpExecutableFinder->find();
        if (!$phpExecutable) {
            $output->writeln('<error>PHP executable not found.</error>');
            return 1;
        }

        $serverCommand = [$phpExecutable, '-S', $host . ':' . $port, '-t', $docRoot];
        $process = new Process($serverCommand);
        $process->setTimeout(null); // Run indefinitely until stopped

        $output->writeln(sprintf('<info>Starting PHP development server at http://%s:%d</info>', $host, $port));
        $output->writeln('<info>Document root: ' . realpath($docRoot) . '</info>');
        $output->writeln('<info>Press Ctrl+C to stop the server.</info>');

        // Attempt to open browser
        $opener = null;
        if (PHP_OS_FAMILY === 'Darwin') { // macOS
            $opener = 'open';
        } elseif (PHP_OS_FAMILY === 'Windows') {
            $opener = 'start';
        } else { // Linux, BSD, etc.
            $opener = 'xdg-open';
        }

        $url = sprintf('http://%s:%d', $host, $port);
        try {
            // Small delay to give the server a moment to start before opening the browser
            // Required especially on slower systems or if server startup is not instantaneous
            if (PHP_OS_FAMILY !== 'Windows') { // sleep function behaves differently on Windows with Process
                 sleep(1);
            }

            $browserProcess = new Process([$opener, $url]);
            $browserProcess->disableOutput(); // We don't need the output of the browser opener
            $browserProcess->run(); // Using run() as mustRun() might throw exception if command not found
                                    // and we want to provide a graceful fallback.
            if (!$browserProcess->isSuccessful() && !file_exists('/.dockerenv')) { // Check if not in Docker before warning
                 $output->writeln(sprintf('<comment>Attempted to open browser with "%s". If it failed, please navigate to %s manually.</comment>', $opener, $url));
            }
        } catch (\Exception $e) {
            $output->writeln(sprintf('<comment>Could not open browser automatically. Please navigate to %s manually. Error: %s</comment>', $url, $e->getMessage()));
        }
        
        // This will block until Ctrl+C or process is killed.
        $process->run(function ($type, $buffer) use ($output) {
            // Stream server output to console, useful for debugging server errors
            // For now, we'll keep it commented to avoid verbose output unless needed
            // if (Process::ERR === $type) {
            //     $output->write('<error>' . $buffer . '</error>');
            // } else {
            //     $output->write('<info>' . $buffer . '</info>');
            // }
        });

        if (!$process->isSuccessful()) {
            $output->writeln('<error>PHP development server failed to start or was stopped with an error.</error>');
            $errorOutput = $process->getErrorOutput();
            if (!empty($errorOutput)) {
                $output->writeln('<error>' . $errorOutput . '</error>');
            }
            return 1;
        }

        $output->writeln(''); // Newline for clarity
        $output->writeln('<comment>PHP development server stopped.</comment>');

        $tempFile = sys_get_temp_dir() . '/drupal_console_ai_output.json';
        $output->writeln('<info>Checking for AI response at: ' . $tempFile . '</info>');

        if (file_exists($tempFile)) {
            $jsonData = file_get_contents($tempFile);
            
            if (unlink($tempFile)) {
                $output->writeln('<comment>Temporary AI response file deleted successfully.</comment>');
            } else {
                $output->writeln('<warning>Could not delete temporary AI response file: ' . $tempFile . '</warning>');
            }

            $aiResponse = json_decode($jsonData, true);

            if (json_last_error() === JSON_ERROR_NONE && isset($aiResponse['status'])) {
                $output->writeln('<info>Successfully parsed AI response:</info>');
                if ($aiResponse['status'] === 'success') {
                    $output->writeln('  <info>Status: Success</info>');
                    $output->writeln('  <info>Suggested Machine Name: ' . htmlspecialchars($aiResponse['suggested_machine_name'] ?? 'N/A') . '</info>');
                    if (!empty($aiResponse['files'])) {
                        $output->writeln('  <info>Files to be generated:</info>');
                        foreach ($aiResponse['files'] as $fileInfo) {
                            $output->writeln('    - ' . htmlspecialchars($fileInfo['path'] ?? 'N/A'));
                        }
                    }
                    if (!empty($aiResponse['messages'])) {
                        $output->writeln('  <info>AI Messages:</info>');
                        foreach ($aiResponse['messages'] as $message) {
                            $output->writeln('    - ' . htmlspecialchars($message));
                        }
                    }
                    $output->writeln('');
                    // $output->writeln('<comment>Next step: Implement file generation based on this AI response.</comment>');

                    $drupalRoot = null;
                    try {
                        $drupalRoot = $this->getDrupalHelper()->getRoot();
                    } catch (\Exception $e) {
                        $output->writeln('<warning>Could not determine Drupal root: ' . $e->getMessage() . '</warning>');
                        $output->writeln('<comment>The "Save to Drupal site" option will be disabled or may not work as expected.</comment>');
                    }

                    $dialog = $this->getDialogHelper();
                    $choices = [
                        'save_to_site' => 'Save to Drupal site',
                        'download_zip' => 'Download as ZIP file',
                        'cancel' => 'Cancel',
                    ];

                    $questionParts = ['How would you like to receive the generated code?'];
                    if ($drupalRoot && isset($aiResponse['type']) && isset($aiResponse['suggested_machine_name'])) {
                        $baseDirForChoice = ($aiResponse['type'] === 'theme') ? 'themes/custom' : 'modules/custom';
                        $targetPathForChoice = $drupalRoot . '/' . $baseDirForChoice . '/' . $aiResponse['suggested_machine_name'];
                        $choices['save_to_site'] .= ' (to ' . $targetPathForChoice . ')';
                    } elseif (!$drupalRoot) {
                         // Optionally disable or modify the choice if Drupal root isn't found
                         // For now, we just won't append the path. User will get an error if they select it.
                    }
                    
                    $question = $dialog->getQuestion(
                        implode("\n", $questionParts),
                        'cancel', // Default choice
                        '?'
                    );
                    $userChoiceKey = $dialog->ask($output, $question, 'cancel', $choices);

                    switch ($userChoiceKey) {
                        case 'save_to_site':
                            if (!$drupalRoot) {
                                $output->writeln('<error>Drupal root not found. Cannot save to site. Please ensure you are running this command within a Drupal site.</error>');
                                break;
                            }
                            if (empty($aiResponse['suggested_machine_name'])) {
                                $output->writeln('<error>AI response did not provide a "suggested_machine_name". Cannot save to site.</error>');
                                break;
                            }
                            if (empty($aiResponse['type'])) {
                                $output->writeln('<error>AI response did not provide a "type" (module/theme). Cannot save to site.</error>');
                                break;
                            }

                            $baseDir = ($aiResponse['type'] === 'theme') ? 'themes/custom' : 'modules/custom';
                            $modulePath = $drupalRoot . '/' . $baseDir . '/' . $aiResponse['suggested_machine_name'];

                            if (!is_dir($modulePath)) {
                                if (!mkdir($modulePath, 0755, true)) {
                                    $output->writeln('<error>Could not create directory: ' . $modulePath . '</error>');
                                    break;
                                }
                                $output->writeln('<info>Created base directory: ' . $modulePath . '</info>');
                            }
                            
                            $allFilesSaved = true;
                            if (!empty($aiResponse['files']) && is_array($aiResponse['files'])) {
                                foreach ($aiResponse['files'] as $fileInfo) {
                                    if (empty($fileInfo['path']) || !isset($fileInfo['content'])) {
                                        $output->writeln('<warning>Skipping a file due to missing path or content in AI response.</warning>');
                                        continue;
                                    }
                                    
                                    // Adjust path: AI provides path like "module_name/file.yml", we need "file.yml"
                                    $pathParts = explode('/', $fileInfo['path']);
                                    if (count($pathParts) > 1 && $pathParts[0] === $aiResponse['suggested_machine_name']) {
                                        $pathWithinModule = implode('/', array_slice($pathParts, 1));
                                    } else {
                                        $pathWithinModule = $fileInfo['path'];
                                    }
                                    if (empty($pathWithinModule)) { // Handle cases where path might become empty after stripping
                                        $output->writeln('<warning>Skipping a file due to empty path after processing AI response for: ' . htmlspecialchars($fileInfo['path']) . '</warning>');
                                        continue;
                                    }


                                    $fullFilePath = $modulePath . '/' . $pathWithinModule;
                                    $dirName = dirname($fullFilePath);

                                    if (!is_dir($dirName)) {
                                        if (!mkdir($dirName, 0755, true) && !is_dir($dirName)) {
                                            $output->writeln('<error>Could not create directory: ' . $dirName . '</error>');
                                            $allFilesSaved = false;
                                            continue; // Skip this file
                                        }
                                    }

                                    if (file_put_contents($fullFilePath, $fileInfo['content']) === false) {
                                        $output->writeln('<error>Could not write file: ' . $fullFilePath . '</error>');
                                        $allFilesSaved = false;
                                    } else {
                                        $output->writeln('<info>Created file: ' . $fullFilePath . '</info>');
                                    }
                                }

                                if ($allFilesSaved) {
                                    $output->writeln('');
                                    $output->writeln('<info>Module/theme successfully saved to ' . $modulePath . '</info>');
                                    $output->writeln('<comment>Remember to review all generated code before enabling or using it on a production site.</comment>');
                                } else {
                                    $output->writeln('');
                                    $output->writeln('<error>Some files could not be saved. Please check the output above for errors.</error>');
                                }

                            } else {
                                $output->writeln('<warning>No files were specified in the AI response to save.</warning>');
                            }
                            break;

                        case 'download_zip':
                            if (!class_exists('ZipArchive')) {
                                $output->writeln('<error>PHP ZipArchive extension is required but not installed. Cannot create ZIP file.</error>');
                                break;
                            }

                            if (empty($aiResponse['suggested_machine_name'])) {
                                $output->writeln('<error>AI response did not provide a "suggested_machine_name". Cannot create ZIP file.</error>');
                                break;
                            }
                            if (empty($aiResponse['files']) || !is_array($aiResponse['files'])) {
                                $output->writeln('<error>AI response did not provide files to package. Cannot create ZIP file.</error>');
                                break;
                            }

                            $zipFileName = $aiResponse['suggested_machine_name'] . '.zip';
                            $zipFilePath = sys_get_temp_dir() . '/' . $zipFileName;

                            $zip = new \ZipArchive();
                            if ($zip->open($zipFilePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === TRUE) {
                                foreach ($aiResponse['files'] as $fileInfo) {
                                    if (empty($fileInfo['path']) || !isset($fileInfo['content'])) {
                                        $output->writeln('<warning>Skipping a file in ZIP due to missing path or content in AI response for: ' . htmlspecialchars($fileInfo['path'] ?? 'N/A') . '</warning>');
                                        continue;
                                    }
                                    // The path from AI response should be relative to the module root.
                                    // e.g., "ai_generated_module/ai_generated_module.info.yml" or directly "ai_generated_module.info.yml"
                                    // We need to ensure it's correctly placed within the zip.
                                    // The current assumption is $fileInfo['path'] is already the correct relative path for inside the zip.
                                    $zip->addFromString($fileInfo['path'], $fileInfo['content']);
                                }
                                $zip->close();
                                $output->writeln('<info>Module/theme successfully packaged as ZIP: ' . $zipFilePath . '</info>');
                                $output->writeln('<comment>Remember to review all generated code before using it on a production site.</comment>');
                            } else {
                                $output->writeln('<error>Failed to create ZIP file at: ' . $zipFilePath . '</error>');
                            }
                            break;

                        case 'cancel':
                            $output->writeln('<comment>Operation cancelled.</comment>');
                            break;
                    }

                } else { // AI returned an error status
                    $output->writeln('  <error>Status: Error from AI</error>');
                    $output->writeln('  <error>Message: ' . htmlspecialchars($aiResponse['message'] ?? 'Unknown error from AI.') . '</error>');
                }
            } else {
                $output->writeln('<error>Error: Could not decode AI response from temporary file. JSON Error: ' . json_last_error_msg() . '</error>');
                $output->writeln('Raw content snippet: <comment>' . htmlspecialchars(substr($jsonData, 0, 500)) . (strlen($jsonData) > 500 ? '...' : '') . '</comment>');
            }
        } else {
            $output->writeln('<warning>AI response file (' . $tempFile . ') not found. Was the web form successfully submitted and data saved?</warning>');
        }

        return 0;
    }
}
