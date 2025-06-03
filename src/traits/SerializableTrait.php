<?php

namespace App\traits;

trait SerializableTrait
{

    /**
     * @Exclude
     */
    private static $annotationCache = [];



    public function serialize(array $context = [], bool $short = false): ?array
    {
        $reflection = new \ReflectionClass($this);
        $properties = $reflection->getProperties();
        $defaultBehavior = $this->getClassDefaultBehavior($reflection);
        $serialized = [];

        foreach ($properties as $property)
        {
            $exposeAnnotation = $this->parseExposeAnnotation($property);
            $excludeAnnotation = $this->parseExcludeAnnotation($property);
            $shortAnnotation = $this->parseShortAnnotation($property);
            $serializeAsAnnotation = $this->parseSerializeAsAnnotation($property);
            $escapeStringAnnotation = $this->parseEscapeStringAnnotation($property);
            $defaultAnnotation = $this->parseDefaultAnnotation($property);
            $timestampAnnotation = $this->parseTimestampAnnotation($property);

            if ($excludeAnnotation)
            {
                continue;
            }

            if ($short && !$shortAnnotation)
            {
                continue; // Skip non-short properties when short serialization is requested
            }

            if ($exposeAnnotation || $defaultBehavior === 'expose')
            {
                $property->setAccessible(true);
                $value = $property->getValue($this);

                // If value is null and default annotation exists, use default value
                if ($value === null && $defaultAnnotation !== null) {
                    $value = $defaultAnnotation;
                }

                if ($this->shouldExposeProperty($exposeAnnotation, $property, $context))
                {
                    if (is_string($value))
                    {
                        if ($escapeStringAnnotation !== null)
                        {
                            $value = $this->processStringEscaping($value, $escapeStringAnnotation);
                        }
                    }

                    if ($serializeAsAnnotation)
                    {
                        $value = $this->serializeAs($value, $serializeAsAnnotation);
                    }
                    else if ($timestampAnnotation && $value !== null)
                    {
                        $value = $this->formatTimestamp($value, $timestampAnnotation);
                    }

                    if ($value !== null)
                    {
                        $serialized[$property->getName()] = $value;
                    }
                }
                else if ($defaultAnnotation !== null)
                {
                    // Use default value when property is not exposed
                    $serialized[$property->getName()] = $defaultAnnotation;
                }
            }
        }

        return !empty($serialized) ? $serialized : null;
    }

    private function parseDefaultAnnotation(\ReflectionProperty $property): ?string
    {
        $className = $property->getDeclaringClass()->getName();
        $propertyName = $property->getName();

        if (!isset(self::$annotationCache[$className]['properties'][$propertyName]['default']))
        {
            $docComment = $property->getDocComment();
            if (preg_match('/@Default\("([^"]+)"\)/', $docComment, $matches)) {
                self::$annotationCache[$className]['properties'][$propertyName]['default'] = $matches[1];
            } else {
                self::$annotationCache[$className]['properties'][$propertyName]['default'] = null;
            }
        }

        return self::$annotationCache[$className]['properties'][$propertyName]['default'];
    }

    private function shouldExposeProperty(?array $exposeAnnotation, \ReflectionProperty $property, array $context): bool
    {
        // If no conditions are set, expose based on roles
        if (empty($exposeAnnotation['when']))
        {
            return $this->checkRoleAccess($exposeAnnotation, $context);
        }

        // Check each condition
        foreach ($exposeAnnotation['when'] as $condition)
        {
            if ($this->evaluateCondition($condition, $context))
            {
                return true;
            }
        }

        return false;
    }

    private function evaluateCondition(string $condition, array $context): bool
    {
        // Clean any remaining whitespace/quotes
        $condition = trim($condition, " \t\n\r\0\x0B\"'");

        // Split by AND (&&) operator
        $andConditions = explode('&&', $condition);

        foreach ($andConditions as $andCondition) {
            if (!$this->evaluateSingleCondition(trim($andCondition), $context)) {
                return false;
            }
        }

        return true;
    }

