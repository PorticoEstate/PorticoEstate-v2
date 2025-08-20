<?php

/**
 * Use this class to transform HTML to PDF using Chromium headless
 * Drop-in replacement for SnappyPdf
 */
class ChromiumPdf
{
    protected $executable = '/usr/bin/chromium';
    protected $defaultExtension = 'pdf';
    protected $options = array(
        'format' => 'A4',
        'margin-top' => '0.75in',
        'margin-right' => '0.75in',
        'margin-bottom' => '0.75in',
        'margin-left' => '0.75in',
        'print-background' => true,
    );

    /**
     * Set the executable path (for compatibility with SnappyPdf)
     */
    public function setExecutable($executable)
    {
        // Accept a custom executable if it exists and is executable
        if ($executable && is_string($executable) && is_executable($executable)) {
            $this->executable = $executable;
        }
    }

    /**
     * Set an option (for compatibility with SnappyPdf)
     */
    public function setOption($option, $value = null)
    {
        $this->options[$option] = $value;
    }

    /**
     * Save HTML file or URL to PDF
     */
    public function save($input, $outputPath)
    {
        $command = $this->buildCommand($input, $outputPath);
        
        $basePath = dirname($outputPath);
        if (!is_dir($basePath)) {
            mkdir($basePath, 0777, true);
        }
        
        if (file_exists($outputPath)) {
            unlink($outputPath);
        }
        
        // Execute and capture output (useful for debugging)
        $result = shell_exec($command . ' 2>&1');

        $ok = file_exists($outputPath) && filesize($outputPath) > 0;
        if (!$ok) {
            @error_log('[ChromiumPdf] Command failed: ' . $command);
            if ($result) {
                @error_log('[ChromiumPdf] Output: ' . substr($result, 0, 4000));
            }
        }
        return $ok;
    }

    /**
     * Output PDF to browser (for compatibility with SnappyPdf)
     */
    public function output($input)
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'chromium_pdf') . '.pdf';
        
        if ($this->save($input, $tempFile)) {
            header('Content-Type: application/pdf');
            readfile($tempFile);
            unlink($tempFile);
        } else {
            throw new Exception('Failed to generate PDF with Chromium');
        }
    }

    /**
     * Build the Chromium command
     */
    protected function buildCommand($input, $outputPath)
    {
        $binary = $this->resolveExecutable();
        // Ensure Chromium has writable locations for profile and crash dumps
        $tmpDir = sys_get_temp_dir();
        $userDataDir = $tmpDir . '/chromium-user-data';
        $crashDumpsDir = $tmpDir . '/chromium-crash-dumps';
        if (!is_dir($userDataDir)) {
            @mkdir($userDataDir, 0700, true);
        }
        if (!is_dir($crashDumpsDir)) {
            @mkdir($crashDumpsDir, 0700, true);
        }

    // Prefix with HOME and XDG_RUNTIME_DIR to ensure writable locations
    // Also unset DBus addresses to avoid spurious DBus connection attempts in containers
    $env = 'HOME=' . escapeshellarg($tmpDir)
         . ' XDG_RUNTIME_DIR=' . escapeshellarg($tmpDir)
         . ' DBUS_SESSION_BUS_ADDRESS='
         . ' DBUS_SYSTEM_BUS_ADDRESS=';
    $command = $env . ' ' . $binary;
        
        // Basic headless options
        $command .= ' --headless --disable-gpu --no-sandbox --disable-dev-shm-usage';

        // Hardening and stability flags for containers
    $command .= ' --no-first-run --no-default-browser-check --disable-extensions --disable-background-networking';
    $command .= ' --disable-crash-reporter --disable-breakpad';
    $command .= ' --disable-features=MojoDbusServices';
    // Often helps inside containers when running without sandbox
    $command .= ' --no-zygote';

        // Ensure Chromium knows where to write its profile and crash dumps
        $command .= ' --user-data-dir=' . escapeshellarg($userDataDir);
        $command .= ' --crash-dumps-dir=' . escapeshellarg($crashDumpsDir);
        
        // PDF-specific options
        $command .= ' --allow-file-access-from-files';
        $command .= ' --print-to-pdf=' . escapeshellarg($outputPath);
        
        // Add format if specified
        if (isset($this->options['format'])) {
            $command .= ' --print-to-pdf-no-header';
        }
        
        // Add the input (file or URL)
        if (file_exists($input)) {
            $command .= ' ' . escapeshellarg('file://' . realpath($input));
        } else {
            $command .= ' ' . escapeshellarg($input);
        }
        
        return $command;
    }

    /**
     * Try to resolve a working Chromium/Chrome executable path across distros
     */
    protected function resolveExecutable()
    {
        $candidates = array(
            $this->executable,
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
        );

        foreach ($candidates as $bin) {
            if ($bin && @is_executable($bin)) {
                return $bin;
            }
        }

        // Fallback to PATH lookup
        $which = @shell_exec('command -v chromium 2>/dev/null');
        if ($which) {
            $which = trim($which);
            if (@is_executable($which)) {
                return $which;
            }
        }
        $which = @shell_exec('command -v chromium-browser 2>/dev/null');
        if ($which) {
            $which = trim($which);
            if (@is_executable($which)) {
                return $which;
            }
        }
        $which = @shell_exec('command -v google-chrome 2>/dev/null');
        if ($which) {
            $which = trim($which);
            if (@is_executable($which)) {
                return $which;
            }
        }

        // As a last resort, return the original (may fail at runtime)
        return $this->executable;
    }
}