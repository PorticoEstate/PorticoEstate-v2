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
        // For compatibility, but we'll use chromium regardless
        $this->executable = '/usr/bin/chromium';
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
        
        $result = shell_exec($command . ' 2>&1');
        
        return file_exists($outputPath) && filesize($outputPath) > 0;
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
        $command = $this->executable;
        
        // Basic headless options
        $command .= ' --headless --disable-gpu --no-sandbox --disable-dev-shm-usage';
        
        // PDF-specific options
        $command .= ' --print-to-pdf="' . $outputPath . '"';
        
        // Add format if specified
        if (isset($this->options['format'])) {
            $command .= ' --print-to-pdf-no-header';
        }
        
        // Add the input (file or URL)
        if (file_exists($input)) {
            $command .= ' "file://' . realpath($input) . '"';
        } else {
            $command .= ' "' . $input . '"';
        }
        
        return $command;
    }
}