    private function evaluateSingleCondition(string $condition, array $context): bool
    {
        // Split condition into field and value
        $parts = explode('=', $condition);
        if (count($parts) !== 2) {
            return false;
        }

        $field = trim($parts[0]);
        $value = trim($parts[1]);

        // Handle special variables
        if (str_starts_with($value, '$')) {
            $contextKey = substr($value, 1);
            $contextValue = $context[$contextKey] ?? null;

            // Get actual field value
            $actualValue = $this->$field;

            // If context value is an array, check if the actual value is in it
            if (is_array($contextValue)) {
                return in_array($actualValue, $contextValue);
            }

            // Regular comparison for non-array values
            return $actualValue == $contextValue;
        }

        // Regular comparison for non-context values
        return $this->$field == $value;
    }

    /**
     * Parse @EscapeString annotation
     * @return array|null Returns null if no annotation is present
     */
    private function parseEscapeStringAnnotation(\ReflectionProperty $property): ?array
    {
        $className = $property->getDeclaringClass()->getName();
        $propertyName = $property->getName();

        if (!isset(self::$annotationCache[$className]['properties'][$propertyName]['escapeString']))
        {
            $docComment = $property->getDocComment();

            if (preg_match('/@EscapeString\((.*?)\)/', $docComment, $matches))
            {
                $options = [];

                // Parse options if they exist
                if (!empty($matches[1]))
                {
                    preg_match_all('/(\w+)\s*=\s*([^,\)]+)/', $matches[1], $optionMatches, PREG_SET_ORDER);
                    foreach ($optionMatches as $match)
                    {
                        $optionName = trim($match[1]);
                        $optionValue = trim($match[2], '"\'');
                        $options[$optionName] = $optionValue;
                    }
                }

                self::$annotationCache[$className]['properties'][$propertyName]['escapeString'] = $options;
            } else
            {
                self::$annotationCache[$className]['properties'][$propertyName]['escapeString'] = null;
            }
        }

        return self::$annotationCache[$className]['properties'][$propertyName]['escapeString'];
    }

    /**
     * Process string escaping based on annotation options
     */
    protected function processStringEscaping(string $value, array $options): string
    {
        $mode = $options['mode'] ?? 'default';

        switch ($mode)
        {
            case 'html':
                // Only decode HTML entities
                return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            case 'preserve_newlines':
                // Preserve newline characters while decoding other entities
                $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                return str_replace(['\n', '\r'], ["\n", "\r"], $decoded);

            case 'encode':
                // Encode special characters (useful for output to HTML)
                return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            case 'none':
                // Don't perform any escaping
                return $value;

            case 'default':
            default:
                // Full sanitization
                return $this->sanitizeString($value);
        }
    }

