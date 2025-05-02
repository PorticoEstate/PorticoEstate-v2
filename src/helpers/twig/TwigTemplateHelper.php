<?php

namespace App\helpers\twig;

use App\helpers\Template;

/**
 * Helper class for migrating from .tpl templates to Twig
 */
class TwigTemplateHelper
{
    /**
     * Convert variables prepared for the legacy Template class to a format suitable for Twig
     *
     * @param array $vars Array of template variables
     * @param array $blocks Array mapping block names to arrays of block items
     * @return array Variables formatted for Twig
     */
    public static function convertVarsForTwig(array $vars, array $blocks = [])
    {
        // Copy all regular variables
        $twigVars = $vars;

        // Convert block arrays into arrays suitable for Twig loops
        foreach ($blocks as $blockName => $itemName)
        {
            if (isset($vars[$itemName]) && is_string($vars[$itemName]))
            {
                // This is a simple block - no need for special handling
                continue;
            }

            // If the block data is an array, convert it to a format suitable for Twig loops
            if (isset($vars[$itemName]) && is_array($vars[$itemName]))
            {
                $twigVars[$blockName] = $vars[$itemName];
            }
            else
            {
                $twigVars[$blockName] = [];
            }
        }

        return $twigVars;
    }

    /**
     * Check if a Twig template exists for the given template file
     *
     * @param string $templateFile The legacy template filename (.tpl)
     * @param string $twigDir The directory where Twig templates are stored
     * @return string|null The path to the Twig template if it exists, null otherwise
     */
    public static function getTwigTemplate($templateFile, $twigDir)
    {
        // Convert template filename to a Twig template filename
        $twigFile = str_replace('.tpl', '.twig', $templateFile);
        $twigPath = $twigDir . '/' . $twigFile;

        if (file_exists($twigPath))
        {
            return $twigPath;
        }

        return null;
    }

    /**
     * Create a modified Template class instance that checks for Twig templates first
     *
     * @param string $templateDir The template directory
     * @param string $unknowns Policy for handling unknown variables
     * @return Template The legacy Template class
     */
    public static function createTemplate($templateDir = '.', $unknowns = 'remove')
    {
        // For now, return the legacy Template
        // In the future, we can make this more sophisticated to choose between
        // the legacy Template and TwigTemplate based on configuration
        return new Template($templateDir, $unknowns);
    }

    /**
     * Create a TwigTemplate instance
     *
     * @param string $templateDir The template directory
     * @param string $unknowns Policy for handling unknown variables
     * @return TwigTemplate The TwigTemplate class
     */
    public static function createTwigTemplate($templateDir = '.', $unknowns = 'remove')
    {
        return new TwigTemplate($templateDir, $unknowns);
    }
}