    /**
     * Sanitize a string value by decoding HTML entities and handling special characters
     */
    protected function sanitizeString(string $value): string
    {
        // First decode any HTML entities
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Handle any remaining special character sequences
        $decoded = preg_replace_callback(
            '/&#(\d+);/',
            function ($matches)
            {
                return chr($matches[1]);
            },
            $decoded
        );

        // Clean up any double-encoded entities
        if (strpos($decoded, '&amp;') !== false)
        {
            $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $decoded;
    }

    private function serializeAs($value, array $serializeAsAnnotation): mixed
    {
        $type = $serializeAsAnnotation['type'];
        $of = $serializeAsAnnotation['of'];
        $useShort = $serializeAsAnnotation['short'] ?? false;

        if ($type === 'object')
        {
            return $this->serializeAsObject($value, $of, $useShort);
        } elseif ($type === 'array')
        {
            return $this->serializeAsArray($value, $of, $useShort);
        }

        return $value;
    }

    private function serializeAsObject($value, string $className, bool $short): ?array
    {
        if ($value === null)
        {
            return null;
        }

        if (!is_object($value))
        {
            $value = $this->instantiate($className, $value);
        }

        if ($value === null)
        {
            return null;
        }
        if (method_exists($value, 'serialize'))
        {
            $serialized = $value->serialize([], $short);
            return !empty($serialized) ? $serialized : null;
        }

        return $value;
    }

    private function serializeAsArray($value, string $className, bool $short): ?array
    {
        if (!is_array($value) || empty($value))
        {
            return null;
        }

        $serialized = array_map(function ($item) use ($className, $short)
        {
            if ($item === null)
            {
                return null;
            }

            if (!is_object($item))
            {
                $item = $this->instantiate($className, $item);
            }

            if ($item === null)
            {
                return null;
            }

            if (method_exists($item, 'serialize'))
            {
                return $item->serialize([], $short);
            }

            return $item;
        }, $value);

        $serialized = array_filter($serialized, function ($item)
        {
            return $item !== null;
        });

        return !empty($serialized) ? $serialized : null;
    }


    private function instantiate(string $className, $data)
    {
        if (!class_exists($className))
        {
            throw new \RuntimeException("Class {$className} does not exist");
        }

        $reflection = new \ReflectionClass($className);

        if (empty($data))
        {
            return null;
        }

        if ($reflection->getConstructor() && $reflection->getConstructor()->getNumberOfParameters() > 0)
        {
            return $reflection->newInstance($data);
        } else
        {
            $instance = $reflection->newInstance();
            if (is_array($data))
            {
                foreach ($data as $key => $value)
                {
                    if (property_exists($instance, $key))
                    {
                        $instance->$key = $value;
                    }
                }
            }
            return $instance;
        }
    }

    private function getClassDefaultBehavior(\ReflectionClass $reflection): string
    {
        $className = $reflection->getName();
        if (!isset(self::$annotationCache[$className]['defaultBehavior']))
        {
            $docComment = $reflection->getDocComment();
            if (strpos($docComment, '@Expose') !== false)
            {
                self::$annotationCache[$className]['defaultBehavior'] = 'expose';
            } elseif (strpos($docComment, '@Exclude') !== false)
            {
                self::$annotationCache[$className]['defaultBehavior'] = 'exclude';
            } else
            {
                self::$annotationCache[$className]['defaultBehavior'] = 'expose'; // Default to expose if no annotation is present
            }
        }
        return self::$annotationCache[$className]['defaultBehavior'];
    }

    private function parseExposeAnnotation(\ReflectionProperty $property): ?array
    {
        $className = $property->getDeclaringClass()->getName();
        $propertyName = $property->getName();

        if (!isset(self::$annotationCache[$className]['properties'][$propertyName]['expose'])) {
            $docComment = $property->getDocComment();

            // Handle multi-line @Expose annotation
            if (preg_match('/@Expose\s*\(\s*when\s*=\s*\{([^}]+)\}\s*\)/s', $docComment, $matches)) {
                // Clean up the conditions string
                $conditionsStr = preg_replace('/\s*\*\s*/', '', $matches[1]);

                // Split conditions by comma, but preserve &&
                $conditions = array_map(
                    function($condition) {
                        return trim($condition, " \t\n\r\0\x0B\"'");
                    },
                    preg_split('/",\s*"/', $conditionsStr)
                );

                self::$annotationCache[$className]['properties'][$propertyName]['expose'] = [
                    'groups' => [],
                    'when' => array_filter($conditions) // Remove empty conditions
                ];
            } else if (strpos($docComment, '@Expose') !== false) {
                // Simple @Expose without conditions
                self::$annotationCache[$className]['properties'][$propertyName]['expose'] = [
                    'groups' => [],
                    'when' => []
                ];
            } else {
                self::$annotationCache[$className]['properties'][$propertyName]['expose'] = null;
            }
        }

        return self::$annotationCache[$className]['properties'][$propertyName]['expose'];
    }


    private function checkRoleAccess(?array $exposeAnnotation, array $context): bool
    {
        if (empty($exposeAnnotation['groups']))
        {
            return true;
        }

        $userRoles = $context['roles'] ?? [];
        return empty($userRoles) || array_intersect($exposeAnnotation['groups'], $userRoles);
    }

    private function parseExcludeAnnotation(\ReflectionProperty $property): bool
    {
        $className = $property->getDeclaringClass()->getName();
        $propertyName = $property->getName();

        if (!isset(self::$annotationCache[$className]['properties'][$propertyName]['exclude']))
        {
            $docComment = $property->getDocComment();
            self::$annotationCache[$className]['properties'][$propertyName]['exclude'] = strpos($docComment, '@Exclude') !== false;
        }
        return self::$annotationCache[$className]['properties'][$propertyName]['exclude'];
    }


    private function parseShortAnnotation(\ReflectionProperty $property): bool
    {
        $className = $property->getDeclaringClass()->getName();
        $propertyName = $property->getName();

        if (!isset(self::$annotationCache[$className]['properties'][$propertyName]['short']))
        {
            $docComment = $property->getDocComment();
            self::$annotationCache[$className]['properties'][$propertyName]['short'] = strpos($docComment, '@Short') !== false;
        }
        return self::$annotationCache[$className]['properties'][$propertyName]['short'];
    }

    private function parseSerializeAsAnnotation(\ReflectionProperty $property): ?array
    {
        $className = $property->getDeclaringClass()->getName();
        $propertyName = $property->getName();

        if (!isset(self::$annotationCache[$className]['properties'][$propertyName]['serializeAs']))
        {
            $docComment = $property->getDocComment();
            if (preg_match('/@SerializeAs\(type="(object|array)"(?:,\s*of="(.+?)")?(?:,\s*short=(true|false))?\)/', $docComment, $matches))
            {
                self::$annotationCache[$className]['properties'][$propertyName]['serializeAs'] = [
                    'type' => $matches[1],
                    'of' => $matches[2] ?? null,
                    'short' => isset($matches[3]) ? $matches[3] === 'true' : false
                ];
            } else
            {
                self::$annotationCache[$className]['properties'][$propertyName]['serializeAs'] = null;
            }
        }
        return self::$annotationCache[$className]['properties'][$propertyName]['serializeAs'];
    }

    /**
     * Parse @Timestamp annotation
     * 
     * Supports the following options:
     * - format: Output format (default: "c" for ISO 8601)
     * - sourceTimezone: The timezone the stored timestamp is in (default: "Europe/Oslo")
     * 
     * Example: @Timestamp(format="c", sourceTimezone="UTC")
     * 
     * @return array|null Returns format settings if annotation is present, null otherwise
     */
    private function parseTimestampAnnotation(\ReflectionProperty $property): ?array
    {
        $className = $property->getDeclaringClass()->getName();
        $propertyName = $property->getName();

        if (!isset(self::$annotationCache[$className]['properties'][$propertyName]['timestamp']))
        {
            $docComment = $property->getDocComment();
            if (preg_match('/@Timestamp(?:\((.*?)\))?/', $docComment, $matches))
            {
                $options = [];

                // Parse options if they exist
                if (!empty($matches[1]))
                {
                    preg_match_all('/(\w+)\s*=\s*"([^"]+)"/', $matches[1], $optionMatches, PREG_SET_ORDER);
                    foreach ($optionMatches as $match)
                    {
                        $optionName = trim($match[1]);
                        $optionValue = trim($match[2]);
                        $options[$optionName] = $optionValue;
                    }
                } else {
                    $options['format'] = 'c';
                }
                
                // Set default source timezone to Oslo if not specified
                if (!isset($options['sourceTimezone'])) {
                    $options['sourceTimezone'] = 'Europe/Oslo';
                }

                self::$annotationCache[$className]['properties'][$propertyName]['timestamp'] = $options;
            }
            else
            {
                self::$annotationCache[$className]['properties'][$propertyName]['timestamp'] = null;
            }
        }

        return self::$annotationCache[$className]['properties'][$propertyName]['timestamp'];
    }

    /**
     * Format a timestamp according to specified options
     */
    private function formatTimestamp($value, array $options = []): string
    {
        // Get format and source timezone from options
        $format = $options['format'] ?? 'c';
        $sourceTimezone = $options['sourceTimezone'] ?? 'Europe/Oslo';
        
        // Create timezone objects
        $sourceTz = new \DateTimeZone($sourceTimezone);
        $osloTz = new \DateTimeZone('Europe/Oslo');

        // Handle different timestamp formats
        if (is_numeric($value)) {
            // Unix timestamp - treat as being in the source timezone
            $date = new \DateTime('now', $sourceTz);
            $date->setTimestamp($value);
            
            // Convert to Oslo timezone for output
            $date->setTimezone($osloTz);
            return $date->format($format);
        }

        if ($value === 'now') {
            $date = new \DateTime('now', $osloTz);
            return $date->format($format);
        }

        try {
            // Create DateTime assuming the value is in the source timezone
            $date = new \DateTime($value, $sourceTz);
            
            // Convert to Oslo timezone for output
            $date->setTimezone($osloTz);
            return $date->format($format);
        } catch (\Exception $e) {
            // If parsing fails, return original value
            return $value;
        }
    }

    /**
     * Convert the object to an array using the serialize method
     * This is a convenience method that calls serialize() with default parameters
     * 
     * @param array $context Additional context for serialization
     * @param bool $short Whether to use short serialization (only properties marked with @Short)
     * @return array|null The serialized array representation of the object
     */
    public function toArray(array $context = [], bool $short = false): ?array
    {
        return $this->serialize($context, $short);
    }


}